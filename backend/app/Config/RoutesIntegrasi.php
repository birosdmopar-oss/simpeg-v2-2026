<?php

declare(strict_types=1);

use App\Constants\Role;
use CodeIgniter\Router\RouteCollection;

/**
 * Route Modul H Jalur B — endpoint v2 integrasi keluar (Fase 6, PRD §6.2; milik Jalur B, `6_1.md`). Dimuat lewat
 * Config\Routing::$routeFiles. Endpoint kompatibilitas legacy (SIMAPI, `services/*`) milik Jalur A di berkas sendiri.
 *
 * @var RouteCollection $routes
 */
$routes->group('api/v1/integrasi', ['namespace' => 'App\Controllers\Api\Integrasi', 'filter' => 'jwt'], static function (RouteCollection $routes): void {
    // H-10 (MAKE-064): tarik ulang e-Kinerja — antre job, bukan sinkron.
    $routes->post('bkn/tarik', 'BknTarikController::tarik', ['filter' => Role::filter(Role::SUPER_ADMIN)]);
});
