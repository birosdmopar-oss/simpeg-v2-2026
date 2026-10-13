<?php

declare(strict_types=1);

namespace App\Libraries\Kepegawaian\Pegawai;

use App\Constants\Role;
use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;
use App\Interfaces\Kepegawaian\LkhServiceInterface;
use App\Interfaces\Kepegawaian\PegawaiScopeInterface;
use App\Interfaces\Kepegawaian\PegawaiServiceInterface;
use App\Interfaces\Kepegawaian\RiwayatRegistryInterface;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Kepegawaian\Lampiran\KolomBerkas;
use App\Libraries\ListQuery;
use App\Models\Kepegawaian\PegawaiHistModel;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\HTTP\Files\UploadedFile;

/**
 * B-20 dasar (MAKE-010): daftar & detail pegawai — implementasi nyata PegawaiServiceInterface (service
 * `pegawaiService`).
 *
 * Daftar [K] `libraries/hr/L_employee.php:6170-6247` (`list`): role 1, 3, 4, 5, 8 (`controllers/hr/Employee.php:16`),
 * `pegawai.status IN (1, 2)`, lingkup role 3 lewat PegawaiScope jalur daftar (CR-045). Kolom = biodata inti +
 * snapshot jabatan (`pegawai_mutasi_jabatan`) + pangkat (`pegawai_kp`); 90 kolom legacy, pencarian lanjut, dan export
 * menyusul di B-20 penutup (MAKE-014). Deviasi [V2]: urutan bawaan nama (legacy mengurutkan per unit/satker/eselon di
 * `get_all_pegawai`), tanpa pengecualian `NIP_TESTER`/`USR_DEVELOPER`, paginasi server.
 *
 * Detail [K] `controllers/hr/Employee.php:254-287` (`detail`) + `L_employee.php:68-77`: biodata `pegawai` + email akun
 * `pengguna`. Hak lihat ikut Matriks v2 (`hr/employee/detail` = UL_ALL; legacy `UL_ES1`) dengan lingkup
 * `PegawaiScope::bolehLihat`. `tabs` = descriptor engine `RiwayatRegistryInterface::descriptorUntuk()` (fungsi yang
 * sama dengan `GET pegawai/{nip}/riwayat` WS-1). Path `pegawai.foto` tidak pernah dikirim (diganti `ada_foto`; berkas
 * lewat `GET pegawai/{nip}/foto`).
 *
 * TODO(B-20 penutup, MAKE-014): data sensitif (NIK, NPWP, BPJS, TASPEN, no. HP, kerabat) untuk role 4/5/8 sementara ikut
 * legacy (ditampilkan penuh, `UL_ES1`); dibahas ulang di MAKE-014 (keputusan user 13-10-2026).
 */
final class PegawaiService implements PegawaiServiceInterface
{
    /**
     * Role daftar pegawai (Matriks `hr/employee/index`).
     *
     * @var list<int>
     */
    public const ROLE_DAFTAR = [Role::SUPER_ADMIN, Role::ADMIN_SATKER, Role::ADMIN_VIEW_ESELON1, Role::MENTERI, Role::PIMPINAN];

    /**
     * Role ubah biodata (Matriks `hr/employee/edit/{nip?}`).
     *
     * @var list<int>
     */
    public const ROLE_UBAH_BIODATA = [Role::SUPER_ADMIN, Role::PEGAWAI, Role::ADMIN_SATKER, Role::PTT, Role::PPPK];

    public const PER_PAGE_DEFAULT = 10;
    public const PER_PAGE_MAX     = 100;

    /**
     * Kolom `pegawai` yang tidak dikirim ke klien (path berkas & kolom internal sinkron SIASN/soft delete).
     *
     * @var list<string>
     */
    private const KOLOM_TERSEMBUNYI = ['foto', 'siasn_flag', 'siasn_lu', 'deleted_at'];

    /**
     * Kolom urut daftar yang diizinkan: kunci query => kolom SQL.
     */
    private const URUT = [
        'nama'      => 'pegawai.nama',
        'nip'       => 'pegawai.nip',
        'jabatan'   => 'pegawai_mutasi_jabatan.jabatan',
        'unit'      => 'pegawai_mutasi_jabatan.unit',
        'satker'    => 'pegawai_mutasi_jabatan.satker',
        'gol_ruang' => 'pegawai_kp.gol_ruang',
    ];

    public function __construct(
        private readonly ?BaseConnection $db = null,
        private readonly ?PegawaiScopeInterface $scope = null,
        private readonly ?RiwayatRegistryInterface $registry = null,
        private readonly ?KolomBerkas $berkas = null,
        private readonly ?LkhServiceInterface $lkh = null,
    ) {
    }

    public function daftar(AuthContext $auth, array $filter = []): array
    {
        if (! in_array($auth->role(), self::ROLE_DAFTAR, true) || ! $auth->isAuthenticated()) {
            throw new ForbiddenException('Anda tidak berhak melihat daftar pegawai.');
        }

        $query   = ListQuery::from($filter, ['search', 'status', 'id_unit', 'id_satker', 'id_jenis_pegawai', 'sort', 'order', 'page', 'per_page']);
        $page    = $query->page();
        $perPage = $query->perPage(self::PER_PAGE_DEFAULT, self::PER_PAGE_MAX);
        $db      = $this->koneksi();
        $foto    = $db->escapeIdentifiers($db->prefixTable('pegawai')) . '.' . $db->escapeIdentifiers('foto');

        $builder = $db->table('pegawai')
            ->select('pegawai.nip, pegawai.nip_lama, pegawai.nama, pegawai.glr_awal, pegawai.glr_akhir, pegawai.jenis_kelamin, pegawai.tgl_lahir, pegawai.agama, pegawai.id_jenis_pegawai, pegawai.jenis_pegawai, pegawai.jenis_status, pegawai.tmt_status, pegawai.status, pegawai.flag_update')
            ->select("({$foto} IS NOT NULL AND {$foto} <> '') AS ada_foto", false)
            ->select('pegawai_mutasi_jabatan.id_unit, pegawai_mutasi_jabatan.unit, pegawai_mutasi_jabatan.id_satker, pegawai_mutasi_jabatan.satker, pegawai_mutasi_jabatan.id_jabatan, pegawai_mutasi_jabatan.jabatan, pegawai_mutasi_jabatan.tmtsk AS tmt_jabatan')
            ->select('pegawai_kp.gol_ruang, pegawai_kp.pangkat')
            ->join('pegawai_mutasi_jabatan', 'pegawai_mutasi_jabatan.nip = pegawai.nip', 'left')
            ->join('pegawai_kp', 'pegawai_kp.nip = pegawai.nip', 'left');

        $this->scope()->terapkanKeQuery($builder, $auth, 'pegawai.nip');
        $this->terapkanFilter($builder, $query, $db);

        $total = (clone $builder)->countAllResults();

        $urut = self::URUT[$query->string('sort')] ?? 'pegawai.nama';
        $arah = strtolower($query->string('order')) === 'desc' ? 'DESC' : 'ASC';
        $rows = $builder->orderBy($urut, $arah)->orderBy('pegawai.nip', 'ASC')->limit($perPage, ($page - 1) * $perPage)->get()->getResultArray();

        return [
            'items' => array_map(static function (array $row): array {
                $row['ada_foto'] = (bool) (int) $row['ada_foto'];

                return $row;
            }, $rows),
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
        ];
    }

    public function detail(AuthContext $auth, string $nip): array
    {
        $scope = $this->scope();

        if (! $scope->bolehLihat($auth, $nip)) {
            throw new ForbiddenException('Anda tidak berhak mengakses data pegawai ini.');
        }

        $db      = $this->koneksi();
        $pegawai = $db->table('pegawai')->where('nip', $nip)->get()->getRowArray();

        if (! is_array($pegawai)) {
            throw new NotFoundException('Pegawai tidak ditemukan.');
        }

        $akun = $db->table('pengguna')->select('email')->where('nip', $nip)->get()->getRowArray();
        $pmj  = $db->table('pegawai_mutasi_jabatan')
            ->select('id_unit, unit, id_satker, satker, id_jabatan, jabatan, jabatan_lain, jenis_jabatan, tmtsk')
            ->where('nip', $nip)->get()->getRowArray();
        $kp = $db->table('pegawai_kp')->select('gol_ruang, pangkat, tmtsk')->where('nip', $nip)->get()->getRowArray();

        $hasil                  = array_diff_key($pegawai, array_flip(self::KOLOM_TERSEMBUNYI));
        $hasil['ada_foto']      = (string) ($pegawai['foto'] ?? '') !== '';
        $hasil['email']         = is_array($akun) ? $akun['email'] : null;
        $hasil['jabatan_aktif'] = is_array($pmj) ? $pmj : null;
        $hasil['pangkat_aktif'] = is_array($kp) ? $kp : null;
        $hasil['pengajuan']     = $this->pengajuanTerakhir($db, $pegawai);

        $bolehUbah     = in_array($auth->role(), self::ROLE_UBAH_BIODATA, true) && (int) $pegawai['status'] !== 10 && $scope->bolehUbah($auth, $nip);
        $hasil['aksi'] = [
            'ubah_biodata'  => $bolehUbah,
            'ubah_langsung' => $bolehUbah && $auth->role() === Role::SUPER_ADMIN,
            'koreksi_nip'   => $auth->role() === Role::SUPER_ADMIN,
            // B-04 (MAKE-011): pengajuan menunggu bisa diproses pemanggil (role 1/3 + jalur proses).
            'proses_biodata' => (int) ($hasil['pengajuan']['flag_update'] ?? 0) === PegawaiHistModel::FLAG_DIAJUKAN
                && in_array($auth->role(), [Role::SUPER_ADMIN, Role::ADMIN_SATKER], true)
                && $scope->bolehProses($auth, $nip),
        ];
        // Descriptor engine lalu non-engine WS-2 (README kontrak "Descriptor tab"): `lkh` (MAKE-014).
        $hasil['tabs'] = $this->registry()->descriptorUntuk($auth, $nip);
        $lkh           = $this->lkh()->descriptorTab($auth, $nip);

        if ($lkh !== null) {
            $hasil['tabs'][] = $lkh;
        }

        return $hasil;
    }

    /**
     * Status pengajuan biodata terakhir bila `pegawai.flag_update` 1 (diajukan) atau 2 (ditolak) — legacy menampilkan
     * "Status Update" + alasan tolak di form (`views/hr/employee/form.php:114-140`).
     *
     * @param array<string, mixed> $pegawai
     *
     * @return array<string, mixed>|null
     */
    private function pengajuanTerakhir(BaseConnection $db, array $pegawai): ?array
    {
        if (! in_array((int) $pegawai['flag_update'], [PegawaiHistModel::FLAG_DIAJUKAN, PegawaiHistModel::FLAG_DITOLAK], true)) {
            return null;
        }

        $hist = $db->table('pegawai_hist')
            ->select('id_pegawai_hist, flag_update, reason_note, created_at, updated_at, notif_date')
            ->where('nip', $pegawai['nip'])
            ->orderBy('id_pegawai_hist', 'DESC')
            ->limit(1)
            ->get()->getRowArray();

        return is_array($hist) ? $hist : null;
    }

    private function terapkanFilter(BaseBuilder $builder, ListQuery $query, BaseConnection $db): void
    {
        $status = $query->string('status');
        in_array($status, ['1', '2'], true)
            ? $builder->where('pegawai.status', (int) $status)
            : $builder->whereIn('pegawai.status', [1, 2]);

        if ($query->filled('search')) {
            $s = ListQuery::likeLiteral($db, $query->string('search'));
            $builder->groupStart()->like('pegawai.nama', $s)->orLike('pegawai.nip', $s)->orLike('pegawai.nip_lama', $s)->groupEnd();
        }

        foreach (['id_unit' => 'pegawai_mutasi_jabatan.id_unit', 'id_satker' => 'pegawai_mutasi_jabatan.id_satker', 'id_jenis_pegawai' => 'pegawai.id_jenis_pegawai'] as $kunci => $kolom) {
            if (! $query->filled($kunci)) {
                continue;
            }

            $nilai = $query->string($kunci);
            ctype_digit($nilai) ? $builder->where($kolom, (int) $nilai) : $builder->where('1 = 0', null, false);
        }
    }

    public function tambah(AuthContext $auth, array $data, ?UploadedFile $foto): array
    {
        return $this->siklus()->tambah($auth, $data, $foto);
    }

    public function hapus(AuthContext $auth, string $nip): array
    {
        return $this->siklus()->hapus($auth, $nip);
    }

    private function siklus(): SiklusPegawai
    {
        $db = $this->koneksi();

        return new SiklusPegawai($db, $this->berkas ?? new KolomBerkas(null, $db), service('accountProvisioner'));
    }

    private function scope(): PegawaiScopeInterface
    {
        return $this->scope ?? service('pegawaiScope');
    }

    private function lkh(): LkhServiceInterface
    {
        return $this->lkh ?? service('lkhService');
    }

    private function registry(): RiwayatRegistryInterface
    {
        return $this->registry ?? service('riwayatRegistry');
    }

    private function koneksi(): BaseConnection
    {
        return $this->db ?? db_connect();
    }
}
