<?php

declare(strict_types=1);

use App\Constants\Role;
use CodeIgniter\Router\RouteCollection;

/**
 * Route Modul D — Presensi paket 5B: laporan Tukin, rekap kehadiran & uang makan, penyesuaian Tukin (PRD 5B §6). Dimuat
 * lewat Config\Routing::$routeFiles. Berkas milik 5B (§2.3); milestone berikutnya (M5, M6) menambah blok sendiri secara
 * aditif. Grup `api/v1` ber-`jwt`; filter `role:*` per route digabung dengan jwt. Kontrak role per route dicatat di
 * tests/feature/KontrakMatriksRoleTest.php.
 *
 * @var RouteCollection $routes
 */
$routes->group('api/v1', ['namespace' => 'App\Controllers\Api\Presensi', 'filter' => 'jwt'], static function (RouteCollection $routes): void {
    // --- M4 F5B-14 penyesuaian Tukin per NIP (MAKE-045, DBV-024): hanya role 1 (pengganti hard-code legacy
    // `L_presensi.php:11755-11806`, tanpa route legacy). ---
    $superAdmin = ['filter' => Role::filter(Role::SUPER_ADMIN)];

    $routes->get('presensi/tukin/penyesuaian', 'PenyesuaianTukinController::index', $superAdmin);
    $routes->post('presensi/tukin/penyesuaian', 'PenyesuaianTukinController::create', $superAdmin);
    $routes->put('presensi/tukin/penyesuaian/(:num)', 'PenyesuaianTukinController::update/$1', $superAdmin);
    $routes->delete('presensi/tukin/penyesuaian/(:num)', 'PenyesuaianTukinController::delete/$1', $superAdmin);

    // --- M6 D-09b rekap kehadiran & uang makan (MAKE-042): Matriks/legacy `hr/presensi/rekap` dan `rekap_uma` =
    // `userAuth(['1','3','4'])` (`Presensi.php:740`, `:849`); v2: rekap kehadiran 1, 3, 4 (F5B-10), rekap uang makan 1, 3,
    // varian IL hanya 1 (F5B-6). Lingkup role 3 dipaksa di service (CakupanLaporan). ---
    $rekapKehadiran = ['filter' => Role::filter(Role::SUPER_ADMIN, Role::ADMIN_SATKER, Role::ADMIN_VIEW_ESELON1)];
    $rekapUangMakan = ['filter' => Role::filter(Role::SUPER_ADMIN, Role::ADMIN_SATKER)];

    $routes->get('presensi/rekap-kehadiran', 'RekapController::kehadiran', $rekapKehadiran);
    $routes->get('presensi/rekap-kehadiran/xlsx', 'RekapController::kehadiranXlsx', $rekapKehadiran);
    $routes->get('presensi/rekap-kehadiran/xlsx-harian', 'RekapController::kehadiranXlsxHarian', $rekapKehadiran);
    $routes->get('presensi/uang-makan/rekap', 'RekapController::uangMakan', $rekapUangMakan);
    $routes->get('presensi/uang-makan/rekap/xlsx', 'RekapController::uangMakanXlsx', $rekapUangMakan);
    $routes->get('presensi/uang-makan/rekap-il', 'RekapController::uangMakanIl', $superAdmin);
    $routes->get('presensi/uang-makan/rekap-il/xlsx', 'RekapController::uangMakanIlXlsx', $superAdmin);
});
