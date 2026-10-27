<?php

declare(strict_types=1);

use App\Constants\Role;
use CodeIgniter\Router\RouteCollection;

/**
 * Route Modul D — Presensi & Remunerasi paket 5A (PRD 5A §6). Dimuat lewat Config\Routing::$routeFiles. Berkas milik 5A
 * (§3.1); milestone berikutnya menambah blok sendiri secara aditif.
 *
 * Grup `api/v1` ber-`jwt`; filter `role:*` per route digabung dengan jwt. Grup publik `api/v1/publik` (tanpa jwt, filter
 * `throttle`) ditambahkan di M4 (verifikasi QR, §6.3). Kontrak role per route dicatat di
 * tests/feature/KontrakMatriksRoleTest.php (MAKE-038) dengan rujukan Matriks v2 `hr/sl_gaji/*`.
 *
 * @var RouteCollection $routes
 */
$routes->group('api/v1', ['namespace' => 'App\Controllers\Api\Presensi', 'filter' => 'jwt'], static function (RouteCollection $routes): void {
    // --- M3 D-10 impor Slip Gaji (MAKE-030): Matriks `hr/sl_gaji/import_preview|import_commit` = 1, 3 (SG-2). ---
    $adminSlip = ['filter' => Role::filter(Role::SUPER_ADMIN, Role::ADMIN_SATKER)];

    $routes->post('slip-gaji/impor/pratinjau', 'SlipGajiImportController::pratinjau', $adminSlip);
    $routes->post('slip-gaji/impor/pratinjau/(:segment)/commit', 'SlipGajiImportController::commit/$1', $adminSlip);
});
