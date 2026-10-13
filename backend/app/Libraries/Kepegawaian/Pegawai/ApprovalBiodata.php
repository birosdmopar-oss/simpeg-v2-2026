<?php

declare(strict_types=1);

namespace App\Libraries\Kepegawaian\Pegawai;

use App\Constants\Role;
use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Interfaces\Kepegawaian\PegawaiScopeInterface;
use App\Interfaces\Kepegawaian\PegawaiScopeProsesInterface;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Kepegawaian\Lampiran\KolomBerkas;
use App\Libraries\ListQuery;
use App\Models\AuditLogModel;
use App\Models\Kepegawaian\PegawaiFotoModel;
use App\Models\Kepegawaian\PegawaiHistModel;
use App\Models\Kepegawaian\PegawaiModel;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Exceptions\DatabaseException;
use Throwable;

/**
 * B-04 Approval pengajuan biodata (MAKE-011), dipakai BiodataService. Ikut legacy `controllers/hr/Employee.php:414-478`
 * (`process`, role 1, 3) + `libraries/hr/L_employee.php:1044-1183` (`process`, `vp_process`, `sp_process`) dan antrean
 * `libraries/hr/L_notification.php` (`get_na_sa` / `get_na_ap_unit` / `get_na_ap_satker`, bagian "DATA UMUM").
 *
 * - Antrean (`pegawai_hist.flag_update` 1): role 1 semua, role 3 jalur PROSES PegawaiScope (CR-045: unit 20 tanpa UPT;
 *   unit 21 + satker BPO; satker admin) lewat PegawaiScopeProsesInterface.
 * - Setujui: kolom biodata pengajuan disalin ke `pegawai`, `flag_update` pegawai & pengajuan = 3, email akun disinkron
 *   (:1083-1118), foto baru masuk galeri `pegawai_foto` (pengganti trigger `pegawai_afUpd`).
 * - Tolak: alasan WAJIB (:1146-1169), pengajuan `flag_update` 2, `pegawai` TIDAK berubah kecuali `flag_update` 2.
 *   Bug legacy tidak ditiru: `sp_process` menyalin seluruh pengajuan ke `pegawai` juga saat ditolak (:1098).
 * - Penanda notifikasi seperti trigger `pegawai_hist_beUpd` (1 → 2/3: `show_notif` 1, `notif_date`), `approved_by`,
 *   `show_ua_upt` (role 3 bersatker) / `show_ua_deputi` (role 3 tanpa satker) / `show_ua_biro` (role 1) (:1095-1103).
 * - Pengajuan yang sudah diproses, atau `pegawai.flag_update` ≠ 1 (legacy :430-433) → 422 `errors.aksi`.
 *
 * Deviasi [V2]: yang disalin hanya kolom biodata (bukan `status`, `nip`, kolom SIASN/audit — legacy menyalin baris
 * pengajuan utuh termasuk `status` saat pengajuan dibuat); legacy `process` tidak memeriksa lingkup role 3 (hanya
 * antreannya yang disaring) — v2 memeriksa `bolehProses` (403). Semua dalam satu transaksi, pegawai `FOR UPDATE`.
 */
final class ApprovalBiodata
{
    /**
     * Kolom biodata yang dibandingkan dan disalin dari pengajuan ke `pegawai`.
     *
     * @var list<string>
     */
    public const KOLOM_BIODATA = [
        'nama', 'glr_awal', 'glr_akhir', 'tgl_lahir', 'id_provinsi_lahir', 'provinsi_lahir', 'provinsi_lahir_lain',
        'id_kabupaten_kota_lahir', 'kabupaten_kota_lahir', 'kabupaten_kota_lahir_lain', 'id_agama', 'agama',
        'jenis_kelamin', 'npwp', 'nik', 'bpjs_kes', 'bpjs_ket', 'no_taspen', 'no_hp', 'jenis_kerabat', 'no_telp_kerabat',
        'status_pernikahan', 'id_jenis_pegawai', 'jenis_pegawai', 'id_jenis_status', 'jenis_status', 'tmt_status',
        'nip_lama', 'foto', 'keterangan',
    ];

    /**
     * Role proses pengajuan biodata (Matriks `hr/employee/process/{id_hist}`).
     *
     * @var list<int>
     */
    public const ROLE_PROSES = [Role::SUPER_ADMIN, Role::ADMIN_SATKER];

    public const PER_PAGE_DEFAULT = 10;
    public const PER_PAGE_MAX     = 100;

    public function __construct(
        private readonly BaseConnection $db,
        private readonly PegawaiScopeInterface $scope,
    ) {
    }

    /**
     * @param array<string, mixed> $filter search, page, per_page
     *
     * @return array{items: list<array<string, mixed>>, total: int, page: int, per_page: int}
     */
    public function antrean(AuthContext $auth, array $filter): array
    {
        $this->wajibRoleProses($auth);

        $query   = ListQuery::from($filter, ['search', 'page', 'per_page']);
        $page    = $query->page();
        $perPage = $query->perPage(self::PER_PAGE_DEFAULT, self::PER_PAGE_MAX);

        $builder = $this->db->table('pegawai_hist')
            ->select('pegawai_hist.id_pegawai_hist, pegawai_hist.nip, pegawai_hist.nama, pegawai_hist.glr_awal, pegawai_hist.glr_akhir, pegawai_hist.created_at, pegawai_hist.updated_at')
            ->select('pegawai_mutasi_jabatan.unit, pegawai_mutasi_jabatan.satker, pegawai_mutasi_jabatan.jabatan')
            ->join('pegawai_mutasi_jabatan', 'pegawai_mutasi_jabatan.nip = pegawai_hist.nip', 'left')
            ->where('pegawai_hist.flag_update', PegawaiHistModel::FLAG_DIAJUKAN);

        if ($query->filled('search')) {
            $s = ListQuery::likeLiteral($this->db, $query->string('search'));
            $builder->groupStart()->like('pegawai_hist.nama', $s)->orLike('pegawai_hist.nip', $s)->groupEnd();
        }

        if ($this->scope instanceof PegawaiScopeProsesInterface) {
            $this->scope->terapkanKeQueryProses($builder, $auth, 'pegawai_hist.nip');
            $total = (clone $builder)->countAllResults();
            $rows  = $builder->orderBy('pegawai_hist.updated_at', 'DESC')->orderBy('pegawai_hist.id_pegawai_hist', 'DESC')
                ->limit($perPage, ($page - 1) * $perPage)->get()->getResultArray();
        } else {
            // Scope tanpa filter query jalur proses (fake test): saring per baris dengan bolehProses().
            $semua = array_values(array_filter(
                $builder->orderBy('pegawai_hist.updated_at', 'DESC')->orderBy('pegawai_hist.id_pegawai_hist', 'DESC')->get()->getResultArray(),
                fn (array $row): bool => $this->scope->bolehProses($auth, (string) $row['nip']),
            ));
            $total = count($semua);
            $rows  = array_slice($semua, ($page - 1) * $perPage, $perPage);
        }

        return [
            'items' => array_map(static function (array $row): array {
                $row['tgl_permohonan'] = $row['updated_at'] ?? $row['created_at'];

                return $row;
            }, $rows),
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
        ];
    }

    /**
     * Detail pengajuan + data pegawai sekarang + daftar kolom yang berubah (slot `diff` ApprovalDialog). Boleh dilihat
     * pemroses (`bolehProses`) dan pemilik pengajuan (UL_PEGAWAI atas NIP sendiri, legacy `detail_hist` :289-332).
     *
     * @return array<string, mixed>
     */
    public function detail(AuthContext $auth, int $id): array
    {
        $hist = $this->hist($id);
        $nip  = (string) $hist['nip'];

        if (! $this->bolehLihat($auth, $nip)) {
            throw new ForbiddenException('Anda tidak berhak melihat pengajuan ini.');
        }

        $pegawai = (array) $this->db->table('pegawai')->where('nip', $nip)->get()->getRowArray();
        $email   = $this->emailAkun($nip);

        return [
            'pengajuan'   => $this->tampil($hist),
            'pegawai'     => $this->tampil($pegawai) + ['email' => $email],
            'perubahan'   => $this->perubahan($hist, $pegawai, $email),
            'can_process' => (int) $hist['flag_update'] === PegawaiHistModel::FLAG_DIAJUKAN
                && in_array($auth->role(), self::ROLE_PROSES, true) && $this->scope->bolehProses($auth, $nip),
        ];
    }

    /**
     * Path foto pengajuan (untuk diunduh BiodataService); null bila tanpa foto.
     */
    public function pathFoto(AuthContext $auth, int $id): ?string
    {
        $hist = $this->hist($id);

        if (! $this->bolehLihat($auth, (string) $hist['nip'])) {
            throw new ForbiddenException('Anda tidak berhak melihat pengajuan ini.');
        }

        $path = (string) ($hist['foto'] ?? '');

        return $path === '' ? null : $path;
    }

    /**
     * @return array<string, mixed> detail pengajuan sesudah diproses
     */
    public function proses(AuthContext $auth, int $id, string $aksi, ?string $reasonNote): array
    {
        // Urutan: role (403) → pengajuan ada (404) → lingkup proses (403) → validasi (422).
        $this->wajibRoleProses($auth);

        $nip = (string) $this->hist($id)['nip'];

        if (! $this->scope->bolehProses($auth, $nip)) {
            throw new ForbiddenException('Anda tidak berhak memproses pengajuan pegawai ini.');
        }

        if (! in_array($aksi, ['setujui', 'tolak'], true)) {
            throw ValidationException::forField('aksi', 'Aksi harus setujui atau tolak.');
        }

        $alasan = $reasonNote === null ? '' : trim($reasonNote);

        if ($aksi === 'tolak' && $alasan === '') {
            throw ValidationException::forField('reason_note', 'Alasan penolakan wajib diisi.');
        }

        if (strlen($alasan) > 255) {
            throw ValidationException::forField('reason_note', 'Alasan maksimal 255 byte.');
        }

        $this->db->transBegin();

        try {
            $this->prosesTerkunci($auth, $id, $nip, $aksi, $alasan === '' ? null : $alasan);

            if ($this->db->transStatus() === false) {
                throw new \RuntimeException('Transaksi proses biodata gagal.');
            }

            $this->db->transCommit();
        } catch (Throwable $e) {
            $this->db->transRollback();

            throw $e;
        } finally {
            $this->db->resetTransStatus();
        }

        return $this->detail($auth, $id);
    }

    private function prosesTerkunci(AuthContext $auth, int $id, string $nip, string $aksi, ?string $alasan): void
    {
        $tPegawai = $this->db->escapeIdentifiers($this->db->prefixTable('pegawai'));
        $tHist    = $this->db->escapeIdentifiers($this->db->prefixTable('pegawai_hist'));
        $pegawai  = $this->db->query("SELECT * FROM {$tPegawai} WHERE nip = ? FOR UPDATE", [$nip])->getRowArray();
        $hist     = $this->db->query("SELECT * FROM {$tHist} WHERE id_pegawai_hist = ? FOR UPDATE", [$id])->getRowArray();

        if (! is_array($pegawai) || ! is_array($hist)) {
            throw new NotFoundException('Pengajuan perubahan data umum tidak ditemukan.');
        }

        if ((int) $hist['flag_update'] !== PegawaiHistModel::FLAG_DIAJUKAN || (int) $pegawai['flag_update'] !== PegawaiHistModel::FLAG_DIAJUKAN) {
            throw ValidationException::forField('aksi', 'Pengajuan ini sudah diproses atau tidak lagi berlaku.');
        }

        $sekarang = gmdate('Y-m-d H:i:s');
        $flag     = $aksi === 'setujui' ? PegawaiHistModel::FLAG_DISETUJUI : PegawaiHistModel::FLAG_DITOLAK;
        $satker   = $auth->idSatker();
        $role3    = $auth->role() === Role::ADMIN_SATKER;

        $histModel = new PegawaiHistModel($this->db);
        $histModel->withActor($auth, static fn () => $histModel->update($id, [
            'flag_update'    => $flag,
            'updated_by'     => $auth->idPengguna(),
            'updated_at'     => $sekarang,
            'approved_by'    => $auth->idPengguna(),
            'reason_note'    => $alasan,
            'show_notif'     => 1,
            'notif_date'     => $sekarang,
            'show_ua_upt'    => $role3 && $satker !== null && $satker !== '' ? 1 : 0,
            'show_ua_deputi' => $role3 && ($satker === null || $satker === '') ? 1 : 0,
            'show_ua_biro'   => $auth->role() === Role::SUPER_ADMIN ? 1 : 0,
        ]));

        $ubahPegawai = ['flag_update' => $flag, 'updated_by' => $auth->idPengguna(), 'updated_at' => $sekarang];

        if ($flag === PegawaiHistModel::FLAG_DISETUJUI) {
            $ubahPegawai += array_intersect_key($hist, array_flip(self::KOLOM_BIODATA));
        }

        $pegawaiModel = new PegawaiModel($this->db);
        $pegawaiModel->withActor($auth, static fn () => $pegawaiModel->update($nip, $ubahPegawai));

        if ($flag !== PegawaiHistModel::FLAG_DISETUJUI) {
            return;
        }

        $foto = (string) ($hist['foto'] ?? '');

        if ($foto !== '' && $foto !== (string) ($pegawai['foto'] ?? '') && $this->db->table('pegawai_foto')->where('nip', $nip)->where('foto', $foto)->countAllResults() === 0) {
            $fotoModel = new PegawaiFotoModel($this->db);
            $fotoModel->withActor($auth, static fn () => $fotoModel->insert(['nip' => $nip, 'foto' => $foto, 'created_at' => $sekarang]));
        }

        $this->sinkronEmail($auth, $nip, $hist['email'] === null ? null : (string) $hist['email']);
    }

    /**
     * Email akun aktif disamakan dengan email pengajuan [K] (:1083-1090, akun `status` 1).
     */
    private function sinkronEmail(AuthContext $auth, string $nip, ?string $email): void
    {
        $akun = $this->db->table('pengguna')->select('id_pengguna, email')->where('nip', $nip)->where('status', '1')->get()->getRowArray();

        if (! is_array($akun) || $email === null || $email === '' || (string) ($akun['email'] ?? '') === $email) {
            return;
        }

        if ($this->db->table('pengguna')->where('id_pengguna', (int) $akun['id_pengguna'])->update(['email' => $email, 'updated_at' => gmdate('Y-m-d H:i:s')]) === false) {
            $error = $this->db->error();

            throw new DatabaseException('Sinkron email akun gagal: ' . $error['message'], (int) $error['code']);
        }

        (new AuditLogModel($this->db))->record('pengguna', (int) $akun['id_pengguna'], AuditLogModel::EVENT_UPDATE, ['email' => $akun['email']], ['email' => $email], $auth->idPengguna(), $auth->nip());
    }

    /**
     * Kolom biodata (+ email) yang berbeda antara pengajuan dan data sekarang.
     *
     * @param array<string, mixed> $hist
     * @param array<string, mixed> $pegawai
     *
     * @return list<array{kolom: string, lama: mixed, baru: mixed}>
     */
    private function perubahan(array $hist, array $pegawai, ?string $email): array
    {
        $hasil = [];

        foreach (self::KOLOM_BIODATA as $kolom) {
            $lama = $pegawai[$kolom] ?? null;
            $baru = $hist[$kolom] ?? null;

            if ((string) $lama !== (string) $baru) {
                // Path berkas foto tidak dikirim: cukup tanda bahwa foto berubah.
                $hasil[] = $kolom === 'foto'
                    ? ['kolom' => 'foto', 'lama' => $lama !== null && $lama !== '', 'baru' => $baru !== null && $baru !== '']
                    : ['kolom' => $kolom, 'lama' => $lama, 'baru' => $baru];
            }
        }

        if ((string) $email !== (string) ($hist['email'] ?? '')) {
            $hasil[] = ['kolom' => 'email', 'lama' => $email, 'baru' => $hist['email']];
        }

        return $hasil;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function tampil(array $row): array
    {
        $row['ada_foto'] = (string) ($row['foto'] ?? '') !== '';

        return array_diff_key($row, array_flip(['foto', 'siasn_flag', 'siasn_lu', 'deleted_at']));
    }

    /**
     * @return array<string, mixed>
     */
    private function hist(int $id): array
    {
        $hist = $this->db->table('pegawai_hist')->where('id_pegawai_hist', $id)->get()->getRowArray();

        if (! is_array($hist)) {
            throw new NotFoundException('Pengajuan perubahan data umum tidak ditemukan.');
        }

        return $hist;
    }

    private function bolehLihat(AuthContext $auth, string $nip): bool
    {
        if (in_array($auth->role(), self::ROLE_PROSES, true)) {
            return $this->scope->bolehProses($auth, $nip);
        }

        return in_array($auth->role(), Role::UL_PEGAWAI, true) && $auth->nip() !== null && $auth->nip() === $nip;
    }

    private function emailAkun(string $nip): ?string
    {
        $akun = $this->db->table('pengguna')->select('email')->where('nip', $nip)->get()->getRowArray();

        return is_array($akun) && $akun['email'] !== null ? (string) $akun['email'] : null;
    }

    private function wajibRoleProses(AuthContext $auth): void
    {
        if (! $auth->isAuthenticated() || ! in_array($auth->role(), self::ROLE_PROSES, true)) {
            throw new ForbiddenException('Anda tidak berhak memproses pengajuan data umum.');
        }
    }

    /**
     * Berkas foto pengajuan lewat KolomBerkas (dipakai BiodataService::fotoPengajuan).
     */
    public static function bacaFoto(KolomBerkas $kolom, ?string $path): ?string
    {
        if ($path === null) {
            return null;
        }

        try {
            return $kolom->ada($path) ? $kolom->baca($path) : null;
        } catch (Throwable) {
            return null;
        }
    }
}
