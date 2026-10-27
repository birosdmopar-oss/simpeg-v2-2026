<?php

declare(strict_types=1);

use App\Constants\Role;
use App\Libraries\Layanan\Tb\TbService;
use CodeIgniter\Router\RouteCollection;

/**
 * Route Modul C — Tugas Belajar, Izin Belajar, PMK (PRD Fase 4 §5.2–§5.4, MAKE-024 M5). Dimuat lewat
 * Config\Routing::$routeFiles. [V2] Berkas terpisah dari `RoutesLayanan.php` (cuti, M3) — satu berkas route per
 * workstream (pola Fase 3 MAKE-002), disetujui reviewer CR 27-10-2026; PRD §5 menyebut satu berkas `RoutesLayanan.php`.
 *
 * Filter 'jwt' di level grup; `Role::filter(...)` per route = kolom "Route" PRD §5 (role lain → 403). Aturan lebih rinci
 * (pemilik, PIC aktif, lingkup, status) ditegakkan di service (§7.10). Path statis (`opsi`, `perpanjangan/…`,
 * `pengajuan…`, `hitung-mker`) didaftarkan sebelum path ber-(:num). Dokumentasi: Controllers/Api/Layanan/README.md.
 * Kontrak role: tests/feature/KontrakMatriksRoleTest.php.
 *
 * @var RouteCollection $routes
 */
$routes->group('api/v1', ['namespace' => 'App\Controllers\Api\Layanan', 'filter' => 'jwt'], static function (RouteCollection $routes): void {
    // C-07 Tugas Belajar — role 1, 2, 3, 6 (role 7 → 403, DoD 070).
    $tb = ['filter' => Role::filter(...TbService::ROLE_ROUTE)];
    $routes->get('tb', 'TbController::index', $tb);
    $routes->get('tb/opsi', 'TbController::opsi', $tb);
    $routes->put('tb/perpanjangan/(:num)', 'TbController::ubahPerpanjangan/$1', $tb);
    $routes->delete('tb/perpanjangan/(:num)', 'TbController::hapusPerpanjangan/$1', $tb);
    $routes->post('tb', 'TbController::create', $tb);
    $routes->get('tb/(:num)', 'TbController::show/$1', $tb);
    $routes->put('tb/(:num)', 'TbController::update/$1', $tb);
    $routes->delete('tb/(:num)', 'TbController::delete/$1', $tb);
    $routes->post('tb/(:num)/process', 'TbController::process/$1', $tb);
    $routes->post('tb/(:num)/perpanjangan', 'TbController::tambahPerpanjangan/$1', $tb);
    $routes->get('tb/(:num)/lampiran/(:num)', 'TbController::lampiran/$1/$2', $tb);
    $routes->get('tb/(:num)/surat', 'TbController::surat/$1', $tb);
});
