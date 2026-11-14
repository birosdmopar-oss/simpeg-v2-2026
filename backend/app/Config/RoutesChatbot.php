<?php

declare(strict_types=1);

use App\Constants\Role;
use CodeIgniter\Router\RouteCollection;

/**
 * Route chatbot "Vanya" (Fase 8 E-10, MAKE-094; PRD Fase 8 v1.1 §6.5, F8-30/F8-32/F8-43). Dimuat lewat
 * Config\Routing::$routeFiles. Filter `jwt` di level grup; `role:*` per route: widget = role 2/6/7 (`Role::UL_PEGAWAI`),
 * admin = role 1. Kontrak: Controllers/Api/HaloSimpeg/Chatbot/README.md. Route spesifik (`tag/ekspor`, `qna/opsi`,
 * `qna/template`, `qna/impor`) didaftarkan sebelum `{id}`.
 *
 * @var RouteCollection $routes
 */
$routes->group('api/v1/halo-simpeg/chatbot', ['namespace' => 'App\Controllers\Api\HaloSimpeg\Chatbot', 'filter' => 'jwt'], static function (RouteCollection $routes): void {
    $widget = ['filter' => Role::filter(...Role::UL_PEGAWAI)];
    $admin  = ['filter' => Role::filter(Role::SUPER_ADMIN)];

    $routes->get('widget/kategori', 'WidgetController::kategori', $widget);
    $routes->get('widget/kategori/(:segment)/pertanyaan', 'WidgetController::pertanyaan/$1', $widget);
    $routes->get('widget/populer', 'WidgetController::populer', $widget);
    $routes->post('widget/balas', 'WidgetController::balas', $widget);
    $routes->post('widget/feedback', 'WidgetController::feedback', $widget);

    $routes->get('tag/ekspor', 'TagController::ekspor', $admin);
    $routes->get('qna/opsi', 'QnaController::opsi', $admin);
    $routes->get('qna/template', 'QnaController::template', $admin);
    $routes->post('qna/impor', 'QnaController::impor', $admin);

    foreach (['kategori' => 'KategoriController', 'tag' => 'TagController', 'qna' => 'QnaController'] as $jalur => $c) {
        $routes->get($jalur, "{$c}::index", $admin);
        $routes->post($jalur, "{$c}::create", $admin);
        $routes->get("{$jalur}/(:num)", "{$c}::show/$1", $admin);
        $routes->put("{$jalur}/(:num)", "{$c}::update/$1", $admin);
        $routes->patch("{$jalur}/(:num)/status", "{$c}::status/$1", $admin);
        $routes->delete("{$jalur}/(:num)", "{$c}::delete/$1", $admin);
    }

    $routes->get('statistik', 'StatistikController::statistik', $admin);
    $routes->get('riwayat', 'StatistikController::riwayat', $admin);
});
