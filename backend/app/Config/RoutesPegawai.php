<?php

declare(strict_types=1);

use App\Constants\Role;
use CodeIgniter\Router\RouteCollection;

/**
 * Route Modul B — pegawai, lampiran, biodata, koreksi NIP, struktur, Konket, LKH (milik WS-2). Dimuat lewat
 * Config\Routing::$routeFiles (S0-A MAKE-002); Routes.php tidak disentuh lagi oleh WS mana pun.
 *
 * Kontrak endpoint (beku): app/Controllers/Api/Kepegawaian/README.md bagian "Kontrak API Modul B". Endpoint nyata
 * didaftarkan WS-2 di milestone-nya di dalam grup di bawah, mis.:
 *   GET    pegawai                                   daftar (filter lingkup)    — role sesuai Matriks v2, mis.
 *          $routes->get('pegawai', 'PegawaiController::index', ['filter' => \App\Constants\Role::filter(1, 3, 4, 5, 8)]);
 *   GET    pegawai/(:segment)                        detail + tabs (descriptor) — UL_ALL + PegawaiScope
 *   GET    pegawai/(:segment)/lampiran               daftar lampiran
 *   POST   pegawai/(:segment)/lampiran               unggah lampiran
 *   GET    pegawai/(:segment)/lampiran/(:num)/unduh  unduh
 *   DELETE pegawai/(:segment)/lampiran/(:num)        hapus keras + audit
 * Filter 'jwt' dipasang di level GRUP; filter `role:*` per route DIGABUNG dengan jwt (bukan menggantinya). Endpoint
 * lampiran memeriksa izin Definisi pemilik `id_riwayat` + PegawaiScope di service (README kontrak). NIP memakai
 * (:segment), bukan (:any), agar tidak menelan path riwayat milik RoutesRiwayat.php. Selama belum didaftarkan, path di
 * atas menjawab 404 terkendali (envelope error). Test penjaga: tests/Kepegawaian/RoutePegawaiKontrakTest.php.
 *
 * @var RouteCollection $routes
 */
$routes->group('api/v1', ['namespace' => 'App\Controllers\Api\Kepegawaian', 'filter' => 'jwt'], static function (RouteCollection $routes): void {
    // B-18 lampiran riwayat (MAKE-009): izin Definisi pemilik kode + PegawaiScope di OtorisasiLampiran (jwt saja).
    $routes->get('pegawai/(:segment)/lampiran', 'LampiranController::index/$1');
    $routes->post('pegawai/(:segment)/lampiran', 'LampiranController::create/$1');
    $routes->get('pegawai/(:segment)/lampiran/(:num)/unduh', 'LampiranController::unduh/$1/$2');
    $routes->delete('pegawai/(:segment)/lampiran/(:num)', 'LampiranController::delete/$1/$2');

    // B-20 dasar + B-03 biodata + B-06 koreksi NIP (MAKE-010). Detail/biodata/foto: izin & lingkup di service (jwt saja).
    $routes->get('pegawai', 'PegawaiController::index', ['filter' => Role::filter(Role::SUPER_ADMIN, Role::ADMIN_SATKER, Role::ADMIN_VIEW_ESELON1, Role::MENTERI, Role::PIMPINAN)]);
    $routes->get('pegawai/(:segment)', 'PegawaiController::show/$1');
    // B-05 tambah & hapus pegawai (MAKE-011, role 1).
    $routes->post('pegawai', 'PegawaiController::create', ['filter' => Role::filter(Role::SUPER_ADMIN)]);
    $routes->delete('pegawai/(:segment)', 'PegawaiController::delete/$1', ['filter' => Role::filter(Role::SUPER_ADMIN)]);
    $routes->put('pegawai/(:segment)/biodata', 'PegawaiController::updateBiodata/$1');
    $routes->get('pegawai/(:segment)/foto', 'PegawaiController::foto/$1');
    $routes->post('pegawai/(:segment)/koreksi-nip', 'PegawaiController::koreksiNip/$1', ['filter' => Role::filter(Role::SUPER_ADMIN)]);

    // B-04 approval pengajuan biodata (MAKE-011). Detail/foto: pemroses atau pemilik (service); antrean/proses role 1, 3.
    $routes->get('biodata/pengajuan', 'PegawaiController::antreanPengajuan', ['filter' => Role::filter(Role::SUPER_ADMIN, Role::ADMIN_SATKER)]);
    $routes->get('biodata/pengajuan/(:num)', 'PegawaiController::pengajuan/$1');
    $routes->get('biodata/pengajuan/(:num)/foto', 'PegawaiController::fotoPengajuan/$1');
    $routes->post('biodata/pengajuan/(:num)/process', 'PegawaiController::prosesPengajuan/$1', ['filter' => Role::filter(Role::SUPER_ADMIN, Role::ADMIN_SATKER)]);

    // B-19 struktur organisasi (MAKE-012): semua pengguna login (legacy hr/so/full = sesi, hr/rwy/jabatan/so = UL_ALL).
    $routes->get('struktur-organisasi', 'StrukturController::index');
    // B-07 penugasan Plt/Plh (MAKE-012): {jenis} = plt|plh; hak akses & lingkup di PenugasanService (jwt saja).
    $routes->get('penugasan/(plt|plh)', 'PenugasanController::index/$1');
    $routes->post('penugasan/(plt|plh)', 'PenugasanController::create/$1');
    $routes->get('penugasan/(plt|plh)/(:num)', 'PenugasanController::show/$1/$2');
    $routes->put('penugasan/(plt|plh)/(:num)', 'PenugasanController::update/$1/$2');
    $routes->delete('penugasan/(plt|plh)/(:num)', 'PenugasanController::delete/$1/$2');
    $routes->get('penugasan/(plt|plh)/(:num)/lampiran', 'PenugasanController::unduhLampiran/$1/$2');

    // WS-2: endpoint Konket/LKH ditambahkan di sini.
});
