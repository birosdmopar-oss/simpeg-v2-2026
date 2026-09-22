<?php

declare(strict_types=1);

namespace App\Controllers\Api\MasterData;

/**
 * G-07 — Master Data Umum & Wilayah. Legacy: hr/master/c_umum/* (role 1).
 *
 * GET    api/v1/master/{entity}?search=&status=&parent=&page=&per_page=
 * GET    api/v1/master/{entity}/options?parent=         (UL_ALL — dropdown, hanya entri aktif)
 * POST   api/v1/master/{entity}                          → 201
 * GET    api/v1/master/{entity}/{kode}
 * PUT    api/v1/master/{entity}/{kode}
 * PATCH  api/v1/master/{entity}/{kode}/status  { status: '0'|'1' }
 * PATCH  api/v1/master/{entity}/{kode}/order   { order: n }
 * DELETE api/v1/master/{entity}/{kode}                  (soft delete → status '0')
 *
 * Dropdown berjenjang wilayah 4 level: options provinsi → kabupaten-kota?parent={id_provinsi}
 * → kecamatan?parent={id_kabupaten_kota} → kelurahan?parent={id_kecamatan}.
 *
 * `kantor` menyusul — menunggu DDL legacy (Trello ISSUE-003).
 */
class UmumController extends BaseMasterController
{
    protected array $entities = [
        'agama', 'jenis-pegawai', 'jenis-status',
        'provinsi', 'kabupaten-kota', 'kecamatan', 'kelurahan',
    ];
}
