<?php

declare(strict_types=1);

use App\Constants\Role;
use App\Libraries\Layanan\LayananDashboardService;
use App\Libraries\Layanan\PetugasLayananService;
use CodeIgniter\Router\RouteCollection;

/**
 * Route Modul C — Dashboard Status Layanan & PIC (PRD Fase 4 §5.5, C-10, MAKE-025 M6). Dimuat lewat
 * Config\Routing::$routeFiles; berkas terpisah per milestone (pola RoutesLayananStudi.php). Filter 'jwt' di level grup;
 * `Role::filter(...)` per route = kolom "Route" PRD §5.5 (role 1–7, role 8 → 403). Tulis PIC hanya role 1 (403 di
 * PetugasLayananService). Dokumentasi: Controllers/Api/Layanan/README.md. Kontrak role: tests/feature/KontrakMatriksRoleTest.php.
 *
 * @var RouteCollection $routes
 */
$routes->group('api/v1', ['namespace' => 'App\Controllers\Api\Layanan', 'filter' => 'jwt'], static function (RouteCollection $routes): void {
    $lihat = ['filter' => Role::filter(...LayananDashboardService::ROLE_ROUTE)];
    $routes->get('layanan/status', 'LayananDashboardController::status', $lihat);
    // [V2] MAKE-046 ekspor Status Layanan (legacy tidak punya halaman Status Layanan); role & lingkup sama dengan daftar.
    $routes->get('layanan/status/ekspor', 'LayananDashboardController::ekspor', $lihat);
    $routes->get('layanan/jenis', 'LayananDashboardController::jenis', $lihat);

    $pic = ['filter' => Role::filter(...PetugasLayananService::ROLE_ROUTE)];
    $routes->get('layanan/petugas', 'PetugasLayananController::index', $pic);
    // [V2] gap PRD §5.5 (disetujui Horii 27-10-2026): kandidat PIC untuk form, role 1 saja.
    $routes->get('layanan/petugas/kandidat', 'PetugasLayananController::kandidat', ['filter' => Role::filter(Role::SUPER_ADMIN)]);
    $routes->post('layanan/petugas', 'PetugasLayananController::create', $pic);
    $routes->put('layanan/petugas/(:num)', 'PetugasLayananController::update/$1', $pic);
    $routes->delete('layanan/petugas/(:num)', 'PetugasLayananController::delete/$1', $pic);
});
