<?php

declare(strict_types=1);

use CodeIgniter\Router\RouteCollection;

/**
 * Route Modul B — pegawai, lampiran, biodata, koreksi NIP, struktur, Konket, LKH (milik WS-2). Dimuat lewat
 * Config\Routing::$routeFiles (S0-A MAKE-002); Routes.php tidak disentuh lagi oleh WS mana pun.
 *
 * Kontrak endpoint (beku): app/Controllers/Api/Kepegawaian/README.md bagian "Kontrak API Modul B". Endpoint nyata
 * didaftarkan WS-2 di milestone-nya di dalam grup di bawah, mis.:
 *   GET    pegawai                                   daftar (filter lingkup)    — role sesuai Matriks v2 (Role::filter)
 *   GET    pegawai/(:segment)                        detail + tabs (descriptor) — UL_ALL + PegawaiScope
 *   GET    pegawai/(:segment)/lampiran               daftar lampiran
 *   POST   pegawai/(:segment)/lampiran               unggah lampiran
 *   GET    pegawai/(:segment)/lampiran/(:num)/unduh  unduh
 *   DELETE pegawai/(:segment)/lampiran/(:num)        hapus keras + audit
 * NIP memakai (:segment), bukan (:any), agar tidak menelan path riwayat milik RoutesRiwayat.php. Selama belum
 * didaftarkan, path di atas menjawab 404 terkendali (envelope error), bukan 500.
 *
 * @var RouteCollection $routes
 */
$routes->group('api/v1', ['namespace' => 'App\Controllers\Api\Kepegawaian'], static function (RouteCollection $routes): void {
    // WS-2: endpoint pegawai/lampiran/biodata/NIP/struktur/Konket/LKH ditambahkan di sini.
});
