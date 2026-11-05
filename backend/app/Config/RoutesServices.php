<?php

declare(strict_types=1);

use CodeIgniter\Router\RouteCollection;

/**
 * Route Modul H Jalur A — `services/*` kompatibilitas legacy (Fase 6, PRD §3/§6.1). Dimuat lewat Config\Routing::$routeFiles.
 * BERKAS BERSAMA (aditif): satu `group('services', …)`, TANPA filter `jwt`/`cors`; sub-group per controller dengan filter per
 * hak klien (`klienLayanan:<hak>`): `s_dm` → `dm` (MAKE-059), `s_stat` → `stat` (MAKE-061), `s_esign` → `esign` (MAKE-065).
 * Yang merge belakangan menggabungkan `origin/main`.
 *
 * @var RouteCollection $routes
 */
$routes->group('services', ['namespace' => 'App\Controllers\Services'], static function (RouteCollection $routes): void {
    // H-07 / F6-10 [K S_dm.php] — MAKE-059; `p_web_config` sengaja tidak ada (F6-10).
    $routes->group('s_dm', ['filter' => 'klienLayanan:dm'], static function (RouteCollection $routes): void {
        $routes->get('list_unit', 'SDmController::listUnit');
        $routes->get('list_satker', 'SDmController::listSatker');
        $routes->get('p_list_unit', 'SDmController::pListUnit');
        $routes->get('p_list_unit_wo_kep', 'SDmController::pListUnitWoKep');
        $routes->get('p_get_unit', 'SDmController::pGetUnit');
        $routes->get('p_list_satker', 'SDmController::pListSatker');
        $routes->get('p_get_satker', 'SDmController::pGetSatker');
    });
});
