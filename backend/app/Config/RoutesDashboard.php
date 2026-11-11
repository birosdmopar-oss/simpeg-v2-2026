<?php

declare(strict_types=1);

use App\Constants\Role;
use CodeIgniter\Router\RouteCollection;

/**
 * Route Modul F — Dashboard & News (Fase 7). Dimuat lewat Config\Routing::$routeFiles (PRD Fase 7 §3.3).
 * Paket pertama (MAKE-036/037): statistik kepegawaian F-03 dan generator dokumen F-09; kontrak di
 * app/Controllers/Api/Dashboard/README.md. Filter `jwt` di level GRUP; filter `role:*` per route DIGABUNG dengan jwt.
 * Kontrak role per route dicatat di tests/feature/KontrakMatriksRoleTest.php (MAKE-038) — Matriks v2 `hr/chart/*`,
 * `hr/document/*` = role 1, 3, 4, 5, 8; lingkup Admin Satker (3) di service lewat PegawaiScope.
 *
 * @var RouteCollection $routes
 */
$routes->group('api/v1', ['namespace' => 'App\Controllers\Api\Dashboard', 'filter' => 'jwt'], static function (RouteCollection $routes): void {
    $laporan = ['filter' => Role::filter(Role::SUPER_ADMIN, Role::ADMIN_SATKER, Role::ADMIN_VIEW_ESELON1, Role::MENTERI, Role::PIMPINAN)];

    // F-03 statistik (MAKE-036): `statistik/lingkup` didaftarkan sebelum `statistik/(:segment)`.
    $routes->get('statistik/lingkup', 'StatistikController::lingkup', $laporan);
    $routes->get('statistik/(:segment)/xlsx', 'StatistikController::xlsx/$1', $laporan);
    $routes->get('statistik/(:segment)', 'StatistikController::statistik/$1', $laporan);

    // F-09 generator dokumen (MAKE-037): duk|nominatif|pensiun|baperjakat; Baperjakat unduh lewat POST (daftar NIP).
    $routes->post('dokumen/baperjakat/pdf', 'DokumenController::baperjakatPdf', $laporan);
    $routes->post('dokumen/baperjakat/xlsx', 'DokumenController::baperjakatXlsx', $laporan);
    $routes->get('dokumen/(:segment)/pdf', 'DokumenController::pdf/$1', $laporan);
    $routes->get('dokumen/(:segment)/xlsx', 'DokumenController::xlsx/$1', $laporan);
    $routes->get('dokumen/(:segment)', 'DokumenController::daftar/$1', $laporan);

    // F-13 beranda per role (MAKE-087, Jalur D, PRD Fase 7 v2.0 §5.10): semua akun login (jwt grup); bentuk per keluarga role.
    $routes->get('dashboard/ringkasan', 'BerandaController::ringkasan');
    // Masa kerja direktur politeknik: role 1, 4 [K `dashboard_ad.php:201`].
    // Ulang tahun hari ini/besok (Q7-45) + foto hanya untuk NIP di daftar itu (Q-D19): semua akun login.
    $routes->get('dashboard/ulang-tahun/(:segment)/foto', 'BerandaController::fotoUlangTahun/$1');
    $routes->get('dashboard/ulang-tahun', 'BerandaController::ulangTahun');
    $routes->get('dashboard/direktur', 'BerandaController::direktur', ['filter' => Role::filter(Role::SUPER_ADMIN, Role::ADMIN_VIEW_ESELON1)]);

    // F-06 form digital (MAKE-083, Jalur B, PRD Fase 7 v2.0 §5.7): sisi pegawai role 2/6/7 [K `MY_Controller.php:246`].
    $pegawaiForm = ['filter' => Role::filter(Role::PEGAWAI, Role::PTT, Role::PPPK)];
    $routes->get('form-digital/saya', 'FormDigitalController::saya', $pegawaiForm);
    $routes->post('form-digital/(:num)/isi', 'FormDigitalController::isi/$1', $pegawaiForm);
    // Kelola role 1 (legacy hr/announcement form digital = Super Admin).
    $adminForm = ['filter' => Role::filter(Role::SUPER_ADMIN)];
    $routes->get('form-digital', 'FormDigitalController::index', $adminForm);
    $routes->post('form-digital', 'FormDigitalController::create', $adminForm);
    $routes->get('form-digital/(:num)/target', 'FormDigitalController::target/$1', $adminForm);
    $routes->patch('form-digital/(:num)/status', 'FormDigitalController::status/$1', $adminForm);
    $routes->get('form-digital/(:num)', 'FormDigitalController::show/$1', $adminForm);
    $routes->put('form-digital/(:num)', 'FormDigitalController::update/$1', $adminForm);
    $routes->delete('form-digital/(:num)', 'FormDigitalController::delete/$1', $adminForm);
});
