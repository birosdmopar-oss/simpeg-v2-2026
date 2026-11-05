<?php

declare(strict_types=1);

use CodeIgniter\Router\RouteCollection;

/**
 * Route `services/s_esign/*` (MuDig, H-02, F6-16; Jalur B MAKE-065 B4b) — path sama dengan legacy, di luar `api/v1`, tanpa
 * `jwt`/`cors` SPA; auth klien layanan `klienLayanan:esign` (MAKE-058, ADR-036). Berkas sementara: digabung ke
 * `Config/RoutesServices.php` (milik Jalur A) oleh paket yang merge belakangan.
 *
 * @var RouteCollection $routes
 */
$routes->group('services/s_esign', ['namespace' => 'App\Controllers\Services', 'filter' => 'klienLayanan:esign'], static function (RouteCollection $routes): void {
    $routes->get('check_user', 'SEsignController::checkUser');
    $routes->post('sign_pdf', 'SEsignController::signPdf');
});
