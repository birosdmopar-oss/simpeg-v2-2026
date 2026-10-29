<?php

declare(strict_types=1);

namespace App\Libraries\Presensi\Laporan;

use App\Exceptions\ValidationException;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Kepegawaian\Kalkulasi\TanggalBisnis;
use App\Libraries\Kepegawaian\Struktur\StrukturService;
use App\Libraries\ListQuery;
use App\Libraries\MasterData\WebConfigService;
use Closure;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Daftar pegawai laporan Tukin (D-08, MAKE-041, PRD 5B §7.3.1) — port `list_lt` / `list_lt_il` (`Presensi.php:76-219`,
 * `L_presensi.php:3535-3627`, `L_employee::get_all_pegawai_dh` `:6310-6396`) legacy `0cf7e67`.
 *
 * - DH: pegawai `status = 1` dengan snapshot jabatan `pegawai_mutasi_jabatan` `jenis_jabatan = 1`; lingkup
 *   {@see CakupanLaporan} (role 3 dipaksa satkernya di **server**, legacy hanya JS — L5B-02); `jenis_pegawai[]` (kosong =
 *   semua [K]), `search` nama/NIP; urut `kelas_jabatan` turun, `gol_ruang` turun, TMT pangkat naik, `id_satker` naik, NIP
 *   [K `:6387-6392` + penentu akhir V2]; **paginasi server** [V2]. IL: snapshot `jenis_jabatan = 2`, tanpa lingkup & jenis.
 * - Item: `nip, nama, gol_ruang, pangkat, jabatan, eselon, unit, satker, pendidikan {strata, jurusan, institusi},
 *   jenis_pegawai, aksi {lihat_laporan}`; eselon dari `id_sub_group_jabatan` 1..4 → E.I..E.IV [K `getEselon`].
 * - `meta.bulan_tersedia` = 2024-04 (F5B-4) s.d. bulan berjalan, setelah tanggal 15 termasuk bulan depan
 *   [K `Presensi.php:90-106`]. Daftar NIP tester legacy tidak di-port (F5B-12).
 */
final class DaftarPegawaiTukinService
{
    private const ESELON = [1 => 'E.I', 2 => 'E.II', 3 => 'E.III', 4 => 'E.IV'];

    private readonly BaseConnection $db;

    /** @var Closure(): DateTimeInterface */
    private readonly Closure $jam;

    /**
     * @param (Closure(): DateTimeInterface)|null $jam
     */
    public function __construct(?BaseConnection $db = null, ?Closure $jam = null, private readonly ?WebConfigService $webConfig = null)
    {
        $this->db  = $db ?? db_connect();
        $this->jam = $jam ?? static fn (): DateTimeInterface => new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    /**
     * @param array<string, mixed> $query
     *
     * @return array<string, mixed>
     */
    public function daftar(AuthContext $auth, array $query, bool $il = false): array
    {
        $q       = ListQuery::from($query, ['unit_satker', 'search', 'page', 'per_page', 'bulan']);
        $lingkup = $il ? null : (new CakupanLaporan($this->db, $this->webConfig))->lingkup($auth, $query['unit_satker'] ?? null);
        $jenis   = $il ? [] : self::jenis($query, $this->db);

        $bangun = function () use ($q, $lingkup, $jenis, $il): BaseBuilder {
            $b = $this->db->table('pegawai p')
                ->join('pegawai_mutasi_jabatan pmj', 'pmj.nip = p.nip', 'inner')
                ->where('p.status', 1)
                ->where('pmj.jenis_jabatan', $il ? 2 : 1);

            if ($lingkup !== null && $lingkup->tipe !== LingkupLaporan::KEMENTERIAN) {
                $b->where($lingkup->tipe === LingkupLaporan::UNIT ? 'pmj.id_unit' : 'pmj.id_satker', $lingkup->id);
            }

            if ($jenis !== []) {
                $b->whereIn('p.id_jenis_pegawai', $jenis);
            }

            if ($q->filled('search')) {
                $like = ListQuery::likeLiteral($this->db, $q->string('search'));
                $b->groupStart()->like('p.nama', $like, 'both', null, true)->orLike('p.nip', $like, 'both', null, true)->groupEnd();
            }

            return $b;
        };

        $total   = $bangun()->countAllResults();
        $page    = $q->page();
        $perPage = $q->perPage(25, 100);
        $baris   = $bangun()
            ->select('p.nip, p.nama, p.glr_awal, p.glr_akhir, pk.gol_ruang, pk.pangkat, pmj.jabatan, pmj.id_sub_group_jabatan, pmj.unit, pmj.satker, pmj.gol_pppk, pp.jenjang_pendidikan_singkat, pp.jurusan_pendidikan, pp.jurusan_pendidikan_lain, pp.institusi_pendidikan, jp.jenis_pegawai, p.id_jenis_pegawai')
            ->join('pegawai_kp pk', 'pk.nip = p.nip', 'left')
            ->join('pegawai_pendidikan pp', 'pp.nip = p.nip', 'left')
            ->join('jabatan jab', 'jab.id_jabatan = pmj.id_jabatan', 'left')
            ->join('jenis_pegawai jp', 'jp.id_jenis_pegawai = p.id_jenis_pegawai', 'left')
            ->orderBy('jab.kelas_jabatan', 'DESC')->orderBy('pk.gol_ruang', 'DESC')->orderBy('pk.tmtsk', 'ASC')->orderBy('pmj.id_satker', 'ASC')->orderBy('p.nip', 'ASC')
            ->limit($perPage, ($page - 1) * $perPage)->get()->getResultArray();

        return [
            'items'          => array_map(static fn (array $b): array => self::item($b), $baris),
            'total'          => $total,
            'page'           => $page,
            'per_page'       => $perPage,
            'lingkup'        => $lingkup?->toArray(),
            'bulan_tersedia' => $this->bulanTersedia(),
        ];
    }

    /**
     * @return list<array{bulan: string, label: string}>
     */
    public function bulanTersedia(): array
    {
        $hariIni = TanggalBisnis::hariIni(($this->jam)());
        $akhir   = (int) substr($hariIni, 8, 2) > 15 ? substr(TanggalBisnis::tambahBulan(substr($hariIni, 0, 7) . '-01', 1), 0, 7) : substr($hariIni, 0, 7);
        $hasil   = [];

        for ($b = RekapTukinService::BULAN_MINIMUM . '-01'; substr($b, 0, 7) <= $akhir; $b = TanggalBisnis::tambahBulan($b, 1)) {
            $hasil[] = ['bulan' => substr($b, 0, 7), 'label' => TanggalBisnis::NAMA_BULAN[(int) substr($b, 5, 2)] . ' ' . substr($b, 0, 4)];
        }

        return $hasil;
    }

    /**
     * `jenis_pegawai[]` opsional (kosong = semua [K]); id wajib ada di master (422).
     *
     * @param array<string, mixed> $query
     *
     * @return list<int>
     */
    private static function jenis(array $query, BaseConnection $db): array
    {
        if (! array_key_exists('jenis_pegawai', $query)) {
            return [];
        }

        $daftar = is_array($query['jenis_pegawai']) ? array_values($query['jenis_pegawai']) : [$query['jenis_pegawai']];
        $jenis  = [];

        foreach ($daftar as $j) {
            if (! is_scalar($j) || preg_match('/^[1-9]\d{0,3}$/', (string) $j) !== 1) {
                throw ValidationException::forField('jenis_pegawai', 'Jenis pegawai tidak valid.');
            }

            $jenis[] = (int) $j;
        }

        $jenis = array_values(array_unique($jenis));

        if ($jenis !== [] && $db->table('jenis_pegawai')->whereIn('id_jenis_pegawai', $jenis)->countAllResults() !== count($jenis)) {
            throw ValidationException::forField('jenis_pegawai', 'Jenis pegawai tidak ditemukan.');
        }

        return $jenis;
    }

    /**
     * @param array<string, mixed> $b
     *
     * @return array<string, mixed>
     */
    private static function item(array $b): array
    {
        $jurusan = (string) ($b['jurusan_pendidikan'] ?? '');

        return [
            'nip'        => (string) $b['nip'],
            'nama'       => StrukturService::namaGelar($b['glr_awal'] ?? null, (string) ($b['nama'] ?? ''), $b['glr_akhir'] ?? null),
            'gol_ruang'  => (int) ($b['id_jenis_pegawai'] ?? 0) === 6 && (string) ($b['gol_pppk'] ?? '') !== '' ? (string) $b['gol_pppk'] : (string) ($b['gol_ruang'] ?? ''),
            'pangkat'    => (string) ($b['pangkat'] ?? ''),
            'jabatan'    => (string) ($b['jabatan'] ?? ''),
            'eselon'     => self::ESELON[(int) ($b['id_sub_group_jabatan'] ?? 0)] ?? '',
            'unit'       => (string) ($b['unit'] ?? ''),
            'satker'     => (string) ($b['satker'] ?? ''),
            'pendidikan' => [
                'strata'    => (string) ($b['jenjang_pendidikan_singkat'] ?? ''),
                'jurusan'   => mb_strtoupper($jurusan) === 'LAIN-LAIN' ? (string) ($b['jurusan_pendidikan_lain'] ?? '') : $jurusan,
                'institusi' => (string) ($b['institusi_pendidikan'] ?? ''),
            ],
            'jenis_pegawai' => (string) ($b['jenis_pegawai'] ?? ''),
            'aksi'          => ['lihat_laporan' => true],
        ];
    }
}
