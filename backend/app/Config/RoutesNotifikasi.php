<?php

declare(strict_types=1);

use App\Constants\Role;
use CodeIgniter\Router\RouteCollection;

/**
 * Route F-08 Pusat Notifikasi & Inbox (Fase 7 Jalur C, MAKE-085; PRD Fase 7 v2.0 §5.9, §6). Dimuat lewat
 * Config\Routing::$routeFiles. Filter `jwt` di level GRUP; filter `role:*` per route digabung dengan jwt.
 * Role ikut legacy (Q7-29): daftar 2,3,6,7 (`NC:12`); hapus 1,2,6,7 (`NC:120`); butuh approval & histori 1,2,3
 * (`NC:39,68`); ringkasan lonceng = semua role login (`MY_Controller:20-175`). Kontrak role di KontrakMatriksRoleTest.
 *
 * @var RouteCollection $routes
 */
$routes->group('api/v1', ['namespace' => 'App\Controllers\Api\Notifikasi', 'filter' => 'jwt'], static function (RouteCollection $routes): void {
    $daftar   = ['filter' => Role::filter(Role::PEGAWAI, Role::ADMIN_SATKER, Role::PTT, Role::PPPK)];
    $hapus    = ['filter' => Role::filter(Role::SUPER_ADMIN, Role::PEGAWAI, Role::PTT, Role::PPPK)];
    $approval = ['filter' => Role::filter(Role::SUPER_ADMIN, Role::PEGAWAI, Role::ADMIN_SATKER)];

    $routes->get('notifikasi/ringkasan', 'NotifikasiController::ringkasan');
    $routes->get('notifikasi/butuh-approval', 'NotifikasiController::butuhApproval', $approval);
    $routes->get('notifikasi/histori-approval', 'NotifikasiController::historiApproval', $approval);
    $routes->post('notifikasi/baca', 'NotifikasiController::baca', $daftar);
    $routes->get('notifikasi', 'NotifikasiController::index', $daftar);
    $routes->delete('notifikasi/(:segment)/(:segment)', 'NotifikasiController::hapus/$1/$2', $hapus);
});
