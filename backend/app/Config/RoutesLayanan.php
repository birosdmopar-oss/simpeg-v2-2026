<?php

declare(strict_types=1);

use App\Constants\Role;
use CodeIgniter\Router\RouteCollection;

/**
 * Route Modul C — Layanan & Approval, Cuti (Fase 4, MAKE-022). Dimuat lewat Config\Routing::$routeFiles. Matriks
 * PRD Fase 4 §5.1; kontrak di app/Controllers/Api/Layanan/README.md. Filter `jwt` di level grup; `role:*` per route
 * DIGABUNG dengan jwt. Hak per baris (pemilik, AL/PyB, ketua tim, lingkup) ditegakkan di service dengan 403/404.
 * Kontrak role per route dicatat di tests/feature/KontrakMatriksRoleTest.php (MAKE-038).
 *
 * @var RouteCollection $routes
 */
$routes->group('api/v1', ['namespace' => 'App\Controllers\Api\Layanan', 'filter' => 'jwt'], static function (RouteCollection $routes): void {
    $cuti = ['filter' => Role::filter(Role::SUPER_ADMIN, Role::PEGAWAI, Role::ADMIN_SATKER, Role::PTT, Role::PPPK)];

    // Path literal didaftarkan sebelum cuti/(:num).
    $routes->get('cuti/opsi', 'CutiController::opsi', $cuti);
    $routes->post('cuti/hitung-lama', 'CutiController::hitungLama'); // semua login (jwt saja)
    $routes->get('cuti/pengingat', 'CutiController::pengingat', $cuti);

    // Kelola saldo (cuti/saldo) dan petugas approval: role 1, 3.
    $admin = ['filter' => Role::filter(Role::SUPER_ADMIN, Role::ADMIN_SATKER)];
    $routes->get('cuti/saldo', 'CutiSaldoController::index', $admin);
    $routes->post('cuti/saldo', 'CutiSaldoController::create', $admin);
    $routes->put('cuti/saldo/(:num)', 'CutiSaldoController::update/$1', $admin);
    $routes->delete('cuti/saldo/(:num)', 'CutiSaldoController::delete/$1', $admin);
    $routes->get('cuti/petugas-approval', 'CutiPetugasApprovalController::index', $admin);
    $routes->post('cuti/petugas-approval', 'CutiPetugasApprovalController::create', $admin);
    $routes->put('cuti/petugas-approval/(:num)', 'CutiPetugasApprovalController::update/$1', $admin);
    $routes->delete('cuti/petugas-approval/(:num)', 'CutiPetugasApprovalController::delete/$1', $admin);

    $routes->get('cuti', 'CutiController::index', $cuti);
    $routes->post('cuti', 'CutiController::create', $cuti);
    $routes->get('cuti/(:num)', 'CutiController::show/$1', $cuti);
    $routes->put('cuti/(:num)', 'CutiController::update/$1', $cuti);
    $routes->delete('cuti/(:num)', 'CutiController::delete/$1', $cuti);
    $routes->post('cuti/(:num)/process', 'CutiController::process/$1', $cuti);
    $routes->post('cuti/(:num)/tunda', 'CutiController::tunda/$1', $cuti);
    $routes->get('cuti/(:num)/lampiran', 'CutiController::lampiran/$1', $cuti);
    $routes->get('cuti/(:num)/surat', 'CutiController::surat/$1', $cuti);
});
