<?php

declare(strict_types=1);

namespace App\Libraries\Layanan;

use App\Constants\Role;
use App\Exceptions\ForbiddenException;
use App\Exceptions\ValidationException;
use App\Interfaces\Kepegawaian\PegawaiScopeInterface;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Kepegawaian\Struktur\StrukturService;
use App\Libraries\Layanan\Cuti\CutiPenyaji;
use App\Libraries\Layanan\Cuti\StatusCuti;
use App\Libraries\Layanan\Cuti\TransisiCuti;
use App\Libraries\Layanan\Ib\IbService;
use App\Libraries\Layanan\Pmk\DasarPmkService;
use App\Libraries\Layanan\Pmk\StatusPmk;
use App\Libraries\Layanan\Pmk\TampilanPmk;
use App\Libraries\Layanan\Studi\StatusStudi;
use App\Libraries\Layanan\Studi\TampilanStudi;
use App\Libraries\Layanan\Tb\TbService;
use App\Libraries\ListQuery;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;

/**
 * Dashboard Status Layanan C-10 (PRD Fase 4 §6.10.1, D17 [V2]): gabungan pengajuan cuti, TB, IB, dan PMK (`riwayat_pmk_req`)
 * dengan tahap & petugas yang dihitung server. Lihat saja — tidak ada aksi tulis.
 *
 * Lingkup = `PegawaiScope::terapkanKeQuery` per cabang (role 1/4/5 semua, role 2/6/7 NIP sendiri, role 3 jalur daftar);
 * modul per role: 6 cuti + TB, 7 cuti + IB, lainnya keempatnya. Aturan tampilan memakai penyaji murni modul masing-masing
 * (CutiPenyaji, TampilanStudi, TampilanPmk) agar tahap & label sama dengan halaman modul. Aksi "Lihat detail" hanya bila
 * pemanggil punya route detail modul itu (role 4/5 tidak), dan untuk role 3 bila detail pegawainya terlihat (jalur detail).
 */
class LayananDashboardService
{
    /** Role route `GET layanan/status` (role 8 → 403). */
    public const ROLE_ROUTE = [Role::SUPER_ADMIN, Role::PEGAWAI, Role::ADMIN_SATKER, Role::ADMIN_VIEW_ESELON1, Role::MENTERI, Role::PTT, Role::PPPK];

    public const JENIS = ['cuti', 'tb', 'ib', 'pmk'];

    public const LABEL_JENIS = ['cuti' => 'Cuti', 'tb' => 'Tugas Belajar', 'ib' => 'Izin Belajar', 'pmk' => 'Peninjauan Masa Kerja'];

    public const STATUS_BERJALAN = 'berjalan';

    public const STATUS_SELESAI = 'selesai';

    public const CATATAN_TANPA_PIC = 'PIC belum ditunjuk';

    /** Modul yang dilihat role pegawai non-PNS (§6.10.1): PTT cuti + TB, PPPK cuti + IB. */
    private const MODUL_ROLE = [Role::PTT => ['cuti', 'tb'], Role::PPPK => ['cuti', 'ib']];

    /** Role route detail modul (RoutesLayanan / RoutesLayananStudi). */
    private const ROLE_DETAIL = [
        'cuti' => [Role::SUPER_ADMIN, Role::PEGAWAI, Role::ADMIN_SATKER, Role::PTT, Role::PPPK],
        'tb'   => TbService::ROLE_ROUTE,
        'ib'   => IbService::ROLE_ROUTE,
        'pmk'  => DasarPmkService::ROLE_ROUTE,
    ];

    /** Tabel, PK, status final per jenis. */
    private const SUMBER = [
        'cuti' => ['riwayat_cuti', 'id_riwayat_cuti', StatusCuti::FINAL],
        'tb'   => ['riwayat_tb', 'id_riwayat_tb', StatusStudi::FINAL],
        'ib'   => ['riwayat_ib', 'id_riwayat_ib', StatusStudi::FINAL],
        'pmk'  => ['riwayat_pmk_req', 'id_riwayat_pmk_req', StatusPmk::FINAL],
    ];

    private const JENIS_LAYANAN = ['tb' => PetugasLayananService::JENIS_TB, 'ib' => PetugasLayananService::JENIS_IB, 'pmk' => PetugasLayananService::JENIS_PMK];

    /** Rute halaman detail FE (M7). */
    private const URL_DETAIL = ['cuti' => '/layanan/cuti/%d', 'tb' => '/layanan/tb/%d', 'ib' => '/layanan/ib/%d', 'pmk' => '/layanan/pmk/pengajuan/%d'];

    public function __construct(
        private readonly ?BaseConnection $db = null,
        private readonly ?PegawaiScopeInterface $scope = null,
        private readonly ?PetugasLayananService $pic = null,
    ) {
    }

    /**
     * @param array<string, mixed> $query
     *
     * @return array{items: list<array<string, mixed>>, total: int, page: int, per_page: int, jenis: list<string>}
     */
    public function status(AuthContext $auth, array $query): array
    {
        $role = (int) $auth->role();

        if (! in_array($role, self::ROLE_ROUTE, true)) {
            throw new ForbiddenException();
        }

        $q       = ListQuery::from($query, ['jenis', 'status', 'id_unit', 'id_satker', 'search', 'page', 'per_page']);
        $modul   = $this->modul($role, $q);
        $page    = $q->page();
        $perPage = $q->perPage(20, 100);
        $status  = $this->filterStatus($q);

        foreach (['id_unit', 'id_satker'] as $k) {
            if ($q->filled($k) && ! ctype_digit($q->string($k))) {
                throw new ValidationException(ListQuery::INVALID_MESSAGE, [$k => ['Harus berupa ID angka.']]);
            }
        }

        if ($modul === []) {
            return ['items' => [], 'total' => 0, 'page' => $page, 'per_page' => $perPage, 'jenis' => []];
        }

        $union = implode(' UNION ALL ', array_map(fn (string $j): string => '(' . $this->cabang($auth, $j, $q, $status)->getCompiledSelect() . ')', $modul));
        $db    = $this->koneksi();
        $total = (int) ($db->query("SELECT COUNT(*) AS n FROM ({$union}) u")->getRowArray()['n'] ?? 0);
        $baris = $db->query("SELECT * FROM ({$union}) u ORDER BY u.created_at DESC, u.jenis ASC, u.id DESC LIMIT ? OFFSET ?", [$perPage, ($page - 1) * $perPage])->getResultArray();

        return ['items' => $this->isi($auth, $baris), 'total' => $total, 'page' => $page, 'per_page' => $perPage, 'jenis' => $modul];
    }

    /**
     * Modul yang dilihat role ∩ filter `jenis`.
     *
     * @return list<string>
     */
    private function modul(int $role, ListQuery $q): array
    {
        $boleh = self::MODUL_ROLE[$role] ?? self::JENIS;

        if (! $q->filled('jenis')) {
            return $boleh;
        }

        $jenis = $q->string('jenis');

        if (! in_array($jenis, self::JENIS, true)) {
            throw new ValidationException(ListQuery::INVALID_MESSAGE, ['jenis' => ['Jenis layanan harus salah satu dari: ' . implode(', ', self::JENIS) . '.']]);
        }

        return in_array($jenis, $boleh, true) ? [$jenis] : [];
    }

    /**
     * `berjalan` | `selesai` | kode status angka | null.
     */
    private function filterStatus(ListQuery $q): int|string|null
    {
        if (! $q->filled('status')) {
            return null;
        }

        $s = $q->string('status');

        return match (true) {
            in_array($s, [self::STATUS_BERJALAN, self::STATUS_SELESAI], true) => $s,
            ctype_digit($s) && strlen($s) <= 2                                => (int) $s,
            default                                                           => throw new ValidationException(ListQuery::INVALID_MESSAGE, ['status' => ['Status harus berjalan, selesai, atau kode status.']]),
        };
    }

    private function cabang(AuthContext $auth, string $jenis, ListQuery $q, int|string|null $status): BaseBuilder
    {
        [$tabel, $pk, $final] = self::SUMBER[$jenis];

        $b = $this->koneksi()->table("{$tabel} x")
            ->select("'{$jenis}' AS jenis, x.{$pk} AS id, x.created_at", false);

        $this->scope()->terapkanKeQuery($b, $auth, 'x.nip');

        if ($status === self::STATUS_BERJALAN) {
            $b->whereNotIn('x.status', $final);
        } elseif ($status === self::STATUS_SELESAI) {
            $b->whereIn('x.status', $final);
        } elseif (is_int($status)) {
            $b->where('x.status', $status);
        }

        if ($q->filled('search')) {
            $like = ListQuery::likeLiteral($this->koneksi(), $q->string('search'));
            $b->join('pegawai p', 'p.nip = x.nip', 'left')
                ->groupStart()->like('p.nama', $like, 'both', null, true)->orLike('x.nip', $like, 'both', null, true)->groupEnd();
        }

        if ($q->filled('id_unit') || $q->filled('id_satker')) {
            $b->join('pegawai_mutasi_jabatan pmj', 'pmj.nip = x.nip', 'inner');
            $q->filled('id_unit') && $b->where('pmj.id_unit', (int) $q->string('id_unit'));
            $q->filled('id_satker') && $b->where('pmj.id_satker', (int) $q->string('id_satker'));
        }

        return $b;
    }

    /**
     * Muat baris lengkap halaman ini per jenis lalu bentuk item (urutan union dipertahankan).
     *
     * @param list<array<string, mixed>> $baris
     *
     * @return list<array<string, mixed>>
     */
    private function isi(AuthContext $auth, array $baris): array
    {
        $db   = $this->koneksi();
        $data = [];

        foreach (self::SUMBER as $jenis => [$tabel, $pk]) {
            $ids = array_map(static fn (array $r): int => (int) $r['id'], array_values(array_filter($baris, static fn (array $r): bool => $r['jenis'] === $jenis)));

            if ($ids === []) {
                continue;
            }

            foreach ($db->table($tabel)->whereIn($pk, $ids)->get()->getResultArray() as $row) {
                $data[$jenis][(int) $row[$pk]] = $row;
            }
        }

        $nips    = array_values(array_unique(array_map(static fn (array $r): string => (string) $r['nip'], array_merge([], ...array_map('array_values', array_values($data))))));
        $pegawai = $this->pegawai($nips);
        $pic     = [];
        $detail  = [];
        $items   = [];

        foreach ($baris as $r) {
            $jenis = (string) $r['jenis'];
            $row   = $data[$jenis][(int) $r['id']] ?? null;

            if ($row === null) {
                continue; // terhapus di antara dua kueri
            }

            $nip = (string) $row['nip'];
            $p   = $pegawai[$nip] ?? ['nama' => null, 'unit' => null, 'satker' => null];

            if ($jenis !== 'cuti' && ! isset($pic[$jenis])) {
                $pic[$jenis] = $this->picAktif(self::JENIS_LAYANAN[$jenis]);
            }

            $detail[$jenis . '|' . $nip] ??= $this->bolehDetail($auth, $jenis, $nip);
            $boleh = $detail[$jenis . '|' . $nip];

            $item = [
                'jenis'             => $jenis,
                'jenis_layanan'     => self::LABEL_JENIS[$jenis],
                'id'                => (int) $r['id'],
                'nip'               => $nip,
                'nama'              => $this->teks($row['nama'] ?? null) ?? $p['nama'],
                'unit'              => $p['unit'],
                'satker'            => $p['satker'],
                'tanggal_pengajuan' => $row['created_at'],
                'status'            => (int) $row['status'],
            ] + ($jenis === 'cuti' ? $this->tampilCuti($row) : $this->tampilLayanan($jenis, $row, $pic[$jenis]));

            $items[] = $item + [
                'url_detail' => $boleh ? sprintf(self::URL_DETAIL[$jenis], (int) $r['id']) : null,
                'aksi'       => ['lihat_detail' => $boleh],
            ];
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function tampilCuti(array $row): array
    {
        $nipAl   = $this->teks($row['nip_atasan_langsung'] ?? null);
        $nipPyb  = $this->teks($row['nip_yang_menyetujui'] ?? null);
        $tunggal = TransisiCuti::langkahTunggal($nipAl, $nipPyb);
        $status  = (int) $row['status'];
        $label   = CutiPenyaji::label($status, $tunggal, (int) ($row['id_sub_group_jabatan'] ?? 0) === 1);
        $tahap   = array_map(static fn (array $t): array => $t + [
            'waktu' => match ($t['kode']) {
                'diajukan' => $row['created_at'],
                'selesai'  => StatusCuti::final($status) ? $row['updated_at'] : null,
                default    => null,
            },
        ], CutiPenyaji::tahap($row, $tunggal));

        $petugas = [];

        if ($nipAl !== null && ! $tunggal) {
            $petugas[] = ['peran' => 'Atasan Langsung', 'nip' => $nipAl, 'nama' => $this->teks($row['nama_atasan_langsung'] ?? null)];
        }

        if ($nipPyb !== null) {
            $petugas[] = ['peran' => 'Pejabat Berwenang', 'nip' => $nipPyb, 'nama' => $this->teks($row['nama_yang_menyetujui'] ?? null)];
        } elseif ($nipAl !== null) {
            $petugas[] = ['peran' => 'Atasan Langsung', 'nip' => $nipAl, 'nama' => $this->teks($row['nama_atasan_langsung'] ?? null)];
        }

        return [
            'ringkasan'    => sprintf('%s %s s/d %s', (string) $row['nama_jenis_cuti'], $this->tgl($row['tgl_mulai']), $this->tgl($row['tgl_akhir'])),
            'label_status' => $label['label'],
            'nada_status'  => $label['nada'],
            'tahap'        => $tahap,
            'petugas'      => $petugas,
            'catatan'      => null,
        ];
    }

    /**
     * TB / IB / PMK: tahap PIC + semua PIC aktif jenisnya.
     *
     * @param array<string, mixed>                                    $row
     * @param list<array{peran: string, nip: string, nama: ?string}> $pic
     *
     * @return array<string, mixed>
     */
    private function tampilLayanan(string $jenis, array $row, array $pic): array
    {
        $status = (int) $row['status'];

        if ($jenis === 'pmk') {
            $tampil = [
                'ringkasan'    => sprintf('%s - %s, %s s/d %s', (string) $row['jenis_pmk'], (string) $row['instansi'], $this->tgl($row['tgl_awal']), $this->tgl($row['tgl_akhir'])),
                'label_status' => StatusPmk::labelUi($status),
                'nada_status'  => TampilanPmk::nadaStatus($status),
                'tahap'        => TampilanPmk::tahap($status, $row['created_at'] ?? null, $row['approved_at'] ?? null),
            ];
        } else {
            $tampil = [
                'ringkasan' => sprintf(
                    '%s %s - %s (%s), %s s/d %s',
                    self::LABEL_JENIS[$jenis],
                    (string) $row['jenjang_pendidikan_singkat'],
                    (string) $row['institusi_pendidikan'],
                    (string) $row['jurusan_pendidikan'],
                    $this->tgl($row['tgl_mulai']),
                    $this->tgl($row['tgl_akhir']),
                ),
                'label_status' => TampilanStudi::labelStatus($status),
                'nada_status'  => TampilanStudi::nadaStatus($status),
                'tahap'        => TampilanStudi::tahap(
                    $status,
                    $row['created_at'] ?? null,
                    StatusStudi::final($status) ? ($row['notif_date'] ?? $row['updated_at'] ?? null) : null,
                    null,
                ),
            ];
        }

        return $tampil + ['petugas' => $pic, 'catatan' => $pic === [] ? self::CATATAN_TANPA_PIC : null];
    }

    private function bolehDetail(AuthContext $auth, string $jenis, string $nip): bool
    {
        $role = (int) $auth->role();

        if (! in_array($role, self::ROLE_DETAIL[$jenis], true)) {
            return false;
        }

        // Role 3: daftar memakai jalur daftar PegawaiScope yang bisa lebih luas dari jalur detail.
        return $role !== Role::ADMIN_SATKER || $this->scope()->bolehLihat($auth, $nip);
    }

    /**
     * @return list<array{peran: string, nip: string, nama: ?string}>
     */
    private function picAktif(int $idJenisLayanan): array
    {
        $nips = $this->pic()->nipPicAktif($idJenisLayanan);
        $peg  = $this->pegawai($nips);

        return array_map(static fn (string $nip): array => ['peran' => 'PIC', 'nip' => $nip, 'nama' => $peg[$nip]['nama'] ?? null], $nips);
    }

    /**
     * Nama bergelar + unit/satker jabatan saat ini.
     *
     * @param list<string> $nips
     *
     * @return array<string, array{nama: ?string, unit: ?string, satker: ?string}>
     */
    private function pegawai(array $nips): array
    {
        if ($nips === []) {
            return [];
        }

        $rows = $this->koneksi()->table('pegawai p')
            ->select('p.nip, p.glr_awal, p.nama, p.glr_akhir, pmj.unit, pmj.satker')
            ->join('pegawai_mutasi_jabatan pmj', 'pmj.nip = p.nip', 'left')
            ->whereIn('p.nip', $nips)->get()->getResultArray();

        $out = [];

        foreach ($rows as $r) {
            $out[(string) $r['nip']] = [
                'nama'   => StrukturService::namaGelar($r['glr_awal'], (string) $r['nama'], $r['glr_akhir']),
                'unit'   => $this->teks($r['unit']),
                'satker' => $this->teks($r['satker']),
            ];
        }

        return $out;
    }

    /**
     * `YYYY-MM-DD` → `DD-MM-YYYY` (contoh ringkasan §6.10.1).
     */
    private function tgl(mixed $tanggal): string
    {
        $t = (string) $tanggal;

        return preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $t, $m) === 1 ? "{$m[3]}-{$m[2]}-{$m[1]}" : $t;
    }

    private function teks(mixed $nilai): ?string
    {
        $t = is_scalar($nilai) ? trim((string) $nilai) : '';

        return $t === '' ? null : $t;
    }

    private function koneksi(): BaseConnection
    {
        return $this->db ?? db_connect();
    }

    private function scope(): PegawaiScopeInterface
    {
        return $this->scope ?? service('pegawaiScope');
    }

    private function pic(): PetugasLayananService
    {
        return $this->pic ?? new PetugasLayananService($this->koneksi());
    }
}
