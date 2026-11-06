<?php

declare(strict_types=1);

use App\Libraries\Integrasi\Simapi\PenyajiPegawai;
use CodeIgniter\Router\RouteCollection;

/**
 * Route Modul H Jalur A — SIMAPI kompatibilitas legacy (Fase 6, PRD §6.1; MAKE-059). Dimuat lewat Config\Routing::$routeFiles.
 * Path sama dengan legacy (`simapi/v1/...`), di luar `api/v1`, TANPA filter `jwt`/`cors` SPA. Method GET dan POST [K]
 * (controller CI3 legacy tidak membatasi method). Auth: `a_auth` → `klienLayanan:simapi` (Basic), lainnya → `simapiToken`.
 *
 * @var RouteCollection $routes
 */
$routes->group('simapi/v1', ['namespace' => 'App\Controllers\Simapi'], static function (RouteCollection $routes): void {
    $routes->match(['GET', 'POST'], 'a_auth/token', 'AAuth::token', ['filter' => 'klienLayanan:simapi']);

    $routes->group('', ['filter' => 'simapiToken'], static function (RouteCollection $routes): void {
        // H-06 [K A_employee.php]: 25 method `a_employee/{method}/{nip}`; tanpa NIP → "Invalid paramater" [V2: legacy galat PHP].
        // [V2] foto khusus klien SIMAPI (keputusan Horii = A, pola Q6-38): URL `foto` di data_umum/detil menunjuk ke sini.
        $routes->get('a_employee/foto/(:segment)', 'AEmployee::foto/$1');

        // MAKE-060 (F6-06) [K AE:20-118]: ack-on-read, hanya klien type 2.
        $routes->match(['GET', 'POST'], 'a_employee/change_history', 'AEmployee::changeHistory');

        foreach (array_keys(PenyajiPegawai::METHOD) as $method) {
            $routes->match(['GET', 'POST'], 'a_employee/' . $method, 'AEmployee::sajikan/' . $method);
            $routes->match(['GET', 'POST'], 'a_employee/' . $method . '/(:segment)', 'AEmployee::sajikan/' . $method . '/$1');
        }

        // H-07 [K A_dm.php]; `jabatan` kini butuh token (F6-03, AC-07.1).
        $routes->match(['GET', 'POST'], 'a_dm/group_jabatan', 'ADm::groupJabatan');
        $routes->match(['GET', 'POST'], 'a_dm/sub_group_jabatan', 'ADm::subGroupJabatan');
        $routes->match(['GET', 'POST'], 'a_dm/jabatan', 'ADm::jabatan');
        $routes->match(['GET', 'POST'], 'a_dm/struktur_jabatan', 'ADm::strukturJabatan');

        // F6-07 [K A_sinkron.php:59]; `ambil_seluruh_pengguna` sengaja tidak ada (Q6-03 = A → 404).
        $routes->match(['GET', 'POST'], 'a_sinkron/ambil_riwayat_mutasi', 'ASinkron::ambilRiwayatMutasi');
    });
});
