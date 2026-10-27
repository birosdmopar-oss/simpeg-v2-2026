<?php

declare(strict_types=1);

use App\Constants\Role;
use CodeIgniter\Router\RouteCollection;

/**
 * Route Modul D — Presensi & Remunerasi paket 5A (PRD 5A §6). Dimuat lewat Config\Routing::$routeFiles. Berkas milik 5A
 * (§3.1); tiap milestone menambah blok sendiri secara aditif.
 *
 * Grup `api/v1` ber-`jwt`; filter `role:*` per route digabung dengan jwt. Kontrak role per route dicatat di
 * tests/feature/KontrakMatriksRoleTest.php (MAKE-038) dengan rujukan Matriks v2.
 *
 * @var RouteCollection $routes
 */
$routes->group('api/v1', ['namespace' => 'App\Controllers\Api\Presensi', 'filter' => 'jwt'], static function (RouteCollection $routes): void {
    // --- M5 D-02/D-03 presensi online, manual, data presensi (MAKE-032): Matriks `hr/services/local/po_in|po_out`,
    // `hr/presensi/view_presensi|list_ptt` = 2, 6, 7; `hr/presensi/list_ad|list` = 1, 3, 4, 5 (F5-15);
    // `hr/presensi/add|edit|delete` = 1 (akun khusus di service). ---
    $pegawai = ['filter' => Role::filter(Role::PEGAWAI, Role::PTT, Role::PPPK)];
    $admin   = ['filter' => Role::filter(Role::SUPER_ADMIN, Role::ADMIN_SATKER, Role::ADMIN_VIEW_ESELON1, Role::MENTERI)];
    $manual  = ['filter' => Role::filter(Role::SUPER_ADMIN)];

    $routes->get('presensi/saya/hari-ini', 'PresensiController::hariIni', $pegawai);
    $routes->get('presensi/saya', 'PresensiController::saya', $pegawai);
    $routes->post('presensi/cek-lokasi', 'PresensiController::cekLokasi', $pegawai);
    $routes->post('presensi/masuk', 'PresensiController::masuk', $pegawai);
    $routes->post('presensi/pulang', 'PresensiController::pulang', $pegawai);
    $routes->get('presensi/pegawai', 'PresensiController::pegawai', $admin);
    $routes->get('presensi/pegawai/(:segment)', 'PresensiController::pegawaiDetail/$1', $admin);
    $routes->post('presensi/manual', 'PresensiManualController::tambah', $manual);
    $routes->put('presensi/manual/(:segment)/(:segment)', 'PresensiManualController::ubah/$1/$2', $manual);
    $routes->delete('presensi/manual/(:segment)/(:segment)', 'PresensiManualController::hapus/$1/$2', $manual);
});
