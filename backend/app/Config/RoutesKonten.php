<?php

declare(strict_types=1);

use App\Constants\Role;
use CodeIgniter\Router\RouteCollection;

/**
 * Route Modul F Jalur A — konten admin Fase 7 (MAKE-079 Popup, MAKE-080 Banner; PRD v2.0 §5.3/5.4, §6). Dimuat lewat
 * Config\Routing::$routeFiles. Filter `jwt` di level GRUP; `role:*` per route. Kelola = role 1; `aktif` = role ber-NIP
 * 2–8 (Q7-05); `gambar` = semua role login (penerima dicek di service, role 1 untuk pratinjau; selain itu 404). Kontrak
 * role di KontrakMatriksRoleTest.
 *
 * @var RouteCollection $routes
 */
$routes->group('api/v1', ['namespace' => 'App\Controllers\Api\Konten', 'filter' => 'jwt'], static function (RouteCollection $routes): void {
    $admin = ['filter' => Role::filter(Role::SUPER_ADMIN)];
    $semua = ['filter' => Role::filter(
        Role::SUPER_ADMIN,
        Role::PEGAWAI,
        Role::ADMIN_SATKER,
        Role::ADMIN_VIEW_ESELON1,
        Role::MENTERI,
        Role::PTT,
        Role::PPPK,
        Role::PIMPINAN,
    )];
    $bernip = ['filter' => Role::filter(
        Role::PEGAWAI,
        Role::ADMIN_SATKER,
        Role::ADMIN_VIEW_ESELON1,
        Role::MENTERI,
        Role::PTT,
        Role::PPPK,
        Role::PIMPINAN,
    )];

    $routes->get('konten/opsi/unit-satker', 'PopupController::opsiUnitSatker', $admin);
    $routes->get('konten/opsi/pegawai', 'PopupController::opsiPegawai', $admin);

    foreach (['popup' => 'PopupController', 'banner' => 'BannerController'] as $jalur => $c) {
        $routes->get("konten/{$jalur}/aktif", "{$c}::aktif", $bernip);
        $routes->get("konten/{$jalur}", "{$c}::index", $admin);
        $routes->post("konten/{$jalur}", "{$c}::create", $admin);
        $routes->get("konten/{$jalur}/(:num)/target", "{$c}::target/$1", $admin);
        $routes->get("konten/{$jalur}/(:num)/penerima", "{$c}::penerima/$1", $admin);
        $routes->get("konten/{$jalur}/(:num)/gambar", "{$c}::gambar/$1", $semua);
        $routes->post("konten/{$jalur}/(:num)/urutan", "{$c}::urutan/$1", $admin);
        $routes->post("konten/{$jalur}/(:num)", "{$c}::update/$1", $admin);
        $routes->delete("konten/{$jalur}/(:num)", "{$c}::delete/$1", $admin);
    }
});
