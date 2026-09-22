<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Registry master data Modul G (Fase 2) — satu sumber kebenaran untuk engine CRUD master generik
 * (App\Libraries\MasterData\MasterService), routing (Config\Routes), dan metadata form frontend (GET master/meta).
 *
 * Menambah master baru = tambah migration + 1 entri di sini (+ daftarkan key di controller grupnya).
 * Pola standar setiap master (02-MasterData.md): kolom `order` (urutan tampil) dan `status` ('1'/'0').
 *
 * Struktur entri:
 *   key (slug URL) => [
 *     'label'      => nama tampilan,
 *     'controller' => controller grup di App\Controllers\Api\MasterData (sesuai "Files touched" task),
 *     'table', 'primaryKey',
 *     'idMaxLength'=> panjang kode (PK VARCHAR, diinput admin),
 *     'nameField', 'nameLabel', 'nameMaxLength',
 *     'parent'     => null | ['field' => kolom FK, 'entity' => key master induk],
 *   ]
 *
 * Keunikan nama berlaku per induk (mis. nama kecamatan unik dalam satu kabupaten/kota), termasuk entri non-aktif.
 */
class MasterData extends BaseConfig
{
    /**
     * @var array<string, array{
     *     label: string,
     *     controller: string,
     *     table: string,
     *     primaryKey: string,
     *     idMaxLength: int,
     *     nameField: string,
     *     nameLabel: string,
     *     nameMaxLength: int,
     *     parent: array{field: string, entity: string}|null
     * }>
     */
    public array $entities = [
        // ------------------------------------------------------------------
        // G-07 — Master Data Umum & Wilayah (UmumController)
        // ------------------------------------------------------------------
        'agama' => [
            'label'         => 'Agama',
            'controller'    => 'UmumController',
            'table'         => 'agama',
            'primaryKey'    => 'id_agama',
            'idMaxLength'   => 5,
            'nameField'     => 'nama_agama',
            'nameLabel'     => 'Nama Agama',
            'nameMaxLength' => 50,
            'parent'        => null,
        ],
        'jenis-pegawai' => [
            'label'         => 'Jenis Pegawai',
            'controller'    => 'UmumController',
            'table'         => 'jenis_pegawai',
            'primaryKey'    => 'id_jenis_pegawai',
            'idMaxLength'   => 5,
            'nameField'     => 'nama_jenis_pegawai',
            'nameLabel'     => 'Nama Jenis Pegawai',
            'nameMaxLength' => 50,
            'parent'        => null,
        ],
        'jenis-status' => [
            'label'         => 'Jenis Status Pegawai',
            'controller'    => 'UmumController',
            'table'         => 'jenis_status',
            'primaryKey'    => 'id_jenis_status',
            'idMaxLength'   => 5,
            'nameField'     => 'nama_jenis_status',
            'nameLabel'     => 'Nama Jenis Status',
            'nameMaxLength' => 50,
            'parent'        => null,
        ],
        'provinsi' => [
            'label'         => 'Provinsi',
            'controller'    => 'UmumController',
            'table'         => 'provinsi',
            'primaryKey'    => 'id_provinsi',
            'idMaxLength'   => 10,
            'nameField'     => 'nama_provinsi',
            'nameLabel'     => 'Nama Provinsi',
            'nameMaxLength' => 100,
            'parent'        => null,
        ],
        'kabupaten-kota' => [
            'label'         => 'Kabupaten/Kota',
            'controller'    => 'UmumController',
            'table'         => 'kabupaten_kota',
            'primaryKey'    => 'id_kabupaten_kota',
            'idMaxLength'   => 10,
            'nameField'     => 'nama_kabupaten_kota',
            'nameLabel'     => 'Nama Kabupaten/Kota',
            'nameMaxLength' => 100,
            'parent'        => ['field' => 'id_provinsi', 'entity' => 'provinsi'],
        ],
        'kecamatan' => [
            'label'         => 'Kecamatan',
            'controller'    => 'UmumController',
            'table'         => 'kecamatan',
            'primaryKey'    => 'id_kecamatan',
            'idMaxLength'   => 10,
            'nameField'     => 'nama_kecamatan',
            'nameLabel'     => 'Nama Kecamatan',
            'nameMaxLength' => 100,
            'parent'        => ['field' => 'id_kabupaten_kota', 'entity' => 'kabupaten-kota'],
        ],
        'kelurahan' => [
            'label'         => 'Kelurahan/Desa',
            'controller'    => 'UmumController',
            'table'         => 'kelurahan',
            'primaryKey'    => 'id_kelurahan',
            'idMaxLength'   => 10,
            'nameField'     => 'nama_kelurahan',
            'nameLabel'     => 'Nama Kelurahan/Desa',
            'nameMaxLength' => 100,
            'parent'        => ['field' => 'id_kecamatan', 'entity' => 'kecamatan'],
        ],
    ];
}
