<?php

declare(strict_types=1);

use App\Constants\Role;
use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */

// Healthcheck (F0-01): GET / harus 200.
$routes->get('/', 'Home::index');

// Preflight CORS untuk seluruh endpoint API (filter 'cors' di Config\Filters).
$routes->options('api/(:any)', static fn () => '');

// Seluruh endpoint aplikasi diversion di api/v1 (ADR-018).
$routes->group('api/v1', ['namespace' => 'App\Controllers\Api'], static function (RouteCollection $routes): void {
    $routes->get('health', 'Home::index');

    // ------------------------------------------------------------------
    // Endpoint dummy RBAC (F0-08) — HANYA untuk verifikasi filter jwt+role.
    // Tidak tersedia di production. Pola diambil dari Matriks Role x Endpoint Bagian 3.
    // ------------------------------------------------------------------
    if (ENVIRONMENT !== 'production') {
        $routes->group('_rbac', ['namespace' => 'App\Controllers\Api\Auth'], static function (RouteCollection $routes): void {
            // Siapa pun yang sudah login (UL_ALL)
            $routes->get('any', 'RbacProbeController::any', ['filter' => 'jwt']);

            // "Admin CRUD, Pegawai View" — 1,3 (Admin) + 2,4,5 (View)
            $routes->get('admin-crud', 'RbacProbeController::adminCrud', [
                'filter' => ['jwt', Role::filter(Role::SUPER_ADMIN, Role::ADMIN_SATKER)],
            ]);
            $routes->get('admin-view', 'RbacProbeController::adminView', [
                'filter' => ['jwt', Role::filter(Role::SUPER_ADMIN, Role::PEGAWAI, Role::ADMIN_SATKER, Role::ADMIN_VIEW_ESELON1, Role::MENTERI)],
            ]);

            // "Self-service submit" — 1,2,3,6,7
            $routes->post('self-service', 'RbacProbeController::selfService', [
                'filter' => ['jwt', Role::filter(Role::SUPER_ADMIN, Role::PEGAWAI, Role::ADMIN_SATKER, Role::PTT, Role::PPPK)],
            ]);

            // "View-only leadership" — 1,3,4,5,8
            $routes->get('leadership', 'RbacProbeController::leadership', [
                'filter' => ['jwt', Role::filter(Role::SUPER_ADMIN, Role::ADMIN_SATKER, Role::ADMIN_VIEW_ESELON1, Role::MENTERI, Role::PIMPINAN)],
            ]);

            // "Super Admin only" — 1
            $routes->get('super-admin', 'RbacProbeController::superAdmin', [
                'filter' => ['jwt', Role::filter(Role::SUPER_ADMIN)],
            ]);
        });
    }
});
