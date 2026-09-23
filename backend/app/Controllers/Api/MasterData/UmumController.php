<?php

declare(strict_types=1);

namespace App\Controllers\Api\MasterData;

/**
 * G-07 — Master Data Umum & Wilayah. Legacy: hr/master/c_umum/* (role 1).
 *
 * Endpoint generik (lihat README.md modul ini): index, show, options, create, update, setStatus, reorder, delete.
 * Dropdown berjenjang wilayah 4 level: options provinsi → kabupaten-kota?parent={id_provinsi}
 * → kecamatan?parent={id_kabupaten_kota} → kelurahan?parent={id_kecamatan}.
 */
class UmumController extends BaseMasterController
{
    protected array $entities = [
        'agama', 'jenis-pegawai', 'jenis-status', 'kantor',
        'provinsi', 'kabupaten-kota', 'kecamatan', 'kelurahan',
    ];
}
