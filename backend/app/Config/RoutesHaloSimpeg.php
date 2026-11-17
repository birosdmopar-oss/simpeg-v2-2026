<?php

declare(strict_types=1);

use App\Constants\Role;
use CodeIgniter\Router\RouteCollection;

/**
 * Route helpdesk Halo Simpeg (Fase 8 E-02/E-07 master, MAKE-070; PRD Fase 8 v1.3 §6.1). Dimuat lewat Config\Routing::$routeFiles.
 * Chatbot punya berkas sendiri (`RoutesChatbot.php`). Filter `jwt` di level grup; `role:*` per route: baca bersama = role 1–7
 * (F8-04: role 8 tanpa akses), kelola master = role 1 (F8-05). Route literal (`kategori/opsi`, `petugas/kandidat`, `petugas/saya`,
 * `kontak/aktif`) didaftarkan sebelum `(:num)`. Kontrak: Controllers/Api/HaloSimpeg/README.md.
 *
 * @var RouteCollection $routes
 */
$routes->group('api/v1/halo-simpeg', ['namespace' => 'App\Controllers\Api\HaloSimpeg', 'filter' => 'jwt'], static function (RouteCollection $routes): void {
    $semua = ['filter' => Role::filter(1, 2, 3, 4, 5, 6, 7)];
    $admin = ['filter' => Role::filter(Role::SUPER_ADMIN)];

    $routes->get('kategori/opsi', 'KategoriController::opsi', $semua);
    $routes->get('petugas/saya', 'PetugasController::saya', $semua);
    $routes->get('petugas/kandidat', 'PetugasController::kandidat', $admin);
    $routes->get('kontak/aktif', 'KontakController::aktif', $semua);

    foreach (['kategori' => 'KategoriController', 'petugas' => 'PetugasController', 'kontak' => 'KontakController'] as $jalur => $c) {
        $routes->get($jalur, "{$c}::index", $admin);
        $routes->post($jalur, "{$c}::create", $admin);
        $routes->put("{$jalur}/(:num)", "{$c}::update/$1", $admin);
        $routes->patch("{$jalur}/(:num)/status", "{$c}::status/$1", $admin);
        $routes->delete("{$jalur}/(:num)", "{$c}::delete/$1", $admin);
    }
});
