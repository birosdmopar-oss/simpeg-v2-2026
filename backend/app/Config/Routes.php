<?php

declare(strict_types=1);

use App\Constants\Role;
use App\Libraries\MasterData\FaqService;
use CodeIgniter\Router\RouteCollection;
use Config\MasterData as MasterDataConfig;

/** @var RouteCollection $routes */

// Healthcheck (F0-01): GET / harus 200.
$routes->get('/', 'Home::index');

// Preflight CORS untuk seluruh endpoint API (filter 'cors' di Config\Filters).
$routes->options('api/(:any)', static fn () => '');

// Seluruh endpoint aplikasi diversion di api/v1 (ADR-018).
$routes->group('api/v1', ['namespace' => 'App\Controllers\Api'], static function (RouteCollection $routes): void {
    $routes->get('health', 'Home::index');

    // ------------------------------------------------------------------
    // Modul A — Autentikasi & Akun (Fase 1). Role akses: Matriks Role x Endpoint Bagian 2 Modul A.
    // Dokumentasi payload: app/Controllers/Api/Auth/README.md
    // ------------------------------------------------------------------
    $routes->group('auth', ['namespace' => 'App\Controllers\Api\Auth'], static function (RouteCollection $routes): void {
        // Publik (Guest)
        $routes->post('login', 'LoginController::login');
        $routes->post('refresh', 'TokenController::refresh');
        $routes->post('forgot-password', 'ResetPasswordController::forgot');
        $routes->post('reset-password', 'ResetPasswordController::reset');

        // UL_ALL (sudah login)
        $routes->post('logout', 'TokenController::logout', ['filter' => 'jwt']);
        $routes->get('me', 'TokenController::me', ['filter' => 'jwt']);
        $routes->post('change-password', 'PasswordController::change', ['filter' => 'jwt']);

        // user/index, add, edit, delete = role 1, 3 (scoping satker di UserService)
        $routes->group('users', ['filter' => ['jwt', Role::filter(Role::SUPER_ADMIN, Role::ADMIN_SATKER)]], static function (RouteCollection $routes): void {
            $routes->get('/', 'UserController::index');
            $routes->post('/', 'UserController::create');
            $routes->get('(:num)', 'UserController::show/$1');
            $routes->put('(:num)', 'UserController::update/$1');
            $routes->patch('(:num)/status', 'UserController::setStatus/$1');
            $routes->delete('(:num)', 'UserController::delete/$1');
        });
    });

    // ------------------------------------------------------------------
    // Modul G — Master Data & Pengaturan (Fase 2). Matriks Role x Endpoint Modul G: seluruh CRUD master = role 1.
    // Route per master dibangkitkan dari Config\MasterData; dokumentasi: app/Controllers/Api/MasterData/README.md
    // ------------------------------------------------------------------
    $routes->group('master', ['namespace' => 'App\Controllers\Api\MasterData'], static function (RouteCollection $routes): void {
        $superAdmin = ['filter' => ['jwt', Role::filter(Role::SUPER_ADMIN)]];

        $routes->get('meta', 'MetaController::index', $superAdmin);

        foreach (config(MasterDataConfig::class)->entities as $key => $entity) {
            $c = $entity['controller'];

            // Dropdown untuk modul lain: UL_ALL, read-only, hanya entri aktif. Master ber-publicOptions false (FAQ) hanya
            // role 1: dropdown-nya khusus form admin dan tidak menyaring rantai status (CR-003). Didaftarkan sebelum (:segment).
            $routes->get("{$key}/options", "{$c}::options/{$key}", ($entity['publicOptions'] ?? true) ? ['filter' => 'jwt'] : $superAdmin);

            $routes->group($key, $superAdmin, static function (RouteCollection $routes) use ($c, $key): void {
                $routes->get('/', "{$c}::index/{$key}");
                $routes->post('/', "{$c}::create/{$key}");
                $routes->get('(:segment)', "{$c}::show/{$key}/\$1");
                $routes->put('(:segment)', "{$c}::update/{$key}/\$1");
                $routes->patch('(:segment)/status', "{$c}::setStatus/{$key}/\$1");
                $routes->patch('(:segment)/order', "{$c}::reorder/{$key}/\$1");
                $routes->delete('(:segment)', "{$c}::delete/{$key}/\$1");
            });
        }
    });

    // ------------------------------------------------------------------
    // G-10 — FAQ untuk pegawai (DBV-002): baca = UL_ALL (wajib login, D8), rating = UL_PEGAWAI (2, 6, 7; U2).
    // Kelola konten: master/faq-topic|faq-sub-topic|faq-article (role 1). Tidak ada endpoint faq_related_article.
    // ------------------------------------------------------------------
    $routes->group('faq', ['namespace' => 'App\Controllers\Api\MasterData'], static function (RouteCollection $routes): void {
        $routes->get('/', 'FaqController::publicIndex', ['filter' => 'jwt']);
        $routes->get('(:segment)', 'FaqController::publicShow/$1', ['filter' => 'jwt']);
        $routes->post('(:segment)/rate', 'FaqController::rate/$1', ['filter' => ['jwt', Role::filter(...FaqService::RATER_ROLES)]]);
    });

    // ------------------------------------------------------------------
    // Endpoint dummy RBAC (F0-08) — HANYA untuk verifikasi filter jwt+role.
    // Tidak tersedia di production. Pola diambil dari Matriks Role x Endpoint Bagian 3.
    // ------------------------------------------------------------------
    if (ENVIRONMENT !== 'production') {
        $routes->group('_rbac', ['namespace' => 'App\Controllers\Api\Auth'], static function (RouteCollection $routes): void {
            // Siapa pun yang sudah login (UL_ALL)
            $routes->get('any', 'RbacProbeController::any', ['filter' => 'jwt']);

            // "Admin CRUD, Pegawai View" — 1,3 (Admin) + 2,4,5 (View)
            $routes->get('admin-crud', 'RbacProbeController::adminCrud', [
                'filter' => ['jwt', Role::filter(Role::SUPER_ADMIN, Role::ADMIN_SATKER)],
            ]);
            $routes->get('admin-view', 'RbacProbeController::adminView', [
                'filter' => ['jwt', Role::filter(Role::SUPER_ADMIN, Role::PEGAWAI, Role::ADMIN_SATKER, Role::ADMIN_VIEW_ESELON1, Role::MENTERI)],
            ]);

            // "Self-service submit" — 1,2,3,6,7
            $routes->post('self-service', 'RbacProbeController::selfService', [
                'filter' => ['jwt', Role::filter(Role::SUPER_ADMIN, Role::PEGAWAI, Role::ADMIN_SATKER, Role::PTT, Role::PPPK)],
            ]);

            // "View-only leadership" — 1,3,4,5,8
            $routes->get('leadership', 'RbacProbeController::leadership', [
                'filter' => ['jwt', Role::filter(Role::SUPER_ADMIN, Role::ADMIN_SATKER, Role::ADMIN_VIEW_ESELON1, Role::MENTERI, Role::PIMPINAN)],
            ]);

            // "Super Admin only" — 1
            $routes->get('super-admin', 'RbacProbeController::superAdmin', [
                'filter' => ['jwt', Role::filter(Role::SUPER_ADMIN)],
            ]);
        });
    }
});
