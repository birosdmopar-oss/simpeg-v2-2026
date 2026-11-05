<?php

declare(strict_types=1);

use CodeIgniter\Router\RouteCollection;

/**
 * Route endpoint kompatibilitas legacy `services/*` (Fase 6 Jalur A, PRD Fase 6 §3/§6.1) — berkas BERSAMA Jalur A:
 * `s_stat` (A6 MAKE-061, H-08), `s_dm` (A4 MAKE-059, F6-10), `s_esign` (B4b, F6-16). Dimuat lewat
 * `Config\Routing::$routeFiles`. Di luar `api/v1` (path legacy dipertahankan, F6-04), tanpa filter `jwt`/`cors`;
 * auth per sub-group lewat `klienLayanan:<hak>` (A3, ADR-036): tanpa/salah Basic → 401 amplop INT dari filter.
 * Amplop bisnis lewat `App\Libraries\Integrasi\Layanan\ResponsLayanan`.
 *
 * Aditif: setiap paket hanya menambah sub-group-nya sendiri di dalam group `services`; yang merge belakangan menggabungkan
 * `origin/main`. Pola sub-group:
 *
 *   $routes->group('s_stat', ['filter' => 'klienLayanan:stat'], static function (RouteCollection $routes): void { … });
 *   $routes->group('s_dm', ['filter' => 'klienLayanan:dm'], static function (RouteCollection $routes): void { … });
 *   $routes->group('s_esign', ['filter' => 'klienLayanan:esign'], static function (RouteCollection $routes): void { … });
 *
 * @var RouteCollection $routes
 */
$routes->group('services', ['namespace' => 'App\Controllers\Services'], static function (RouteCollection $routes): void {
    // s_stat (A6 MAKE-061, klienLayanan:stat): diisi branch fase6/a-lokal/make-061-s-stat.
    // s_dm (A4 MAKE-059, klienLayanan:dm): diisi branch A4.
    // s_esign (B4b, klienLayanan:esign): diisi branch B4b.
});
