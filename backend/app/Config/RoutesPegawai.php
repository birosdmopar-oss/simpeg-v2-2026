<?php

declare(strict_types=1);

use App\Constants\Role;
use CodeIgniter\Router\RouteCollection;
use Config\Arsip;

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
    // B-20 penutup (MAKE-014): ekspor & pencarian lanjutan — didaftarkan sebelum pegawai/(:segment).
    $daftar    = ['filter' => Role::filter(Role::SUPER_ADMIN, Role::ADMIN_SATKER, Role::ADMIN_VIEW_ESELON1, Role::MENTERI, Role::PIMPINAN)];
    $pencarian = ['filter' => Role::filter(Role::SUPER_ADMIN, Role::ADMIN_SATKER, Role::ADMIN_VIEW_ESELON1, Role::MENTERI)];
    $routes->get('pegawai/export', 'PegawaiController::ekspor', $daftar);
    $routes->get('pegawai/pencarian/katalog', 'PegawaiController::katalogPencarian', $pencarian);
    $routes->post('pegawai/pencarian', 'PegawaiController::pencarian', $pencarian);
    $routes->post('pegawai/pencarian/export', 'PegawaiController::eksporPencarian', $pencarian);
    $routes->get('pegawai/(:segment)', 'PegawaiController::show/$1');
    // B-05 tambah & hapus pegawai (MAKE-011, role 1).
    $routes->post('pegawai', 'PegawaiController::create', ['filter' => Role::filter(Role::SUPER_ADMIN)]);
    $routes->delete('pegawai/(:segment)', 'PegawaiController::delete/$1', ['filter' => Role::filter(Role::SUPER_ADMIN)]);
    $routes->put('pegawai/(:segment)/biodata', 'PegawaiController::updateBiodata/$1');
    $routes->get('pegawai/(:segment)/foto', 'PegawaiController::foto/$1');
    // B-20 penutup (MAKE-014): Cetak DRH PDF — hak di DrhPegawai (UL_ALL; 2/6/7 sendiri).
    $routes->get('pegawai/(:segment)/drh', 'PegawaiController::drh/$1');
    $routes->post('pegawai/(:segment)/koreksi-nip', 'PegawaiController::koreksiNip/$1', ['filter' => Role::filter(Role::SUPER_ADMIN)]);

    // B-04 approval pengajuan biodata (MAKE-011). Detail/foto: pemroses atau pemilik (service); antrean/proses role 1, 3.
    $routes->get('biodata/pengajuan', 'PegawaiController::antreanPengajuan', ['filter' => Role::filter(Role::SUPER_ADMIN, Role::ADMIN_SATKER)]);
    $routes->get('biodata/pengajuan/(:num)', 'PegawaiController::pengajuan/$1');
    $routes->get('biodata/pengajuan/(:num)/foto', 'PegawaiController::fotoPengajuan/$1');
    $routes->post('biodata/pengajuan/(:num)/process', 'PegawaiController::prosesPengajuan/$1', ['filter' => Role::filter(Role::SUPER_ADMIN, Role::ADMIN_SATKER)]);

    // B-19 struktur organisasi (MAKE-012): semua pengguna login (legacy hr/so/full = sesi, hr/rwy/jabatan/so = UL_ALL).
    $routes->get('struktur-organisasi', 'StrukturController::index');
    $routes->get('struktur-organisasi/pdf', 'StrukturController::pdf');
    // B-07 penugasan Plt/Plh (MAKE-012): {jenis} = plt|plh; hak akses & lingkup di PenugasanService (jwt saja).
    $routes->get('penugasan/(plt|plh)', 'PenugasanController::index/$1');
    $routes->post('penugasan/(plt|plh)', 'PenugasanController::create/$1');
    $routes->get('penugasan/(plt|plh)/(:num)', 'PenugasanController::show/$1/$2');
    $routes->put('penugasan/(plt|plh)/(:num)', 'PenugasanController::update/$1/$2');
    $routes->delete('penugasan/(plt|plh)/(:num)', 'PenugasanController::delete/$1/$2');
    $routes->get('penugasan/(plt|plh)/(:num)/lampiran', 'PenugasanController::unduhLampiran/$1/$2');

    // B-13 Konket (MAKE-013): hak akses & lingkup di KonketService (role 1, 2, 3, 6, 7; proses 1, 3).
    $routes->get('konket', 'KonketController::index');
    $routes->get('konket/opsi', 'KonketController::opsi');
    $routes->post('konket', 'KonketController::create');
    $routes->get('konket/(:num)', 'KonketController::show/$1');
    $routes->put('konket/(:num)', 'KonketController::update/$1');
    $routes->delete('konket/(:num)', 'KonketController::delete/$1');
    $routes->post('konket/(:num)/process', 'KonketController::process/$1');
    $routes->get('konket/(:num)/berkas', 'KonketController::berkasBukti/$1');

    // B-12b LKH (MAKE-013): role 1, 2, 3, 6, 7; verifikasi = atasan langsung (nip_atasan); hak & lingkup di LkhService.
    $routes->get('lkh', 'LkhController::index');
    $routes->get('lkh/opsi', 'LkhController::opsi');
    $routes->get('lkh/verifikasi', 'LkhController::verifikasi');
    $routes->get('lkh/rekap', 'LkhController::rekap');
    $routes->get('lkh/rekap/(:segment)', 'LkhController::rekapBawahan/$1');
    $routes->post('lkh', 'LkhController::create');
    $routes->get('lkh/(:num)', 'LkhController::show/$1');
    $routes->put('lkh/(:num)', 'LkhController::update/$1');
    $routes->delete('lkh/(:num)', 'LkhController::delete/$1');
    $routes->post('lkh/(:num)/process', 'LkhController::process/$1');
    $routes->get('lkh/(:num)/pdf', 'LkhController::pdf/$1');

    // F-10 Arsip Tahap 1 (MAKE-090, PRD F-10 §8.1): role 1–7 di route (PegawaiScope::ROLE_LIHAT_SEMUA memuat role 8),
    // lingkup lihat + kepemilikan baris di ArsipService.
    $arsip = ['filter' => Role::filter(Role::SUPER_ADMIN, Role::PEGAWAI, Role::ADMIN_SATKER, Role::ADMIN_VIEW_ESELON1, Role::MENTERI, Role::PTT, Role::PPPK)];
    $routes->get('pegawai/(:segment)/arsip', 'ArsipController::folder/$1', $arsip);
    $routes->get('pegawai/(:segment)/arsip/berkas', 'ArsipController::berkas/$1', $arsip);
    $routes->get('pegawai/(:segment)/arsip/berkas/(:num)/buka', 'ArsipController::buka/$1/$2', $arsip);
    // F-10 Arsip Tahap 2 (MAKE-092, PRD F-10 §8.1, §12.1): literal (`ekspor`, `opsi-hubungkan`) sebelum route berparameter.
    // Tulis = role 1, 2, 3, 6, 7 (+ lingkup ubah & kunci F10-31 di AksiArsip); ekspor = role 1–7 seperti daftar.
    $arsipTulis = ['filter' => Role::filter(Role::SUPER_ADMIN, Role::PEGAWAI, Role::ADMIN_SATKER, Role::PTT, Role::PPPK)];
    $routes->get('pegawai/(:segment)/arsip/ekspor', 'ArsipController::ekspor/$1', $arsip);
    $routes->get('pegawai/(:segment)/arsip/opsi-hubungkan', 'ArsipController::opsiHubungkan/$1', $arsipTulis);
    $routes->patch('pegawai/(:segment)/arsip/berkas/(:num)', 'ArsipController::ubah/$1/$2', $arsipTulis);
    $routes->post('pegawai/(:segment)/arsip/berkas/(:num)/hubungkan', 'ArsipController::hubungkan/$1/$2', $arsipTulis);
    $routes->delete('pegawai/(:segment)/arsip/berkas/(:num)', 'ArsipController::hapus/$1/$2', $arsipTulis);

    // WS-2: endpoint tambahan ditambahkan di sini.
});

// F-10 rute KHUSUS MESIN DEV (PRD F-10 §7.4): pengganti Nginx `/berkas/` untuk dua pola berkas legacy, tanpa cek izin.
// Hanya development + `arsip.sajiBerkasDev = true`; test penjaga memastikan tidak terdaftar di testing/production.
if (ENVIRONMENT === 'development' && config(Arsip::class)->sajiBerkasDev) {
    $routes->get('berkas/(:any)', 'BerkasDevController::saji/$1', ['namespace' => 'App\Controllers']);
}
