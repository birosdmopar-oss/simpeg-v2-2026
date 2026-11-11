<?php

declare(strict_types=1);

namespace App\Libraries\Dashboard\Interaksi\Survei;

use App\Exceptions\ApiException;
use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Kepegawaian\Kalkulasi\TanggalBisnis;
use App\Libraries\Waktu\StempelUtc;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Exceptions\DatabaseException;
use Throwable;

/**
 * F-11 Survei Kepuasan Layanan sisi pegawai (MAKE-084, PRD Fase 7 §5.8, role 2/6 Q7-23 lewat filter route):
 *
 * - {@see self::saya()} — padanan `core/MY_Controller.php:466-560`: baris riwayat milik aktor dengan `status = 1`
 *   (disetujui) dan `rated = 2` (belum dinilai), lima jenis urut 1→5 lalu id naik [K]; teks `info` mengikuti pola legacy.
 * - {@see self::kirim()} — padanan `controllers/hr/Announcement.php:650-720` dengan perbaikan [V2]:
 *   - riwayat dikunci `FOR UPDATE` dan wajib milik aktor (legacy tidak memeriksa: id tebakan bisa menilai milik orang lain)
 *     → 404 bila bukan miliknya / tidak ada; `status ≠ 1` → 422 `layanan_belum_selesai`; `rated = 0` → 422 `tidak_perlu_dinilai`;
 *   - INSERT `skl` + `rated = 1` dalam SATU transaksi;
 *   - ganda (sudah ada baris / `rated = 1` / UNIQUE 1062) → 200 `sudah_tercatat` dan `rated` dipastikan 1 (Q7-21 idempoten [K]);
 *   - `kritik_saran` = data pribadi: tidak dicatat di log/audit.
 */
final class SurveiLayananService
{
    public const KODE_BELUM_SELESAI = 'layanan_belum_selesai';

    public const KODE_TIDAK_PERLU = 'tidak_perlu_dinilai';

    public const MAKS_KRITIK = 2000;

    private const UNIQUE = 'uk_skl_nip_jenis_rwy_id_rwy';

    public function __construct(private readonly BaseConnection $db)
    {
    }

    /**
     * @return list<array{jenis_rwy: int, id_rwy: int, jenis_layanan: string, info: string}>
     */
    public function saya(AuthContext $auth): array
    {
        $nip   = $this->nip($auth);
        $label = JenisLayananSkl::label($this->db);
        $hasil = [];

        foreach (JenisLayananSkl::PETA as $kode => [$tabel, $pk]) {
            $b = $this->db->table($tabel . ' r')->where('r.nip', $nip)->where('r.status', 1)->where('r.rated', 2)->orderBy('r.' . $pk, 'ASC');

            match ($kode) {
                1       => $b->select("r.{$pk} AS id, r.tgl_mulai, r.tgl_akhir, jc.nama_jenis_cuti")->join('jenis_cuti jc', 'jc.id_jenis_cuti = r.id_jenis_cuti', 'left'),
                4, 5    => $b->select("r.{$pk} AS id, r.tgl_mulai, r.jenjang_pendidikan_singkat, r.institusi_pendidikan"),
                default => $b->select("r.{$pk} AS id, r.created_at"),
            };

            foreach ($b->get()->getResultArray() as $r) {
                $hasil[] = ['jenis_rwy' => $kode, 'id_rwy' => (int) $r['id'], 'jenis_layanan' => $label[$kode], 'info' => self::info($kode, $r)];
            }
        }

        return $hasil;
    }

    /**
     * @param array<string, mixed> $input `jenis_rwy` 1–5, `id_rwy`, `rate` 1–5, `kritik_saran` opsional ≤ 2000
     *
     * @return array{status: 'tercatat'|'sudah_tercatat', id: int|null}
     */
    public function kirim(AuthContext $auth, array $input): array
    {
        $nip                             = $this->nip($auth);
        [$jenis, $idRwy, $rate, $kritik] = self::validasi($input);
        [$tabel, $pk]                    = JenisLayananSkl::tabel($jenis);

        $this->db->transException(true)->transStart();

        try {
            $t   = $this->db->escapeIdentifiers($this->db->prefixTable($tabel));
            $row = $this->db->query(
                "SELECT status, rated FROM {$t} WHERE {$this->db->escapeIdentifiers($pk)} = ? AND nip = ? FOR UPDATE",
                [$idRwy, $nip],
            )->getRowArray();

            if (! is_array($row)) {
                throw new NotFoundException('Layanan tidak ditemukan.');
            }

            if ((int) $row['status'] !== 1) {
                throw new ApiException('Layanan belum selesai sehingga belum dapat dinilai.', 422, null, self::KODE_BELUM_SELESAI);
            }

            if ((int) $row['rated'] === 0) {
                throw new ApiException('Layanan ini tidak perlu dinilai.', 422, null, self::KODE_TIDAK_PERLU);
            }

            $ada = $this->db->table('skl')->select('id_skl')->where('nip', $nip)->where('jenis_rwy', $jenis)->where('id_rwy', $idRwy)->get()->getRowArray();

            if (is_array($ada) || (int) $row['rated'] === 1) {
                $this->tandaiDinilai($tabel, $pk, $idRwy);
                $this->db->transComplete();

                return ['status' => 'sudah_tercatat', 'id' => is_array($ada) ? (int) $ada['id_skl'] : null];
            }

            $this->db->table('skl')->insert([
                'nip'          => $nip,
                'jenis_rwy'    => $jenis,
                'id_rwy'       => $idRwy,
                'rate'         => $rate,
                'kritik_saran' => $kritik,
                'created_at'   => gmdate('Y-m-d H:i:s'),
                'created_by'   => $auth->idPengguna(),
            ]);
            $id = (int) $this->db->insertID();
            $this->tandaiDinilai($tabel, $pk, $idRwy);
            $this->db->transComplete();
        } catch (Throwable $e) {
            if ($this->db->transDepth > 0) {
                $this->db->transRollback();
            }

            $this->db->transException(false);

            if ($e instanceof DatabaseException && str_contains($e->getMessage(), self::UNIQUE)) {
                // Balapan dua kiriman: yang kalah tetap sukses tanpa baris ganda (Q7-21).
                $this->db->table($tabel)->where($pk, $idRwy)->where('nip', $nip)->where('rated', 2)->update(['rated' => 1]);

                return ['status' => 'sudah_tercatat', 'id' => null];
            }

            throw $e;
        }

        $this->db->transException(false);

        return ['status' => 'tercatat', 'id' => $id];
    }

    private function tandaiDinilai(string $tabel, string $pk, int $idRwy): void
    {
        $this->db->table($tabel)->where($pk, $idRwy)->update(['rated' => 1]);
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{0: int, 1: int, 2: int, 3: ?string}
     */
    private static function validasi(array $input): array
    {
        $galat = [];
        $jenis = 0;

        try {
            $jenis = JenisLayananSkl::kode($input['jenis_rwy'] ?? null);
        } catch (ValidationException $e) {
            $galat += (array) $e->getErrors();
        }

        $id = $input['id_rwy'] ?? null;

        if (is_string($id) && ctype_digit($id)) {
            $id = (int) $id;
        }

        if (! is_int($id) || $id < 1) {
            $galat['id_rwy'] = ['Layanan wajib dipilih.'];
        }

        $rate = $input['rate'] ?? null;

        if (is_string($rate) && ctype_digit($rate)) {
            $rate = (int) $rate;
        }

        if (! is_int($rate) || $rate < 1 || $rate > 5) {
            $galat['rate'] = ['Nilai wajib 1-5.'];
        }

        $kritik = $input['kritik_saran'] ?? null;

        if ($kritik !== null && (! is_string($kritik) || mb_strlen($kritik) > self::MAKS_KRITIK)) {
            $galat['kritik_saran'] = ['Kritik & saran maksimal ' . self::MAKS_KRITIK . ' karakter.'];
        }

        if ($galat !== []) {
            throw new ValidationException('Data penilaian tidak valid.', $galat);
        }

        $kritik = is_string($kritik) ? trim($kritik) : null;

        return [$jenis, (int) $id, (int) $rate, $kritik === '' ? null : $kritik];
    }

    /**
     * Teks info butir survei, pola legacy `MY_Controller.php:466-560` (tanggal Indonesia).
     *
     * @param array<string, mixed> $r
     */
    private static function info(int $kode, array $r): string
    {
        $tgl  = static fn (mixed $v): string => is_string($v) && TanggalBisnis::valid(substr($v, 0, 10)) ? TanggalBisnis::formatIndonesia(substr($v, 0, 10)) : '-';
        $wib  = static fn (mixed $v): string => is_string($v) ? $tgl(StempelUtc::tanggalWib($v)) : '-';
        $awal = 'Mohon berikan penilaian Anda atas layanan ';

        return match ($kode) {
            1       => $awal . 'permohonan ' . trim((string) ($r['nama_jenis_cuti'] ?? 'Cuti')) . ' pada tanggal ' . $tgl($r['tgl_mulai'] ?? null) . ' hingga ' . $tgl($r['tgl_akhir'] ?? null) . '.',
            2       => $awal . 'KARIS/KARSU pada tanggal ' . $wib($r['created_at'] ?? null) . '.',
            3       => $awal . 'KARPEG pada tanggal ' . $wib($r['created_at'] ?? null) . '.',
            4, 5    => $awal . ($kode === 4 ? 'Tugas Belajar ' : 'Izin Belajar ') . trim((string) ($r['jenjang_pendidikan_singkat'] ?? '')) . ' di ' . trim((string) ($r['institusi_pendidikan'] ?? '')) . ' pada tanggal ' . $tgl($r['tgl_mulai'] ?? null) . '.',
            default => $awal . 'kepegawaian.',
        };
    }

    private function nip(AuthContext $auth): string
    {
        $nip = $auth->nip();

        if ($nip === null || $nip === '') {
            throw new ForbiddenException('Akun tanpa NIP tidak dapat mengisi survei layanan.');
        }

        return $nip;
    }
}
