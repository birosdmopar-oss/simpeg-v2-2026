<?php

declare(strict_types=1);

namespace Tests\Presensi;

use CodeIgniter\Router\RouteCollection;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;

/**
 * PRD 5A §6, §6.3 (AC-D14.6): route modul Presensi (`slip-gaji*`, `presensi*`, `publik/*`) — satu-satunya route 5A tanpa
 * `jwt` adalah `GET api/v1/publik/verifikasi-slip/{token}` dengan filter `throttle`; semua route 5A lain ber-`jwt` dan
 * `role:*`. Route publik tidak memakai filter `role`.
 *
 * @internal
 */
final class RoutePresensiKontrakTest extends CIUnitTestCase
{
    public function testHanyaVerifikasiPublikTanpaJwt(): void
    {
        /** @var RouteCollection $routes */
        $routes    = Services::routes()->loadRoutes();
        $tanpaJwt  = [];
        $diperiksa = 0;

        foreach (['GET', 'POST', 'PUT', 'DELETE'] as $verb) {
            foreach (array_keys($routes->getRoutes($verb)) as $from) {
                $from = (string) $from;

                if (preg_match('#^api/v1/(slip-gaji|presensi|publik)(/|$)#', $from) !== 1) {
                    continue;
                }

                $diperiksa++;
                $filter = $routes->getFiltersForRoute($from, $verb);

                if (! in_array('jwt', $filter, true)) {
                    $tanpaJwt[] = "{$verb} {$from}";
                    $this->assertContains('throttle', $filter, "{$verb} {$from} publik wajib throttle");
                    $this->assertEmpty(array_filter($filter, static fn (string $f): bool => str_starts_with($f, 'role')), 'route publik tanpa role');

                    continue;
                }

                $this->assertNotEmpty(array_filter($filter, static fn (string $f): bool => str_starts_with($f, 'role:')), "{$verb} {$from} wajib role");
            }
        }

        $this->assertGreaterThan(10, $diperiksa);
        $this->assertSame(['GET api/v1/publik/verifikasi-slip/([^/]+)'], $tanpaJwt);
    }
}
