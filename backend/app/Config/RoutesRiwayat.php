<?php

declare(strict_types=1);

use CodeIgniter\Router\RouteCollection;

/**
 * Route Modul B — riwayat pegawai (milik WS-1). Dimuat lewat Config\Routing::$routeFiles (S0-A MAKE-002); Routes.php
 * tidak disentuh lagi oleh WS mana pun.
 *
 * Kontrak endpoint (beku): app/Controllers/Api/Kepegawaian/README.md bagian "Kontrak API Modul B". Endpoint nyata
 * didaftarkan WS-1 mulai M1 (MAKE-004) di dalam grup di bawah, dengan pola:
 *   GET    pegawai/(:segment)/riwayat/(:segment)                 daftar
 *   GET    pegawai/(:segment)/riwayat/(:segment)/(:num)          detail
 *   POST   pegawai/(:segment)/riwayat/(:segment)                 tambah (multipart: data + berkas[<id_riwayat>])
 *   PUT    pegawai/(:segment)/riwayat/(:segment)/(:num)          ubah (JSON; dengan berkas: POST multipart + _method=PUT)
 *   DELETE pegawai/(:segment)/riwayat/(:segment)/(:num)          hapus lunak (status 10)
 *   POST   pegawai/(:segment)/riwayat/(:segment)/(:num)/process  setujui/tolak
 * Filter 'jwt' dipasang di level GRUP (default semua route di grup; filter per route digabung, bukan mengganti), jadi
 * route baru tidak pernah terbuka tanpa token. Hak per role per jenis = izin Definisi riwayat, lingkup = PegawaiScope —
 * keduanya diperiksa service (403), bukan filter `role:*`, karena role berbeda per jenis (penyimpangan §2.3.1 yang
 * disetujui reviewer CR 07-10-2026). Selama belum didaftarkan, path di atas menjawab 404 terkendali (envelope error).
 * Test penjaga: tests/Kepegawaian/RouteRiwayatKontrakTest.php.
 *
 * @var RouteCollection $routes
 */
$routes->group('api/v1', ['namespace' => 'App\Controllers\Api\Kepegawaian', 'filter' => 'jwt'], static function (RouteCollection $routes): void {
    // WS-1: endpoint riwayat ditambahkan di sini (M1, MAKE-004).
});
