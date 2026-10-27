<?php

declare(strict_types=1);

use App\Constants\Role;
use CodeIgniter\Router\RouteCollection;

/**
 * Route Modul D — Presensi & Remunerasi paket 5A (PRD 5A §6). Dimuat lewat Config\Routing::$routeFiles. Berkas milik 5A
 * (§3.1); milestone berikutnya menambah blok sendiri secara aditif.
 *
 * Grup `api/v1` ber-`jwt`; filter `role:*` per route digabung dengan jwt. Grup publik `api/v1/publik` (tanpa jwt, filter
 * `throttle`) hanya untuk verifikasi QR (M4, §6.3). Kontrak role per route dicatat di
 * tests/feature/KontrakMatriksRoleTest.php (MAKE-038) dengan rujukan Matriks v2 `hr/sl_gaji/*`.
 *
 * @var RouteCollection $routes
 */
$routes->group('api/v1', ['namespace' => 'App\Controllers\Api\Presensi', 'filter' => 'jwt'], static function (RouteCollection $routes): void {
    // --- M3 D-10 impor Slip Gaji (MAKE-030): Matriks `hr/sl_gaji/import_preview|import_commit` = 1, 3 (SG-2). ---
    $adminSlip = ['filter' => Role::filter(Role::SUPER_ADMIN, Role::ADMIN_SATKER)];

    $routes->post('slip-gaji/impor/pratinjau', 'SlipGajiImportController::pratinjau', $adminSlip);
    $routes->post('slip-gaji/impor/pratinjau/(:segment)/commit', 'SlipGajiImportController::commit/$1', $adminSlip);

    // --- M4 D-11 akses Slip Gaji (MAKE-031): Matriks `hr/sl_gaji/list|mark_viewed|cetak` = 2, 6, 7 (NIP dari token);
    // `hr/sl_gaji/admin|admin_data|get_detail|edit_save|reset_access|cetak?nip=` = 1, 3 (SG-2). ---
    $pegawaiSlip = ['filter' => Role::filter(Role::PEGAWAI, Role::PTT, Role::PPPK)];

    $routes->get('slip-gaji/saya', 'SlipGajiController::daftar', $pegawaiSlip);
    $routes->get('slip-gaji/saya/(:segment)/pdf', 'SlipGajiController::pdf/$1', $pegawaiSlip);
    $routes->post('slip-gaji/saya/(:segment)/buka', 'SlipGajiController::buka/$1', $pegawaiSlip);
    $routes->get('slip-gaji/saya/(:segment)', 'SlipGajiController::status/$1', $pegawaiSlip);
    $routes->get('slip-gaji', 'SlipGajiAdminController::daftar', $adminSlip);
    $routes->get('slip-gaji/(:num)/pdf', 'SlipGajiAdminController::pdf/$1', $adminSlip);
    $routes->post('slip-gaji/(:num)/buka-ulang', 'SlipGajiAdminController::bukaUlang/$1', $adminSlip);
    $routes->get('slip-gaji/(:num)', 'SlipGajiAdminController::detail/$1', $adminSlip);
    $routes->put('slip-gaji/(:num)', 'SlipGajiAdminController::koreksi/$1', $adminSlip);

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

// --- M4 D-14 verifikasi QR publik (MAKE-031, ADR-032): SATU-SATUNYA route 5A tanpa `jwt`; filter `throttle`
// (ThrottlePublikFilter, 30 permintaan/60 detik/IP). Halaman SPA `/verify/slip/:token` memanggil endpoint ini. ---
$routes->group('api/v1/publik', ['namespace' => 'App\Controllers\Api\Presensi', 'filter' => 'throttle'], static function (RouteCollection $routes): void {
    $routes->get('verifikasi-slip/(:segment)', 'VerifikasiSlipController::verifikasi/$1');
});
