<?php

declare(strict_types=1);

use App\Constants\Role;
use App\Libraries\Layanan\Ib\IbService;
use App\Libraries\Layanan\Pmk\DasarPmkService;
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

    // C-08 Izin Belajar — role 1, 2, 3, 7 (role 6 → 403, DoD 071-3).
    $ib = ['filter' => Role::filter(...IbService::ROLE_ROUTE)];
    $routes->get('ib', 'IbController::index', $ib);
    $routes->get('ib/opsi', 'IbController::opsi', $ib);
    $routes->post('ib', 'IbController::create', $ib);
    $routes->get('ib/(:num)', 'IbController::show/$1', $ib);
    $routes->put('ib/(:num)', 'IbController::update/$1', $ib);
    $routes->delete('ib/(:num)', 'IbController::delete/$1', $ib);
    $routes->post('ib/(:num)/process', 'IbController::process/$1', $ib);
    $routes->get('ib/(:num)/lampiran/(:num)', 'IbController::lampiran/$1/$2', $ib);
    $routes->get('ib/(:num)/surat', 'IbController::surat/$1', $ib);

    // C-09 PMK — role 1, 2, 3 (role 4..8 → 403, DoD 072-4).
    $pmk = ['filter' => Role::filter(...DasarPmkService::ROLE_ROUTE)];
    $routes->get('pmk/opsi', 'PmkController::opsi', $pmk);
    $routes->post('pmk/hitung-mker', 'PmkController::hitungMker', $pmk);
    $routes->get('pmk/pengajuan', 'PmkController::indexPengajuan', $pmk);
    $routes->post('pmk/pengajuan', 'PmkController::createPengajuan', $pmk);
    $routes->get('pmk/pengajuan/(:num)', 'PmkController::showPengajuan/$1', $pmk);
    $routes->put('pmk/pengajuan/(:num)', 'PmkController::updatePengajuan/$1', $pmk);
    $routes->delete('pmk/pengajuan/(:num)', 'PmkController::deletePengajuan/$1', $pmk);
    $routes->post('pmk/pengajuan/(:num)/process', 'PmkController::processPengajuan/$1', $pmk);
    // [V2] gap PRD §5.4 (disetujui Horii 27-10-2026): unduh berkas kolom pengajuan; {kolom} di-whitelist di service.
    $routes->get('pmk/pengajuan/(:num)/berkas/(:segment)', 'PmkController::unduhBerkas/$1/$2', $pmk);
    $routes->get('pmk', 'PmkController::index', $pmk);
    $routes->put('pmk/(:num)', 'PmkController::tambahArsip/$1', $pmk);
    // [V2] gap PRD §5.4 (keputusan Horii 28-10-2026): unduh arsip SK riwayat final; legacy `list.php:70-77`.
    $routes->get('pmk/(:num)/arsip/(:num)', 'PmkController::unduhArsip/$1/$2', $pmk);
    $routes->delete('pmk/(:num)/arsip/(:num)', 'PmkController::hapusArsip/$1/$2', $pmk);
    $routes->delete('pmk/(:num)', 'PmkController::delete/$1', $pmk);
});
