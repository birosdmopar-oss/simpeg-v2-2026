<?php

declare(strict_types=1);

namespace App\Libraries\Presensi\Laporan;

use App\Constants\Role;
use App\Exceptions\ApiException;
use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Kepegawaian\Struktur\StrukturService;
use App\Libraries\ListQuery;
use App\Libraries\Presensi\Slip\KodeGalatSlip;
use App\Models\Presensi\PenyesuaianTukinModel;
use Closure;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Exceptions\DatabaseException;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use RuntimeException;
use Throwable;

/**
 * CRUD penyesuaian Tukin per NIP (PRD 5B §6.1, §7.3.10, F5B-14; tabel DBV-024) — pengganti daftar NIP & periode yang
 * di-hard-code legacy (`L_presensi.php:11755-11806`). Hanya role 1 (route + service). Efek ke laporan/rekap = M5
 * (`Rekap\PenyesuaianTukin`); `total_potongan` tidak pernah diubah.
 *
 * - Validasi 422 per field: `nip` ada di `pegawai`; `bulan_mulai`/`bulan_akhir` `YYYY-MM` ≥ 2024-04 (F5B-4), mulai ≤
 *   akhir; `persen` desimal maks 2 angka di belakang koma, ≠ 0, −100..100; `alasan` wajib ≤ 255 karakter; `status` 1/2
 *   (bawaan 1; 10 hanya lewat DELETE). NIP baris tidak bisa diubah [V2] (hapus lalu tambah baru).
 * - **Tumpang tindih (syarat DBV C-1):** baris berstatus 1 tidak boleh beririsan rentang dengan baris status 1 lain milik
 *   NIP yang sama. Kunci `SELECT … FROM pegawai WHERE nip = ? FOR UPDATE` diambil **sebelum** cek rentang; cek + tulis
 *   dalam satu transaksi, sehingga permintaan bersamaan untuk NIP yang sama berjalan berurutan (tabel tidak bisa
 *   menjaganya dengan UNIQUE). Kunci gagal karena permintaan lain (1205/1213) → 409 `konflik_data`.
 * - Audit lewat {@see PenyesuaianTukinModel} (`create`/`update`, hapus = status 10 event `delete`).
 */
final class PenyesuaianTukinService
{
    public const BULAN_MINIMUM = '2024-04';

    public const PESAN_TUMPANG_TINDIH = 'Rentang bulan beririsan dengan penyesuaian aktif lain milik pegawai ini.';

    public const PESAN_KONFLIK = 'Data sedang diubah oleh permintaan lain, coba lagi.';

    /** Galat kunci karena permintaan lain: 1205 lock wait timeout, 1213 deadlock. */
    private const GALAT_KUNCI = [1205, 1213];

    private readonly BaseConnection $db;

    /** @var Closure(): DateTimeInterface */
    private readonly Closure $jam;

    /**
     * @param (Closure(): DateTimeInterface)|null $jam              instan sekarang (bawaan jam server); disuntik test
     * @param (Closure(string): void)|null        $kaitSetelahKunci seam test konkurensi (C-1): dipanggil dengan NIP tepat
     *                                                               setelah kunci baris pegawai diambil, sebelum cek rentang
     */
    public function __construct(?BaseConnection $db = null, ?Closure $jam = null, private readonly ?Closure $kaitSetelahKunci = null)
    {
        $this->db  = $db ?? db_connect();
        $this->jam = $jam ?? static fn (): DateTimeInterface => new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    /**
     * `GET presensi/tukin/penyesuaian` — filter `nip`, `bulan` (baris yang mencakup bulan itu), `status` (bawaan 1 dan 2).
     *
     * @param array<string, mixed> $query
     *
     * @return array{items: list<array<string, mixed>>, total: int, page: int, per_page: int}
     */
    public function daftar(AuthContext $auth, array $query): array
    {
        $this->wajibRole($auth);

        $q      = ListQuery::from($query, ['nip', 'bulan', 'status', 'page', 'per_page']);
        $bulan  = $q->filled('bulan') ? $this->bulan($q->string('bulan'), 'bulan', false) : null;
        $status = null;

        if ($q->filled('status')) {
            $status = in_array($q->string('status'), ['1', '2', '10'], true)
                ? (int) $q->string('status')
                : throw ValidationException::forField('status', 'Status harus 1, 2, atau 10.');
        }

        $bangun = function () use ($q, $bulan, $status): BaseBuilder {
            $b = $this->db->table('penyesuaian_tukin pt')->join('pegawai p', 'p.nip = pt.nip', 'inner');

            if ($q->filled('nip')) {
                $b->where('pt.nip', $q->string('nip'));
            }

            if ($bulan !== null) {
                $b->where('pt.bulan_mulai <=', $bulan)->where('pt.bulan_akhir >=', $bulan);
            }

            $status === null
                ? $b->whereIn('pt.status', [PenyesuaianTukinModel::STATUS_AKTIF, PenyesuaianTukinModel::STATUS_TIDAK_AKTIF])
                : $b->where('pt.status', $status);

            return $b;
        };

        $total   = $bangun()->countAllResults();
        $page    = $q->page();
        $perPage = $q->perPage(25, 100);
        $items   = $bangun()
            ->select('pt.*, p.nama, p.glr_awal, p.glr_akhir')
            ->orderBy('pt.nip', 'ASC')->orderBy('pt.bulan_mulai', 'DESC')->orderBy('pt.id_penyesuaian_tukin', 'DESC')
            ->limit($perPage, ($page - 1) * $perPage)->get()->getResultArray();

        return ['items' => array_map(fn (array $b): array => $this->bentuk($b), $items), 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }

    /**
     * `POST presensi/tukin/penyesuaian` (201).
     *
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    public function tambah(AuthContext $auth, array $body): array
    {
        $this->wajibRole($auth);

        $errors = [];
        $nip    = is_string($body['nip'] ?? null) ? trim($body['nip']) : '';

        if ($nip === '') {
            $errors['nip'][] = 'NIP wajib diisi.';
        } elseif ($this->db->table('pegawai')->where('nip', $nip)->countAllResults() === 0) {
            $errors['nip'][] = 'Pegawai tidak ditemukan.';
        }

        $isi = $this->isian($body, $errors);
        $this->lemparBilaGalat($errors);

        $sekarang = $this->sekarang();
        $baris    = $isi + ['nip' => $nip, 'created_at' => $sekarang, 'updated_at' => $sekarang, 'created_by' => $auth->idPengguna(), 'updated_by' => $auth->idPengguna()];

        $id = $this->transaksi(function () use ($nip, $baris): int {
            $this->kunciNip($nip);
            $this->tolakTumpangTindih($nip, $baris, null);

            $id = $this->model()->insert($baris);

            if ($id === false) {
                throw new RuntimeException('Tulis penyesuaian_tukin gagal.');
            }

            return (int) $id;
        });

        return $this->ambil($id);
    }

    /**
     * `PUT presensi/tukin/penyesuaian/{id}` — ganti `bulan_mulai`, `bulan_akhir`, `persen`, `alasan`, `status` (1/2).
     *
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    public function ubah(AuthContext $auth, int $id, array $body): array
    {
        $this->wajibRole($auth);

        $lama   = $this->barisAda($id);
        $errors = [];

        if (array_key_exists('nip', $body) && (! is_string($body['nip']) || trim($body['nip']) !== $lama['nip'])) {
            $errors['nip'][] = 'NIP tidak dapat diubah. Hapus data ini lalu tambahkan data baru.';
        }

        $isi = $this->isian($body, $errors);
        $this->lemparBilaGalat($errors);

        $ubah = $isi + ['updated_at' => $this->sekarang(), 'updated_by' => $auth->idPengguna()];

        $this->transaksi(function () use ($id, $lama, $ubah): int {
            $this->kunciNip((string) $lama['nip']);
            $this->barisAda($id);
            $this->tolakTumpangTindih((string) $lama['nip'], $ubah, $id);

            if ($this->model()->update($id, $ubah) === false) {
                throw new RuntimeException('Ubah penyesuaian_tukin gagal.');
            }

            return $id;
        });

        return $this->ambil($id);
    }

    /**
     * `DELETE presensi/tukin/penyesuaian/{id}` — status 10 (pola master), audit `delete`.
     *
     * @return array{deleted: bool, id: int}
     */
    public function hapus(AuthContext $auth, int $id): array
    {
        $this->wajibRole($auth);

        $lama = $this->barisAda($id);

        $this->transaksi(function () use ($auth, $id, $lama): int {
            $this->kunciNip((string) $lama['nip']);
            $this->barisAda($id);
            $this->model()->hapusStatus($id, $auth->idPengguna(), $this->sekarang());

            return $id;
        });

        return ['deleted' => true, 'id' => $id];
    }

    /**
     * Baris yang belum dihapus (status 1/2) dalam bentuk respons.
     *
     * @return array<string, mixed>
     */
    public function ambil(int $id): array
    {
        $b = $this->db->table('penyesuaian_tukin pt')->join('pegawai p', 'p.nip = pt.nip', 'inner')
            ->select('pt.*, p.nama, p.glr_awal, p.glr_akhir')->where('pt.id_penyesuaian_tukin', $id)->get()->getRowArray();

        if (! is_array($b) || (int) $b['status'] === PenyesuaianTukinModel::STATUS_DIHAPUS) {
            throw new NotFoundException('Data penyesuaian Tukin tidak ditemukan.');
        }

        return $this->bentuk($b);
    }

    private function wajibRole(AuthContext $auth): void
    {
        if ($auth->role() !== Role::SUPER_ADMIN) {
            throw new ForbiddenException('Hanya Super Admin yang dapat mengelola penyesuaian Tukin.');
        }
    }

    /**
     * Isian bersama tambah & ubah (whitelist).
     *
     * @param array<string, mixed>               $body
     * @param array<string, list<string>>        $errors
     *
     * @return array{bulan_mulai?: string, bulan_akhir?: string, persen?: string, alasan?: string, status?: int}
     */
    private function isian(array $body, array &$errors): array
    {
        $isi   = [];
        $mulai = $this->bulanBody($body, 'bulan_mulai', $errors);
        $akhir = $this->bulanBody($body, 'bulan_akhir', $errors);

        if ($mulai !== null && $akhir !== null && $akhir < $mulai) {
            $errors['bulan_akhir'][] = 'Bulan akhir tidak boleh sebelum bulan mulai.';
        }

        if ($mulai !== null && $akhir !== null) {
            $isi['bulan_mulai'] = $mulai;
            $isi['bulan_akhir'] = $akhir;
        }

        $persen = self::persen($body['persen'] ?? null);

        if ($persen === null) {
            $errors['persen'][] = 'Persen wajib angka dengan paling banyak dua angka di belakang koma, tidak nol, antara −100 dan 100.';
        } else {
            $isi['persen'] = $persen;
        }

        $alasan = is_string($body['alasan'] ?? null) ? trim($body['alasan']) : '';

        if ($alasan === '') {
            $errors['alasan'][] = 'Alasan wajib diisi.';
        } elseif (mb_strlen($alasan) > 255) {
            $errors['alasan'][] = 'Alasan paling banyak 255 karakter.';
        } else {
            $isi['alasan'] = $alasan;
        }

        $status = $body['status'] ?? PenyesuaianTukinModel::STATUS_AKTIF;

        if (in_array((string) (is_scalar($status) ? $status : ''), ['1', '2'], true)) {
            $isi['status'] = (int) $status;
        } else {
            $errors['status'][] = 'Status harus 1 (aktif) atau 2 (tidak aktif).';
        }

        return $isi;
    }

    /**
     * @param array<string, mixed>        $body
     * @param array<string, list<string>> $errors
     */
    private function bulanBody(array $body, string $field, array &$errors): ?string
    {
        $nilai = $body[$field] ?? null;

        if (! is_string($nilai) || trim($nilai) === '') {
            $errors[$field][] = 'Bulan wajib diisi (YYYY-MM).';

            return null;
        }

        $pesan = self::pesanBulan(trim($nilai), true);

        if ($pesan !== null) {
            $errors[$field][] = $pesan;

            return null;
        }

        return trim($nilai) . '-01';
    }

    /**
     * `YYYY-MM` → `YYYY-MM-01`; `$minimum` = wajib ≥ {@see self::BULAN_MINIMUM} (F5B-4).
     */
    private function bulan(string $nilai, string $field, bool $minimum): string
    {
        $pesan = self::pesanBulan($nilai, $minimum);

        if ($pesan !== null) {
            throw ValidationException::forField($field, $pesan);
        }

        return $nilai . '-01';
    }

    private static function pesanBulan(string $nilai, bool $minimum): ?string
    {
        if (preg_match('/^(\d{4})-(\d{2})$/', $nilai, $m) !== 1 || (int) $m[2] < 1 || (int) $m[2] > 12) {
            return 'Bulan harus berformat YYYY-MM.';
        }

        return $minimum && $nilai < self::BULAN_MINIMUM ? 'Bulan paling awal 2024-04.' : null;
    }

    /**
     * Persen → string desimal 2 angka (`-20.00`), atau null bila tidak sah (bukan angka, > 2 desimal, 0, di luar ±100).
     */
    public static function persen(mixed $nilai): ?string
    {
        if (is_int($nilai)) {
            $teks = (string) $nilai;
        } elseif (is_float($nilai) && is_finite($nilai)) {
            $teks = rtrim(rtrim(sprintf('%.6F', $nilai), '0'), '.');
        } elseif (is_string($nilai)) {
            $teks = trim($nilai);
        } else {
            return null;
        }

        if (preg_match('/^(-?)(\d{1,3})(?:\.(\d{1,2}))?$/', $teks, $m) !== 1) {
            return null;
        }

        $sen = (int) $m[2] * 100 + (int) str_pad($m[3] ?? '', 2, '0');

        if ($sen === 0 || $sen > 10000) {
            return null;
        }

        return sprintf('%s%d.%02d', $m[1], intdiv($sen, 100), $sen % 100);
    }

    /**
     * Kunci baris pegawai (C-1): permintaan lain untuk NIP yang sama menunggu sampai transaksi ini selesai. Kunci gagal
     * karena permintaan lain (1205 lock wait timeout, 1213 deadlock) → 409 `konflik_data` (preseden impor Slip Gaji,
     * keputusan reviewer CR 28-10-2026); transaksi di-rollback pemanggil. Galat lain diteruskan; tidak pernah lanjut
     * tanpa kunci.
     */
    private function kunciNip(string $nip): void
    {
        try {
            $hasil = $this->db->query('SELECT nip FROM ' . $this->db->escapeIdentifiers($this->db->prefixTable('pegawai')) . ' WHERE nip = ? FOR UPDATE', [$nip]);
        } catch (DatabaseException $e) {
            $this->lemparBilaKonflik(self::kodeGalat($e));

            throw $e;
        }

        // Tanpa DBDebug, kueri gagal mengembalikan false.
        if ($hasil === false) {
            $galat = $this->db->error();
            $this->lemparBilaKonflik((int) $galat['code']);

            throw new DatabaseException('Kunci data pegawai gagal: ' . $galat['message'], (int) $galat['code']);
        }

        if ($this->kaitSetelahKunci !== null) {
            ($this->kaitSetelahKunci)($nip);
        }
    }

    private function lemparBilaKonflik(int $kode): void
    {
        if (in_array($kode, self::GALAT_KUNCI, true)) {
            throw new ApiException(self::PESAN_KONFLIK, 409, kode: KodeGalatSlip::KONFLIK_DATA);
        }
    }

    /**
     * Kode galat DB dari exception atau rantai sebelumnya (`mysqli_sql_exception`).
     */
    private static function kodeGalat(Throwable $e): int
    {
        for ($t = $e; $t !== null; $t = $t->getPrevious()) {
            if (in_array((int) $t->getCode(), self::GALAT_KUNCI, true)) {
                return (int) $t->getCode();
            }
        }

        return (int) $e->getCode();
    }

    /**
     * Hanya bila baris yang ditulis berstatus 1: tolak irisan dengan baris status 1 lain milik NIP yang sama.
     *
     * @param array<string, mixed> $baris
     */
    private function tolakTumpangTindih(string $nip, array $baris, ?int $kecuali): void
    {
        if ((int) ($baris['status'] ?? 0) !== PenyesuaianTukinModel::STATUS_AKTIF) {
            return;
        }

        $b = $this->db->table('penyesuaian_tukin')->select('id_penyesuaian_tukin, bulan_mulai, bulan_akhir')
            ->where('nip', $nip)->where('status', PenyesuaianTukinModel::STATUS_AKTIF)
            ->where('bulan_mulai <=', $baris['bulan_akhir'])->where('bulan_akhir >=', $baris['bulan_mulai']);

        if ($kecuali !== null) {
            $b->where('id_penyesuaian_tukin !=', $kecuali);
        }

        $bentrok = $b->orderBy('bulan_mulai', 'ASC')->get(1)->getRowArray();

        if (is_array($bentrok)) {
            throw ValidationException::forField('bulan_mulai', sprintf(
                '%s (%s s.d. %s).',
                rtrim(self::PESAN_TUMPANG_TINDIH, '.'),
                substr((string) $bentrok['bulan_mulai'], 0, 7),
                substr((string) $bentrok['bulan_akhir'], 0, 7),
            ));
        }
    }

    /**
     * Baris status 1/2; tidak ada atau sudah dihapus → 404.
     *
     * @return array<string, mixed>
     */
    private function barisAda(int $id): array
    {
        $b = $this->db->table('penyesuaian_tukin')->where('id_penyesuaian_tukin', $id)->get()->getRowArray();

        if (! is_array($b) || (int) $b['status'] === PenyesuaianTukinModel::STATUS_DIHAPUS) {
            throw new NotFoundException('Data penyesuaian Tukin tidak ditemukan.');
        }

        return $b;
    }

    /**
     * @param array<string, mixed> $b
     *
     * @return array<string, mixed>
     */
    private function bentuk(array $b): array
    {
        return [
            'id'          => (int) $b['id_penyesuaian_tukin'],
            'nip'         => (string) $b['nip'],
            'nama'        => StrukturService::namaGelar($b['glr_awal'] ?? null, (string) ($b['nama'] ?? ''), $b['glr_akhir'] ?? null),
            'bulan_mulai' => substr((string) $b['bulan_mulai'], 0, 7),
            'bulan_akhir' => substr((string) $b['bulan_akhir'], 0, 7),
            'persen'      => (string) $b['persen'],
            'alasan'      => (string) $b['alasan'],
            'status'      => (int) $b['status'],
            'created_at'  => $b['created_at'],
            'updated_at'  => $b['updated_at'],
        ];
    }

    /**
     * @param array<string, list<string>> $errors
     */
    private function lemparBilaGalat(array $errors): void
    {
        if ($errors !== []) {
            throw new ValidationException('Validasi gagal.', $errors);
        }
    }

    /**
     * @param Closure(): int $kerja
     */
    private function transaksi(Closure $kerja): int
    {
        $this->db->transBegin();

        try {
            $hasil = $kerja();

            if ($this->db->transStatus() === false) {
                throw new RuntimeException('Transaksi penyesuaian Tukin gagal.');
            }

            $this->db->transCommit();

            return $hasil;
        } catch (Throwable $e) {
            $this->db->transRollback();
            $this->db->resetTransStatus();

            throw $e;
        }
    }

    private function model(): PenyesuaianTukinModel
    {
        return new PenyesuaianTukinModel($this->db);
    }

    private function sekarang(): string
    {
        return DateTimeImmutable::createFromInterface(($this->jam)())->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
