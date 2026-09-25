<?php

declare(strict_types=1);

namespace Tests\Support\Config;

use Config\MasterData;
use Tests\Support\Controllers\MasterUjiController;
use Tests\Support\Libraries\UjiJurusanHooks;

/**
 * Config\MasterData tiruan untuk test engine (CR-009): seluruh master sungguhan + master UJI di atas tabel
 * Tests\Support\Database\Migrations\CreateMasterUjiTables. Dipasang lewat MasterUjiTestTrait::useMasterUji().
 * Master UJI bukan master grup mana pun — hanya kendaraan test untuk fitur engine yang belum dipakai master sungguhan.
 */
class MasterDataUji extends MasterData
{
    public const CONTROLLER = '\\' . MasterUjiController::class;

    public function __construct()
    {
        parent::__construct();

        $this->entities += [
            // Mode urutan manual: `order` = level (TINYINT), tidak digeser/dinomori ulang. Filter options ?kategori=.
            'uji-level' => [
                'label'         => 'Level Uji',
                'controller'    => self::CONTROLLER,
                'table'         => 'uji_level',
                'primaryKey'    => 'id_level',
                'autoIncrement' => true,
                'nameField'     => 'level',
                'nameLabel'     => 'Level',
                'nameMaxLength' => 50,
                'parent'        => null,
                'fields'        => [
                    'kategori' => ['label' => 'Kategori', 'type' => 'select', 'required' => true, 'options' => [1 => 'CPNS', 2 => 'PNS']],
                ],
                'auditColumns'    => ['updated_at', 'updated_by'],
                'orderMode'       => 'manual',
                'orderColumnType' => 'tinyint',
                'filters'         => ['kategori'],
            ],
            // orderScope: urutan per jenis (pola diklat DBV-005), nama unik per jenis.
            'uji-diklat' => [
                'label'         => 'Diklat Uji',
                'controller'    => self::CONTROLLER,
                'table'         => 'uji_diklat',
                'primaryKey'    => 'id_diklat',
                'autoIncrement' => true,
                'nameField'     => 'diklat',
                'nameLabel'     => 'Nama Diklat',
                'nameMaxLength' => 100,
                'parent'        => null,
                'fields'        => [
                    'jenis' => [
                        'label'    => 'Jenis Diklat',
                        'type'     => 'select',
                        'required' => true,
                        'options'  => [1 => 'Struktural', 2 => 'Teknis', 3 => 'Fungsional', 4 => 'Prajabatan', 5 => 'Sertifikasi'],
                    ],
                ],
                'uniqueScope'     => ['jenis'],
                'auditColumns'    => ['updated_at', 'updated_by'],
                'orderScope'      => ['jenis'],
                'orderColumnType' => 'tinyint',
                'filters'         => ['jenis'],
            ],
            'uji-bidang' => [
                'label'         => 'Bidang Uji',
                'controller'    => self::CONTROLLER,
                'table'         => 'uji_bidang',
                'primaryKey'    => 'id_bidang',
                'autoIncrement' => true,
                'nameField'     => 'bidang',
                'nameLabel'     => 'Bidang',
                'nameMaxLength' => 100,
                'parent'        => null,
                'auditColumns'  => ['updated_at', 'updated_by'],
            ],
            // statusChain (dropdown ikut status bidang), uniqueFields, boolean 1/0, batas angka per tipe kolom.
            'uji-jurusan' => [
                'label'         => 'Jurusan Uji',
                'controller'    => self::CONTROLLER,
                'table'         => 'uji_jurusan',
                'primaryKey'    => 'id_jurusan',
                'autoIncrement' => true,
                'nameField'     => 'jurusan',
                'nameLabel'     => 'Jurusan',
                'nameMaxLength' => 100,
                'parent'        => ['field' => 'id_bidang', 'entity' => 'uji-bidang'],
                'fields'        => [
                    'singkat'   => ['label' => 'Singkatan', 'rules' => 'max_length[10]'],
                    'kode_lama' => ['label' => 'Kode Lama', 'type' => 'int'],
                    'flag_d3'   => ['label' => 'Jenjang D-III', 'type' => 'boolean'],
                    'flag_s1'   => ['label' => 'Jenjang S-1', 'type' => 'boolean', 'required' => true],
                    'bobot'     => ['label' => 'Bobot', 'type' => 'int', 'columnType' => 'tinyint'],
                    'kuota'     => ['label' => 'Kuota', 'type' => 'int', 'columnType' => 'int unsigned', 'min' => 1],
                ],
                'uniqueFields' => ['singkat', 'kode_lama' => ['id_bidang']],
                'auditColumns' => ['updated_at', 'updated_by'],
                // Hook uji: bisa menyisipkan baris "pemenang balapan" lewat koneksi DB kedua (test 1062 → 422).
                'hooks'       => UjiJurusanHooks::class,
                'filters'     => ['flag_d3'],
                'statusChain' => true,
            ],
            // Field ref ke master wilayah sungguhan, kabupaten/kota bergantung pada provinsi (dependsOn). id_kabupaten_lain:
            // checkDependsOn false (pola sentinel LAIN-LAIN kantor DBV-003) — rantai diserahkan ke hook, engine hanya
            // memeriksa kanonik/ada/aktif.
            'uji-kantor' => [
                'label'         => 'Kantor Uji',
                'controller'    => self::CONTROLLER,
                'table'         => 'uji_kantor',
                'primaryKey'    => 'id_kantor',
                'autoIncrement' => true,
                'nameField'     => 'kantor',
                'nameLabel'     => 'Nama Kantor',
                'nameMaxLength' => 100,
                'parent'        => null,
                'fields'        => [
                    'id_provinsi'       => ['label' => 'Provinsi', 'type' => 'ref', 'entity' => 'provinsi'],
                    'id_kabupaten'      => ['label' => 'Kabupaten/Kota', 'type' => 'ref', 'entity' => 'kabupaten-kota', 'dependsOn' => 'id_provinsi'],
                    'id_kabupaten_lain' => ['label' => 'Kabupaten/Kota Lain', 'type' => 'ref', 'entity' => 'kabupaten-kota', 'dependsOn' => 'id_provinsi', 'checkDependsOn' => false],
                ],
                'auditColumns' => ['updated_at', 'updated_by'],
                'filters'      => ['id_provinsi'],
            ],
            // statusChain bertingkat: dusun → kelurahan → kecamatan → kabupaten/kota → provinsi.
            'uji-dusun' => [
                'label'         => 'Dusun Uji',
                'controller'    => self::CONTROLLER,
                'table'         => 'uji_dusun',
                'primaryKey'    => 'id_dusun',
                'autoIncrement' => true,
                'nameField'     => 'dusun',
                'nameLabel'     => 'Dusun',
                'nameMaxLength' => 100,
                'parent'        => ['field' => 'id_kelurahan', 'entity' => 'kelurahan'],
                'auditColumns'  => ['updated_at', 'updated_by'],
                'statusChain'   => true,
            ],
        ];
    }
}
