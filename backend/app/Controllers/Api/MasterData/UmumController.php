<?php

declare(strict_types=1);

namespace App\Controllers\Api\MasterData;

use App\Libraries\MasterData\MasterDefinition;

/**
 * G-07 — Master Data Umum & Wilayah. Legacy: hr/master/c_umum/* (role 1).
 *
 * GET    api/v1/master/{entity}?search=&status=&parent=&page=&per_page=
 * GET    api/v1/master/{entity}/options?parent=         (UL_ALL — dropdown, hanya entri aktif)
 * POST   api/v1/master/{entity}                          → 201
 * GET    api/v1/master/{entity}/{kode}
 * PUT    api/v1/master/{entity}/{kode}
 * PATCH  api/v1/master/{entity}/{kode}/status  { status: '1'|'2' }   (juga memulihkan entri berstatus 10)
 * PATCH  api/v1/master/{entity}/{kode}/order   { order: n }
 * DELETE api/v1/master/{entity}/{kode}                  (soft delete → status 10 'Dihapus')
 *
 * Skema legacy (DBV-001): agama/jenis-pegawai/jenis-status ber-PK AUTO_INCREMENT (kode tidak diinput); kode
 * wilayah tepat 2/4/7/10 digit. Daftar default menyembunyikan status 10; filter ?status=1|2|10.
 *
 * Dropdown berjenjang wilayah 4 level: options provinsi → kabupaten-kota?parent={id_provinsi}
 * → kecamatan?parent={id_kabupaten_kota} → kelurahan?parent={id_kecamatan}.
 * Sentinel LAIN-LAIN (99/9999/9999999/9999999999, DBV-003) = baris sistem: tidak tampil di options maupun daftar,
 * tidak bisa diubah/dihapus/menjadi induk (422); hanya bisa dibaca lewat GET {kode} dan dirujuk kolom wilayah kantor.
 *
 * DBV-003/CR-010: `kantor` (kode wilayah berjenjang + LAIN-LAIN, aturan rantai/`*_lain`/kode pos di KantorHooks, nama
 * unik global), `bidang-kursem`, `instansi-kursem` (DDL legacy, tanpa kolom *_by).
 */
class UmumController extends BaseMasterController
{
    protected array $entities = [
        'agama', 'jenis-pegawai', 'jenis-status',
        'provinsi', 'kabupaten-kota', 'kecamatan', 'kelurahan',
        'lokasi-presensi', 'aturan-lokasi-presensi',
        'kantor', 'bidang-kursem', 'instansi-kursem',
    ];

    /** Target location description is derived from target_lp, but the generic engine validates the name field first. */
    protected function input(MasterDefinition $def): array
    {
        $payload = parent::input($def);
        if ($def->key === 'aturan-lokasi-presensi' && isset($payload['target_lp']) && ! isset($payload['target_lp_desc'])) {
            $payload['target_lp_desc'] = (string) $payload['target_lp'];
        }
        return $payload;
    }
}
