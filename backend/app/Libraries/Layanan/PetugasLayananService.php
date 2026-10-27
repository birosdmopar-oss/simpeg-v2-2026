<?php

declare(strict_types=1);

namespace App\Libraries\Layanan;

use App\Constants\Role;
use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Kepegawaian\Struktur\StrukturService;
use App\Libraries\ListQuery;
use App\Models\Layanan\JenisLayananModel;
use App\Models\Layanan\PetugasLayananModel;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;

/**
 * PIC layanan (`petugas_layanan`, PRD Fase 4 §3 "PIC", §6.10.2): approver TB/IB/PMK (D14, D16), ditunjuk role 1.
 * Dipakai bersama C-07/C-08/C-09 (M5) dan C-10 (M6, CRUD + dashboard). PIC = baris `status = 1` (aktif) untuk
 * `id_jenis_layanan` itu; tanpa batas unit (D14 [V2], perbaikan TB-01). Anomali Karpeg/Kariskarsu tanpa filter status
 * tidak disentuh di Fase 4 (§6.10.2).
 *
 * CRUD (M6, legacy `Layanan.php:77-222`, `L_layanan.php`): lihat role 1–7 (role 1 semua status, role lain hanya PIC
 * aktif); tulis role 1 saja. Kandidat = pegawai `status = 1`, PNS (`id_jenis_pegawai = 1`), `pmj.id_satker` = satker
 * kepegawaian (`WebConfigService::value('id_satker_kepegawaian')`, Q-06(a)) [K `L_layanan.php:38-46`]; jenis layanan
 * wajib aktif (perbaikan opsi nonaktif legacy). Duplikat `(id_jenis_layanan, nip)` → 422 `errors.nip`. Hapus fisik +
 * audit [K `L_layanan.php:166`].
 */
class PetugasLayananService
{
    /** `jenis_layanan` (seed DBV-022): 3 Tugas Belajar, 4 Izin Belajar, 5 Peninjauan Masa Kerja. */
    public const JENIS_TB = 3;

    public const JENIS_IB = 4;

    public const JENIS_PMK = 5;

    /** `petugas_layanan.status` aktif [K] (domain DBV-022: 0 Tidak Aktif, 1 Aktif, 10 dihapus). */
    public const STATUS_AKTIF = 1;

    public const STATUS_NONAKTIF = 0;

    /** Status yang boleh ditulis lewat API (10 tidak dipakai: hapus fisik [K]). */
    public const STATUS_TULIS = [self::STATUS_NONAKTIF, self::STATUS_AKTIF];

    public const LABEL_STATUS = [self::STATUS_NONAKTIF => 'Tidak Aktif', self::STATUS_AKTIF => 'Aktif'];

    /** Role route `layanan/petugas` dan `layanan/jenis` (lihat); tulis role 1 di service. */
    public const ROLE_ROUTE = [Role::SUPER_ADMIN, Role::PEGAWAI, Role::ADMIN_SATKER, Role::ADMIN_VIEW_ESELON1, Role::MENTERI, Role::PTT, Role::PPPK];

    public const PESAN_DUPLIKAT = 'Petugas sudah terdaftar untuk layanan ini.';

    /** Batas hasil & panjang kata kunci `GET layanan/petugas/kandidat` (pencarian di server). */
    public const KANDIDAT_MAKS = 50;

    public const KANDIDAT_SEARCH_MAKS = 100;

    public const PERINGATAN_SATKER_KOSONG = 'Satker kepegawaian belum diatur di Web Config; PIC belum dapat ditunjuk.';

    private const JENIS_PEGAWAI_PNS = 1;

    private const STATUS_DIHAPUS = 10;

    public function __construct(private readonly ?BaseConnection $db = null)
    {
    }

    public function adalahPic(?string $nip, int $idJenisLayanan): bool
    {
        if ($nip === null || $nip === '') {
            return false;
        }

        return $this->db()->table('petugas_layanan')
            ->where('id_jenis_layanan', $idJenisLayanan)
            ->where('nip', $nip)
            ->where('status', self::STATUS_AKTIF)
            ->countAllResults() > 0;
    }

    /**
     * NIP semua PIC aktif satu jenis layanan (penerima notifikasi pengajuan baru, §6.6.2), urut NIP.
     *
     * @return list<string>
     */
    public function nipPicAktif(int $idJenisLayanan): array
    {
        $rows = $this->db()->table('petugas_layanan')->select('nip')
            ->where('id_jenis_layanan', $idJenisLayanan)
            ->where('status', self::STATUS_AKTIF)
            ->orderBy('nip')->get()->getResultArray();

        return array_values(array_unique(array_map(static fn (array $r): string => (string) $r['nip'], $rows)));
    }

    // ------------------------------------------------------------------------------------------------------------
    // CRUD (C-10)

    /**
     * Master `jenis_layanan` aktif (`GET layanan/jenis`).
     *
     * @return list<array{id_jenis_layanan: int, jenis_layanan: string, module_name: string}>
     */
    public function jenisAktif(AuthContext $auth): array
    {
        $this->wajibRoleRoute($auth);

        return (new JenisLayananModel($this->db()))->aktif();
    }

    /**
     * @param array<string, mixed> $query
     *
     * @return array<string, mixed>
     */
    public function daftar(AuthContext $auth, array $query): array
    {
        $this->wajibRoleRoute($auth);

        $q     = ListQuery::from($query, ['id_jenis_layanan', 'status', 'search', 'page', 'per_page']);
        $admin = (int) $auth->role() === Role::SUPER_ADMIN;

        foreach (['id_jenis_layanan', 'status'] as $k) {
            if ($q->filled($k) && ! ctype_digit($q->string($k))) {
                throw new ValidationException(ListQuery::INVALID_MESSAGE, [$k => ['Harus berupa angka.']]);
            }
        }

        $bangun = function () use ($q, $admin): BaseBuilder {
            $b = $this->db()->table('petugas_layanan pl')->where('pl.status !=', self::STATUS_DIHAPUS);

            if (! $admin) {
                $b->where('pl.status', self::STATUS_AKTIF);
            } elseif ($q->filled('status')) {
                $b->where('pl.status', (int) $q->string('status'));
            }

            if ($q->filled('id_jenis_layanan')) {
                $b->where('pl.id_jenis_layanan', (int) $q->string('id_jenis_layanan'));
            }

            if ($q->filled('search')) {
                $like = ListQuery::likeLiteral($this->db(), $q->string('search'));
                $b->join('pegawai sp', 'sp.nip = pl.nip', 'left')
                    ->groupStart()->like('sp.nama', $like, 'both', null, true)->orLike('pl.nip', $like, 'both', null, true)->groupEnd();
            }

            return $b;
        };

        $total   = $bangun()->countAllResults();
        $page    = $q->page();
        $perPage = $q->perPage(20, 100);
        $baris   = $this->pilihKolom($bangun())->orderBy('pl.status', 'DESC')->orderBy('pl.id_petugas_layanan', 'DESC')
            ->limit($perPage, ($page - 1) * $perPage)->get()->getResultArray();

        return [
            'items'      => array_map(fn (array $r): array => $this->item($auth, $r), $baris),
            'total'      => $total,
            'page'       => $page,
            'per_page'   => $perPage,
            'can_create' => $admin,
        ];
    }

    /**
     * @param array<string, mixed> $in
     *
     * @return array<string, mixed>
     */
    public function tambah(AuthContext $auth, array $in): array
    {
        $this->wajibAdmin($auth);

        $data  = $this->validasi($in, null);
        $model = new PetugasLayananModel($this->db());
        $id    = $model->withActor($auth, static fn () => $model->insert($data));

        return $this->ambil($auth, (int) $id);
    }

    /**
     * @param array<string, mixed> $in
     *
     * @return array<string, mixed>
     */
    public function ubah(AuthContext $auth, int $id, array $in): array
    {
        $this->wajibAdmin($auth);

        $lama  = $this->baris($id);
        $data  = $this->validasi($in, $lama);
        $model = new PetugasLayananModel($this->db());
        $model->withActor($auth, static fn () => $model->update($id, $data));

        return $this->ambil($auth, $id);
    }

    /**
     * @return array{deleted: bool, soft_delete: bool}
     */
    public function hapus(AuthContext $auth, int $id): array
    {
        $this->wajibAdmin($auth);
        $this->baris($id);

        $model = new PetugasLayananModel($this->db());
        $model->withActor($auth, static fn () => $model->delete($id));

        return ['deleted' => true, 'soft_delete' => false];
    }

    /**
     * Kandidat PIC (`GET layanan/petugas/kandidat`, role 1) — [V2] gap PRD §5.5; legacy memuat pilihan di form
     * (`L_layanan.php` `form_off`) [K]: pegawai `status = 1`, `id_jenis_pegawai = 1`, `pmj.id_satker` = satker kepegawaian,
     * urut nama, maksimal KANDIDAT_MAKS baris. Satker kosong → `items: []` + `peringatan`.
     *
     * @param array<string, mixed> $query
     *
     * @return array{items: list<array{nip: string, nama: string}>, peringatan: string|null}
     */
    public function kandidat(AuthContext $auth, array $query): array
    {
        $this->wajibAdmin($auth);

        $q = ListQuery::from($query, ['search']);

        if (mb_strlen($q->string('search')) > self::KANDIDAT_SEARCH_MAKS) {
            throw new ValidationException(ListQuery::INVALID_MESSAGE, ['search' => ['Kata kunci maksimal ' . self::KANDIDAT_SEARCH_MAKS . ' karakter.']]);
        }

        $satker = $this->satkerKepegawaian();

        if ($satker === null) {
            return ['items' => [], 'peringatan' => self::PERINGATAN_SATKER_KOSONG];
        }

        $b = $this->kueriKandidat($satker)->select('p.nip, p.glr_awal, p.nama, p.glr_akhir');

        if ($q->filled('search')) {
            $like = ListQuery::likeLiteral($this->db(), $q->string('search'));
            $b->groupStart()->like('p.nama', $like, 'both', null, true)->orLike('p.nip', $like, 'both', null, true)->groupEnd();
        }

        $rows = $b->orderBy('p.nama')->orderBy('p.nip')->limit(self::KANDIDAT_MAKS)->get()->getResultArray();

        return [
            'items'      => array_map(static fn (array $r): array => ['nip' => (string) $r['nip'], 'nama' => StrukturService::namaGelar($r['glr_awal'], (string) $r['nama'], $r['glr_akhir'])], $rows),
            'peringatan' => null,
        ];
    }

    // ------------------------------------------------------------------------------------------------------------

    /**
     * Validasi tambah ($lama null) / ubah (kolom yang tidak dikirim tetap nilai lama). Kandidat & jenis aktif hanya
     * diperiksa bila nilainya berubah, supaya PIC lama tetap bisa dinonaktifkan meski tidak lagi memenuhi syarat.
     *
     * @param array<string, mixed>      $in
     * @param array<string, mixed>|null $lama
     *
     * @return array{id_jenis_layanan: int, nip: string, status: int}
     */
    private function validasi(array $in, ?array $lama): array
    {
        $errors = [];
        $jenis  = $this->nilaiAngka($in, 'id_jenis_layanan', $lama === null ? null : (int) $lama['id_jenis_layanan']);
        $nip    = array_key_exists('nip', $in) ? (is_scalar($in['nip']) ? trim((string) $in['nip']) : '') : (string) ($lama['nip'] ?? '');
        $status = $this->nilaiAngka($in, 'status', $lama === null ? self::STATUS_AKTIF : (int) $lama['status']);

        if ($jenis === null) {
            $errors['id_jenis_layanan'] = 'Silahkan pilih layanan.';
        } elseif (($lama === null || $jenis !== (int) $lama['id_jenis_layanan'])
            && $this->db()->table('jenis_layanan')->where('id_jenis_layanan', $jenis)->where('status', JenisLayananModel::STATUS_AKTIF)->countAllResults() === 0) {
            $errors['id_jenis_layanan'] = 'Layanan tidak ditemukan atau tidak aktif.';
        }

        if ($nip === '') {
            $errors['nip'] = 'Silahkan pilih petugas.';
        } elseif ($lama === null || $nip !== (string) $lama['nip']) {
            $galat = $this->galatKandidat($nip);

            if ($galat !== null) {
                $errors['nip'] = $galat;
            }
        }

        if ($status === null || ! in_array($status, self::STATUS_TULIS, true)) {
            $errors['status'] = 'Status harus 1 (Aktif) atau 0 (Tidak Aktif).';
        }

        if (! isset($errors['nip']) && ! isset($errors['id_jenis_layanan']) && $jenis !== null) {
            $dup = $this->db()->table('petugas_layanan')->where('id_jenis_layanan', $jenis)->where('nip', $nip);

            if ($lama !== null) {
                $dup->where('id_petugas_layanan !=', (int) $lama['id_petugas_layanan']);
            }

            if ($dup->countAllResults() > 0) {
                $errors['nip'] = self::PESAN_DUPLIKAT;
            }
        }

        if ($errors !== []) {
            throw new ValidationException('Validasi gagal.', array_map(static fn (string $p): array => [$p], $errors));
        }

        return ['id_jenis_layanan' => (int) $jenis, 'nip' => $nip, 'status' => (int) $status];
    }

    private function galatKandidat(string $nip): ?string
    {
        $satker = $this->satkerKepegawaian();

        if ($satker === null) {
            return self::PERINGATAN_SATKER_KOSONG;
        }

        return $this->kueriKandidat($satker)->where('p.nip', $nip)->countAllResults() > 0 ? null : 'Petugas harus PNS aktif di satker kepegawaian.';
    }

    /**
     * Aturan kandidat PIC [K `L_layanan.php:38-46`] — satu sumber untuk daftar kandidat dan validasi tulis.
     */
    private function kueriKandidat(int $satker): BaseBuilder
    {
        return $this->db()->table('pegawai p')
            ->join('pegawai_mutasi_jabatan pmj', 'pmj.nip = p.nip', 'inner')
            ->where('p.status', 1)->where('p.id_jenis_pegawai', self::JENIS_PEGAWAI_PNS)
            ->where('pmj.id_satker', $satker);
    }

    private function satkerKepegawaian(): ?int
    {
        $satker = service('webConfigService')->value('id_satker_kepegawaian');

        return is_scalar($satker) && ctype_digit((string) $satker) ? (int) $satker : null;
    }

    /**
     * @param array<string, mixed> $in
     */
    private function nilaiAngka(array $in, string $key, ?int $bawaan): ?int
    {
        if (! array_key_exists($key, $in)) {
            return $bawaan;
        }

        $v = $in[$key];

        return is_int($v) || (is_string($v) && ctype_digit(trim($v))) ? (int) $v : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function baris(int $id): array
    {
        $row = $this->db()->table('petugas_layanan')->where('id_petugas_layanan', $id)->where('status !=', self::STATUS_DIHAPUS)->get()->getRowArray();

        return is_array($row) ? $row : throw new NotFoundException();
    }

    /**
     * @return array<string, mixed>
     */
    private function ambil(AuthContext $auth, int $id): array
    {
        $row = $this->pilihKolom($this->db()->table('petugas_layanan pl')->where('pl.id_petugas_layanan', $id))->get()->getRowArray();

        return is_array($row) ? $this->item($auth, $row) : throw new NotFoundException();
    }

    private function pilihKolom(BaseBuilder $b): BaseBuilder
    {
        return $b->select('pl.id_petugas_layanan, pl.id_jenis_layanan, pl.nip, pl.status, pl.created_at, pl.updated_at, jl.jenis_layanan, '
            . 'p.glr_awal, p.nama, p.glr_akhir, pmj.jabatan, pmj.satker, pmj.unit')
            ->join('jenis_layanan jl', 'jl.id_jenis_layanan = pl.id_jenis_layanan', 'left')
            ->join('pegawai p', 'p.nip = pl.nip', 'left')
            ->join('pegawai_mutasi_jabatan pmj', 'pmj.nip = pl.nip', 'left');
    }

    /**
     * @param array<string, mixed> $r
     *
     * @return array<string, mixed>
     */
    private function item(AuthContext $auth, array $r): array
    {
        $admin  = (int) $auth->role() === Role::SUPER_ADMIN;
        $status = (int) $r['status'];

        return [
            'id_petugas_layanan' => (int) $r['id_petugas_layanan'],
            'id_jenis_layanan'   => (int) $r['id_jenis_layanan'],
            'jenis_layanan'      => $r['jenis_layanan'],
            'nip'                => (string) $r['nip'],
            'nama'               => $r['nama'] === null ? null : StrukturService::namaGelar($r['glr_awal'], (string) $r['nama'], $r['glr_akhir']),
            'jabatan'            => $r['jabatan'],
            'satker'             => $r['satker'],
            'unit'               => $r['unit'],
            'status'             => $status,
            'label_status'       => self::LABEL_STATUS[$status] ?? (string) $status,
            'created_at'         => $r['created_at'],
            'updated_at'         => $r['updated_at'],
            'aksi'               => [
                'ubah'        => $admin,
                'aktifkan'    => $admin && $status !== self::STATUS_AKTIF,
                'nonaktifkan' => $admin && $status === self::STATUS_AKTIF,
                'hapus'       => $admin,
            ],
        ];
    }

    private function wajibRoleRoute(AuthContext $auth): void
    {
        if (! in_array((int) $auth->role(), self::ROLE_ROUTE, true)) {
            throw new ForbiddenException();
        }
    }

    private function wajibAdmin(AuthContext $auth): void
    {
        if ((int) $auth->role() !== Role::SUPER_ADMIN) {
            throw new ForbiddenException('Hanya Super Admin yang dapat mengelola PIC layanan.');
        }
    }

    private function db(): BaseConnection
    {
        return $this->db ?? db_connect();
    }
}
