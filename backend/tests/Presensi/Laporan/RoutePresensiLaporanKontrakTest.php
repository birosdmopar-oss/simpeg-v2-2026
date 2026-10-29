<?php

declare(strict_types=1);

namespace Tests\Presensi\Laporan;

use App\Constants\Role;
use CodeIgniter\Router\RouteCollection;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use Tests\Support\AuthTestTrait;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;
use Tests\Support\Presensi\LaporanTukinUjiTrait;
use Tests\Support\Presensi\RekapUjiTrait;
use Tests\Support\Presensi\TukinDataUjiTrait;

/**
 * PRD 5B §6, §7.7, §11.1 #4 (M7 MAKE-043): **seluruh** route 5B (`Config/RoutesPresensiLaporan.php`) × role 1–8 dalam
 * satu matriks lewat HTTP — role berhak tidak pernah 401/403 (status 2xx, atau 404/422 untuk tulis penyesuaian tanpa isi),
 * role lain 403, tanpa token 401. Route per pegawai: role 2/6/7 memakai NIP sendiri (NIP lain = 403 service, AC-D08.1 di
 * `LaporanTukinPegawaiTest`). Daftar route di router harus sama persis dengan matriks, sehingga route 5B baru tanpa
 * baris matriks membuat test gagal. Melengkapi `RoleMatriksLaporanTest` (tanpa route PDF), `RoleMatriksRekapTest`,
 * `PenyesuaianTukinCrudTest::testRouteHanyaRole1DanAlurHttp`, dan baris `KontrakMatriksRoleTest`.
 *
 * @internal
 */
final class RoutePresensiLaporanKontrakTest extends DatabaseTestCase
{
    use AuthTestTrait;
    use FeatureTestTrait;
    use LaporanTukinUjiTrait;
    use PegawaiFixtureTrait;
    use RekapUjiTrait;
    use TukinDataUjiTrait;

    /** Route 5B: `[verb, pola router, URL uji, role berhak, status role berhak]` (tulis penyesuaian tanpa isi/ID tidak ada → 404/422); `{nip}` diganti NIP target, `{satker}` id satker fixture. */
    private const MATRIKS = [
        ['get', 'api/v1/presensi/tukin/penyesuaian', 'api/v1/presensi/tukin/penyesuaian', [1], [200]],
        ['post', 'api/v1/presensi/tukin/penyesuaian', 'api/v1/presensi/tukin/penyesuaian', [1], [422]],
        ['put', 'api/v1/presensi/tukin/penyesuaian/([0-9]+)', 'api/v1/presensi/tukin/penyesuaian/999999', [1], [404, 422]],
        ['delete', 'api/v1/presensi/tukin/penyesuaian/([0-9]+)', 'api/v1/presensi/tukin/penyesuaian/999999', [1], [404]],
        ['get', 'api/v1/presensi/rekap-kehadiran', 'api/v1/presensi/rekap-kehadiran?bulan=2026-09', [1, 3, 4], [200]],
        ['get', 'api/v1/presensi/rekap-kehadiran/xlsx', 'api/v1/presensi/rekap-kehadiran/xlsx?bulan=2026-09', [1, 3, 4], [200]],
        ['get', 'api/v1/presensi/rekap-kehadiran/xlsx-harian', 'api/v1/presensi/rekap-kehadiran/xlsx-harian?bulan=2026-09', [1, 3, 4], [200]],
        ['get', 'api/v1/presensi/uang-makan/rekap', 'api/v1/presensi/uang-makan/rekap?bulan=2026-09', [1, 3], [200]],
        ['get', 'api/v1/presensi/uang-makan/rekap/xlsx', 'api/v1/presensi/uang-makan/rekap/xlsx?bulan=2026-09', [1, 3], [200]],
        ['get', 'api/v1/presensi/uang-makan/rekap-il', 'api/v1/presensi/uang-makan/rekap-il?bulan=2026-09', [1], [200]],
        ['get', 'api/v1/presensi/uang-makan/rekap-il/xlsx', 'api/v1/presensi/uang-makan/rekap-il/xlsx?bulan=2026-09', [1], [200]],
        ['get', 'api/v1/presensi/tukin/pegawai', 'api/v1/presensi/tukin/pegawai?bulan=2026-09', [1, 3], [200]],
        ['get', 'api/v1/presensi/tukin/pegawai-il', 'api/v1/presensi/tukin/pegawai-il?bulan=2026-09', [1], [200]],
        ['get', 'api/v1/presensi/tukin/laporan/([^/]+)/pdf', 'api/v1/presensi/tukin/laporan/{nip}/pdf?bulan=2026-09', [1, 2, 3, 4, 5, 6, 7], [200]],
        ['get', 'api/v1/presensi/tukin/laporan/([^/]+)', 'api/v1/presensi/tukin/laporan/{nip}?bulan=2026-09', [1, 2, 3, 4, 5, 6, 7], [200]],
        ['get', 'api/v1/presensi/tukin/rekap', 'api/v1/presensi/tukin/rekap?bulan=2026-09', [1, 3], [200]],
        ['get', 'api/v1/presensi/tukin/rekap/xlsx', 'api/v1/presensi/tukin/rekap/xlsx?bulan=2026-09', [1, 3], [200]],
        ['get', 'api/v1/presensi/tukin/rekap/pdf', 'api/v1/presensi/tukin/rekap/pdf?bulan=2026-09&unit_satker=sat_{satker}', [1, 3], [200]],
        ['get', 'api/v1/presensi/tukin/rekap-il', 'api/v1/presensi/tukin/rekap-il?bulan=2026-09', [1], [200]],
        ['get', 'api/v1/presensi/tukin/rekap-il/xlsx', 'api/v1/presensi/tukin/rekap-il/xlsx?bulan=2026-09', [1], [200]],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanLaporanTukin();
    }

    protected function tearDown(): void
    {
        $this->bersihkanLaporanTukin();
        $this->clearAuthState();
        parent::tearDown();
    }

    public function testRouterSamaDenganMatriks(): void
    {
        /** @var RouteCollection $routes */
        $routes = Services::routes()->loadRoutes();
        $router = [];

        foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE'] as $verb) {
            foreach (array_keys($routes->getRoutes($verb)) as $from) {
                if (preg_match('#^api/v1/presensi/(tukin|rekap-kehadiran|uang-makan)(/|$)#', (string) $from) === 1) {
                    $router[] = $verb . ' ' . $from;
                    $this->assertContains('jwt', $routes->getFiltersForRoute((string) $from, $verb), "{$verb} {$from} wajib jwt");
                }
            }
        }

        $matriks = array_map(static fn (array $r): string => strtoupper($r[0]) . ' ' . $r[1], self::MATRIKS);
        sort($router);
        sort($matriks);
        $this->assertSame($matriks, $router, 'route 5B di router = matriks');
    }

    public function testMatriksRouteLengkapRole1Sampai8(): void
    {
        $satker = $this->buatSatker($this->buatUnit());
        $target = $this->pegawaiRekap($satker);

        foreach ([Role::SUPER_ADMIN, Role::PEGAWAI, Role::ADMIN_SATKER, Role::ADMIN_VIEW_ESELON1, Role::MENTERI, Role::PTT, Role::PPPK, Role::PIMPINAN] as $role) {
            $nipAkun = in_array($role, Role::UL_PEGAWAI, true) ? $this->pegawaiRekap($satker) : null;
            $akun    = $this->buatAkunTanpaNip('rk5b' . $role . bin2hex(random_bytes(3)), $role, [
                'nip'                  => $nipAkun,
                'id_satker'            => $role === Role::ADMIN_SATKER ? (string) $satker : null,
                'must_change_password' => 0,
            ]);

            foreach (self::MATRIKS as [$verb, , $url, $boleh, $harap]) {
                $url    = strtr($url, ['{nip}' => $nipAkun ?? $target, '{satker}' => (string) $satker]);
                $status = $this->asUser($akun)->withBodyFormat('json')->{$verb}($url)->response()->getStatusCode();
                $pesan  = "role {$role} {$verb} {$url}";

                if (! in_array($role, $boleh, true)) {
                    $this->assertSame(403, $status, $pesan);

                    continue;
                }

                $this->assertContains($status, $harap, $pesan);
            }
        }

        $this->clearAuthState();

        foreach (self::MATRIKS as [$verb, , $url]) {
            $url = strtr($url, ['{nip}' => $target, '{satker}' => (string) $satker]);
            $this->assertSame(401, $this->withHeaders([])->withBodyFormat('json')->{$verb}($url)->response()->getStatusCode(), "tanpa token {$verb} {$url}");
        }
    }
}
