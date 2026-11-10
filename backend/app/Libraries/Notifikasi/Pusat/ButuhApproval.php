<?php

declare(strict_types=1);

namespace App\Libraries\Notifikasi\Pusat;

use App\Constants\Role;
use App\Exceptions\ApiException;
use App\Interfaces\Kepegawaian\PegawaiScopeInterface;
use App\Interfaces\Kepegawaian\PegawaiScopeProsesInterface;
use App\Interfaces\Kepegawaian\RiwayatRegistryInterface;
use App\Interfaces\Notifikasi\SumberNotifikasiInterface;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Kepegawaian\Riwayat\AksiRiwayat;
use App\Libraries\Kepegawaian\Riwayat\AlurRiwayat;
use App\Libraries\Kepegawaian\Riwayat\RiwayatDefinisi;
use App\Libraries\Layanan\Ib\IbService;
use App\Libraries\Layanan\Pmk\PmkPengajuanService;
use App\Libraries\Layanan\Tb\TbService;
use Closure;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;

/**
 * S3 — antrean "Butuh Approval" per role (legacy `need_approval` + penghitung `tna`, `L_notification.php:1144-2457`).
 *
 * - Role 1: riwayat self-service status 0/3 (`status NOT IN (1,2,10)` `LN:1234-1252`), usulan Karpeg/Karis, pengajuan
 *   biodata (`flag_update` 1), Konket `W` induk (`id_parent IS NULL`), berita (adapter).
 * - Role 3: riwayat status 0, biodata, Konket — dalam lingkup jalur proses PegawaiScope (`LN:1260-1398`).
 * - Role 2: LKH atasan, cuti AL/PyB, TB/IB/PMK PIC (`LN:1144-1210`) — dihitung lewat service antrean modulnya
 *   (satu sumber kondisi dengan halaman modul, B-F8-1); bukan petugas/atasan → 0.
 *
 * Grup tanpa halaman antrean di v2 (riwayat lintas pegawai) mengembalikan daftar item; grup lain hanya jumlah + tautan.
 */
final class ButuhApproval
{
    public const ROLE = [Role::SUPER_ADMIN, Role::PEGAWAI, Role::ADMIN_SATKER];

    /**
     * Batas gabungan item riwayat (paginasi gabungan lintas tabel, B-F8-5) [V2].
     */
    public const BATAS_GABUNGAN = 500;

    /**
     * @param array<string, Closure(AuthContext): int> $penghitungLayanan kode => penghitung (default service modul)
     */
    public function __construct(
        private readonly BaseConnection $db,
        private readonly RiwayatRegistryInterface $registry,
        private readonly PegawaiScopeInterface $scope,
        private readonly SumberNotifikasiInterface $berita,
        private ?array $penghitungLayanan = null,
    ) {
    }

    /**
     * @return list<array{kode: string, label: string, jumlah: int, url_fe: ?string}>
     */
    public function grup(AuthContext $auth): array
    {
        $role = (int) $auth->role();

        if (! in_array($role, self::ROLE, true)) {
            return [];
        }

        $grup = [];

        if (in_array($role, [Role::SUPER_ADMIN, Role::ADMIN_SATKER], true)) {
            $grup[] = ['kode' => 'riwayat', 'label' => 'Riwayat Pegawai', 'jumlah' => $this->jumlahRiwayat($auth), 'url_fe' => '/notifikasi/butuh-approval?grup=riwayat'];

            foreach ($this->definisiUsulan($role) as $d) {
                $grup[] = ['kode' => $d->jenis(), 'label' => $d->label(), 'jumlah' => $this->builderRiwayat($auth, $d)->countAllResults(), 'url_fe' => "/karpeg-karis?jenis={$d->jenis()}"];
            }

            $grup[] = $this->grupLayanan($auth, 'biodata', 'Pengajuan Perubahan Biodata', '/biodata/pengajuan');
            $grup[] = ['kode' => 'konket', 'label' => 'Usulan Konket', 'jumlah' => $this->builderKonket($auth)->countAllResults(), 'url_fe' => '/konket'];

            if ($role === Role::SUPER_ADMIN && $this->berita->tersedia()) {
                $grup[] = ['kode' => $this->berita->kode(), 'label' => 'Berita', 'jumlah' => $this->berita->jumlahMenunggu($auth), 'url_fe' => $this->berita->daftarMenunggu($auth, 1, 1)['url_fe']];
            }
        } else {
            $grup[] = $this->grupLayanan($auth, 'lkh', 'Verifikasi LKH', '/lkh/verifikasi?tab=verifikasi');
            $grup[] = $this->grupLayanan($auth, 'cuti', 'Persetujuan Cuti', '/layanan/cuti?tab=persetujuan');
            $grup[] = $this->grupLayanan($auth, 'tb', 'Tugas Belajar', '/layanan/tb');
            $grup[] = $this->grupLayanan($auth, 'ib', 'Izin Belajar', '/layanan/ib');
            $grup[] = $this->grupLayanan($auth, 'pmk', 'PMK', '/layanan/pmk?tab=pic');
        }

        return array_values(array_filter($grup, static fn (array $g): bool => $g['jumlah'] > 0));
    }

    public function total(AuthContext $auth): int
    {
        return array_sum(array_column($this->grup($auth), 'jumlah'));
    }

    /**
     * Item riwayat menunggu proses (role 1/3), terbaru dulu.
     *
     * @return array{items: list<array<string, mixed>>, total: int, page: int, per_page: int}
     */
    public function daftarRiwayat(AuthContext $auth, int $page, int $perPage): array
    {
        $role = (int) $auth->role();

        if (! in_array($role, [Role::SUPER_ADMIN, Role::ADMIN_SATKER], true)) {
            return ['items' => [], 'total' => 0, 'page' => $page, 'per_page' => $perPage];
        }

        $batas = min(self::BATAS_GABUNGAN, $page * $perPage);
        $items = [];
        $total = 0;

        foreach ($this->definisiRiwayat($role) as $d) {
            $total += $this->builderRiwayat($auth, $d)->countAllResults();
            $t    = 'r';
            $rows = $this->builderRiwayat($auth, $d)
                ->select("{$t}.{$d->primaryKey()} AS id, {$t}.nip, {$t}.{$d->kolomStatus()} AS status_baris, {$t}.updated_at, p.nama")
                ->join('pegawai p', "p.nip = {$t}.nip", 'left')
                ->orderBy("{$t}.updated_at", 'DESC')->orderBy("{$t}.{$d->primaryKey()}", 'DESC')->limit($batas)->get()->getResultArray();

            foreach ($rows as $r) {
                $items[] = [
                    'id'           => $d->jenis() . ':' . (int) $r['id'],
                    'sumber'       => $d->jenis(),
                    'judul'        => $d->label(),
                    'keterangan'   => (int) $r['status_baris'] === 3 ? 'Diproses, menunggu keputusan' : 'Menunggu proses',
                    'status_label' => (int) $r['status_baris'] === 3 ? 'Diproses' : 'Menunggu',
                    'nip'          => (string) $r['nip'],
                    'nama'         => $r['nama'] === null ? null : (string) $r['nama'],
                    'url_fe'       => '/pegawai/' . rawurlencode((string) $r['nip']) . '?tab=' . $d->jenis(),
                    'waktu'        => (string) ($r['updated_at'] ?? ''),
                ];
            }
        }

        usort($items, static fn (array $a, array $b): int => [$b['waktu'], $b['id']] <=> [$a['waktu'], $a['id']]);

        return [
            'items'    => array_slice($items, ($page - 1) * $perPage, $perPage),
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
        ];
    }

    private function jumlahRiwayat(AuthContext $auth): int
    {
        $total = 0;

        foreach ($this->definisiRiwayat((int) $auth->role()) as $d) {
            $total += $this->builderRiwayat($auth, $d)->countAllResults();
        }

        return $total;
    }

    /**
     * Riwayat self-service yang boleh diproses role pemanggil.
     *
     * @return list<RiwayatDefinisi>
     */
    private function definisiRiwayat(int $role): array
    {
        return array_values(array_filter($this->registry->semua(), static fn (RiwayatDefinisi $d): bool => $d->alur() === AlurRiwayat::SelfService && $d->boleh(AksiRiwayat::Proses, $role)));
    }

    /**
     * @return list<RiwayatDefinisi>
     */
    private function definisiUsulan(int $role): array
    {
        return array_values(array_filter($this->registry->semua(), static fn (RiwayatDefinisi $d): bool => $d->alur() === AlurRiwayat::Usulan && $d->boleh(AksiRiwayat::Proses, $role)));
    }

    private function builderRiwayat(AuthContext $auth, RiwayatDefinisi $d): BaseBuilder
    {
        $b      = $this->db->table($d->tabel() . ' r');
        $status = 'r.' . $d->kolomStatus();
        (int) $auth->role() === Role::SUPER_ADMIN ? $b->whereIn($status, [0, 3]) : $b->where($status, 0);
        $this->lingkup($b, $auth, 'r.nip');

        return $b;
    }

    private function builderKonket(AuthContext $auth): BaseBuilder
    {
        $b = $this->db->table('absen_ijin a')->where('a.status', 'W')->where('a.id_parent', null);
        $this->lingkup($b, $auth, 'a.nip');

        return $b;
    }

    private function lingkup(BaseBuilder $b, AuthContext $auth, string $kolomNip): void
    {
        if ((int) $auth->role() === Role::SUPER_ADMIN) {
            return;
        }

        $this->scope instanceof PegawaiScopeProsesInterface
            ? $this->scope->terapkanKeQueryProses($b, $auth, $kolomNip)
            : $this->scope->terapkanKeQuery($b, $auth, $kolomNip);
    }

    /**
     * @return array{kode: string, label: string, jumlah: int, url_fe: ?string}
     */
    private function grupLayanan(AuthContext $auth, string $kode, string $label, string $url): array
    {
        $hitung = $this->penghitung()[$kode] ?? null;

        try {
            $n = $hitung === null ? 0 : $hitung($auth);
        } catch (ApiException) {
            // Bukan petugas/atasan modul itu (403/422 dari service modul) = tidak punya antrean.
            $n = 0;
        }

        return ['kode' => $kode, 'label' => $label, 'jumlah' => $n, 'url_fe' => $url];
    }

    /**
     * Penghitung bawaan memakai service antrean modul masing-masing (kondisi tunggal dengan halaman modul).
     *
     * @return array<string, Closure(AuthContext): int>
     */
    private function penghitung(): array
    {
        return $this->penghitungLayanan ??= [
            'biodata' => static fn (AuthContext $a): int => (int) (service('biodataService')->antreanPengajuan($a, ['per_page' => 1])['total']),
            'lkh'     => static fn (AuthContext $a): int => (int) (service('lkhService')->verifikasi($a, ['status' => '0', 'per_page' => 1])['meta']['total'] ?? 0),
            'cuti'    => static fn (AuthContext $a): int => (int) (service('cutiService')->daftar($a, ['mode' => 'persetujuan', 'per_page' => 1])['total'] ?? 0),
            'tb'      => static fn (AuthContext $a): int => (int) ((new TbService())->daftar($a, ['mode' => 'pic', 'per_page' => 1])['total']),
            'ib'      => static fn (AuthContext $a): int => (int) ((new IbService())->daftar($a, ['mode' => 'pic', 'per_page' => 1])['total']),
            'pmk'     => static fn (AuthContext $a): int => (int) ((new PmkPengajuanService())->daftar($a, ['mode' => 'pic', 'per_page' => 1])['total']),
        ];
    }
}
