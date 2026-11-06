<?php

declare(strict_types=1);

use CodeIgniter\Router\RouteCollection;

/**
 * Route endpoint kompatibilitas legacy `services/*` (Fase 6 Jalur A, PRD Fase 6 §3/§6.1) — berkas BERSAMA Jalur A:
 * `s_stat` (A6 MAKE-061, H-08), `s_dm` (A4 MAKE-059, F6-10), `s_esign` (B4b, F6-16). Dimuat lewat
 * `Config\Routing::$routeFiles`. Di luar `api/v1` (path legacy dipertahankan, F6-04), tanpa filter `jwt`/`cors`;
 * auth per sub-group lewat `klienLayanan:<hak>` (A3, ADR-036). Aditif: setiap paket menambah sub-group-nya sendiri; yang
 * merge belakangan menggabungkan `origin/main`.
 *
 * s_stat (klienLayanan:stat), amplop INT `{"status":1|2,"msg","data"}` HTTP 200 [K], auth gagal 401 [V2]:
 *   GET services/s_stat/rekomendasi_kp                     rekomendasi KP format SIMPEG (S_stat:21)
 *   GET services/s_stat/rekomendasi_kp_uns[?id_unit|id_satker] format MuDig (S_stat:433; Q6-37: dengan filter → data [])
 *   GET services/s_stat/rekomendasi_pensiun                (S_stat:737)
 *   GET services/s_stat/rekomendasi_kgb                    (S_stat:936)
 *   GET services/s_stat/list_tb | list_ib | list_karpeg | list_kariskarsu (S_stat:1268-1442)
 *   GET services/s_stat/berkas/{jenis}/{nip}/{id}          unduh lampiran kp|skp|jabatan|kgb (Q6-38 [V2]) → 404 seragam
 *   GET services/s_stat/berkas/{jenis}/{nip}/{id}/{kolom}  unduh berkas kolom pak|tb|ib|karpeg|kariskarsu
 *
 * s_dm (klienLayanan:dm), amplop INT, data gagal [] [K] — MAKE-059, F6-10 (`p_web_config` sengaja tidak ada):
 *   GET services/s_dm/list_unit | p_list_unit | p_list_unit_wo_kep | p_get_unit?id_unit | list_satker?id_unit | p_list_satker?id_unit
 *       | p_get_satker?id_satker
 *
 * s_esign (klienLayanan:esign, Jalur B MAKE-065 B4b, F6-16), amplop STRING `{"status":"1"|"2","msg","data"}` HTTP 200 [K]:
 *   GET|POST services/s_esign/check_user?nik= status sertifikat BSrE (S_esign:9; `msg` selalu null, bug legacy)
 *   POST services/s_esign/sign_pdf          multipart file + imageTTD → data.signed_file base64 PDF ber-TTE (S_esign:54)
 *
 * @var RouteCollection $routes
 */
$routes->group('services', ['namespace' => 'App\Controllers\Services'], static function (RouteCollection $routes): void {
    $routes->group('s_stat', ['filter' => 'klienLayanan:stat'], static function (RouteCollection $routes): void {
        $routes->get('rekomendasi_kp', 'SStatController::rekomendasiKp');
        $routes->get('rekomendasi_kp_uns', 'SStatController::rekomendasiKpUns');
        $routes->get('rekomendasi_pensiun', 'SStatController::rekomendasiPensiun');
        $routes->get('rekomendasi_kgb', 'SStatController::rekomendasiKgb');
        $routes->get('list_tb', 'SStatController::listTb');
        $routes->get('list_ib', 'SStatController::listIb');
        $routes->get('list_karpeg', 'SStatController::listKarpeg');
        $routes->get('list_kariskarsu', 'SStatController::listKariskarsu');
        $routes->get('berkas/(:segment)/(:segment)/(:num)', 'SStatController::berkasLampiran/$1/$2/$3');
        $routes->get('berkas/(:segment)/(:segment)/(:num)/(:segment)', 'SStatController::berkasKolom/$1/$2/$3/$4');
    });

    // H-07 / F6-10 [K S_dm.php] — MAKE-059.
    $routes->group('s_dm', ['filter' => 'klienLayanan:dm'], static function (RouteCollection $routes): void {
        $routes->get('list_unit', 'SDmController::listUnit');
        $routes->get('list_satker', 'SDmController::listSatker');
        $routes->get('p_list_unit', 'SDmController::pListUnit');
        $routes->get('p_list_unit_wo_kep', 'SDmController::pListUnitWoKep');
        $routes->get('p_get_unit', 'SDmController::pGetUnit');
        $routes->get('p_list_satker', 'SDmController::pListSatker');
        $routes->get('p_get_satker', 'SDmController::pGetSatker');
    });

    // H-02 / F6-16 [K S_esign.php] — MAKE-065 B4b.
    $routes->group('s_esign', ['filter' => 'klienLayanan:esign'], static function (RouteCollection $routes): void {
        $routes->match(['GET', 'POST'], 'check_user', 'SEsignController::checkUser');
        $routes->post('sign_pdf', 'SEsignController::signPdf');
    });
});
