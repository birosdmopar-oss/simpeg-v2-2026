<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Registry master data Modul G (Fase 2) — satu sumber kebenaran untuk engine CRUD master generik
 * (App\Libraries\MasterData\MasterService), routing (Config\Routes), dan metadata form frontend (GET master/meta).
 *
 * Menambah master baru = tambah migration + 1 entri di sini (+ daftarkan key di controller grupnya).
 * Skema mengikuti SIMPEG legacy (Mapping Migrasi Prinsip #1, keputusan user 23-09-2026 / DBV-001): nama tabel &
 * kolom legacy, status legacy 1 Aktif / 2 Tidak Aktif / 10 Dihapus, kolom audit legacy. Pola standar setiap
 * master (02-MasterData.md): kolom `order` (urutan tampil) dan `status`.
 *
 * Struktur entri:
 *   key (slug URL) => [
 *     'label'         => nama tampilan,
 *     'controller'    => controller grup di App\Controllers\Api\MasterData (sesuai "Files touched" task),
 *     'table', 'primaryKey',
 *     'autoIncrement' => true bila PK diberikan DB (kode tidak diinput admin),
 *     'idDigits'      => kode wajib tepat N digit angka (PK CHAR(N), mis. kode wilayah legacy),
 *     'idMaxLength'   => panjang maksimal kode (default = idDigits),
 *     'nameField', 'nameLabel', 'nameMaxLength',
 *     'parent'        => null | ['field' => kolom FK, 'entity' => key master induk],
 *     'fields'        => kolom tambahan legacy (MasterField): label, type, required, rules, options, hint,
 *     'uniqueScope'   => kolom tambahan pembentuk lingkup keunikan nama (selain induk),
 *     'auditColumns'  => kolom audit legacy yang ada di tabel (created_at, updated_at, updated_by, deleted_at),
 *   ]
 *
 * Keunikan nama berlaku per induk (mis. nama kecamatan unik dalam satu kabupaten/kota), termasuk entri tidak aktif
 * dan dihapus — sama dengan UNIQUE index di DB (ISSUE-009).
 */
class MasterData extends BaseConfig
{
    /**
     * Kolom audit legacy standar (wilayah, jenis_pegawai, jenis_status). agama menambah deleted_at (DDL produksi).
     */
    private const AUDIT = ['created_at', 'updated_at', 'updated_by'];

    /**
     * @var array<string, array{
     *     label: string,
     *     controller: string,
     *     table: string,
     *     primaryKey: string,
     *     idMaxLength?: int,
     *     idDigits?: int,
     *     autoIncrement?: bool,
     *     nameField: string,
     *     nameLabel: string,
     *     nameMaxLength: int,
     *     parent?: array{field: string, entity: string}|null,
     *     fields?: array<string, array{label: string, type?: string, required?: bool, rules?: string, options?: array<string|int, string>, hint?: string}>,
     *     uniqueScope?: list<string>,
     *     auditColumns?: list<string>
     * }>
     */
    public array $entities = [
        // ------------------------------------------------------------------
        // G-07 — Master Data Umum & Wilayah (UmumController). Legacy: hr/master/c_umum (Lm_umum.php).
        // ------------------------------------------------------------------
        'agama' => [
            'label'         => 'Agama',
            'controller'    => 'UmumController',
            'table'         => 'agama',
            'primaryKey'    => 'id_agama',
            'autoIncrement' => true,
            'idMaxLength'   => 3,
            'nameField'     => 'agama',
            'nameLabel'     => 'Agama',
            'nameMaxLength' => 30,
            'parent'        => null,
            'auditColumns'  => [...self::AUDIT, 'deleted_at'],
        ],
        'jenis-pegawai' => [
            'label'         => 'Jenis Pegawai',
            'controller'    => 'UmumController',
            'table'         => 'jenis_pegawai',
            'primaryKey'    => 'id_jenis_pegawai',
            'autoIncrement' => true,
            'idMaxLength'   => 3,
            'nameField'     => 'jenis_pegawai',
            'nameLabel'     => 'Jenis Pegawai',
            'nameMaxLength' => 50,
            'parent'        => null,
            'auditColumns'  => self::AUDIT,
        ],
        'jenis-status' => [
            'label'         => 'Jenis Status Pegawai',
            'controller'    => 'UmumController',
            'table'         => 'jenis_status',
            'primaryKey'    => 'id_jenis_status',
            'autoIncrement' => true,
            'idMaxLength'   => 3,
            'nameField'     => 'jenis_status',
            'nameLabel'     => 'Jenis Status',
            'nameMaxLength' => 50,
            'parent'        => null,
            'fields'        => [
                // Legacy: dropdown AKTIF/TIDAK AKTIF, wajib; mengelompokkan jenis status di form pegawai.
                'status_pegawai' => [
                    'label'    => 'Status Pegawai',
                    'type'     => 'select',
                    'required' => true,
                    'options'  => [1 => 'Aktif', 2 => 'Tidak Aktif'],
                ],
            ],
            // Legacy: jenis_status unik per status_pegawai.
            'uniqueScope'  => ['status_pegawai'],
            'auditColumns' => self::AUDIT,
        ],
        'provinsi' => [
            'label'         => 'Provinsi',
            'controller'    => 'UmumController',
            'table'         => 'provinsi',
            'primaryKey'    => 'id_provinsi',
            'idDigits'      => 2,
            'nameField'     => 'provinsi',
            'nameLabel'     => 'Nama Provinsi',
            'nameMaxLength' => 255,
            'parent'        => null,
            'auditColumns'  => self::AUDIT,
        ],
        'kabupaten-kota' => [
            'label'         => 'Kabupaten/Kota',
            'controller'    => 'UmumController',
            'table'         => 'kabupaten_kota',
            'primaryKey'    => 'id_kabupaten_kota',
            'idDigits'      => 4,
            'nameField'     => 'kabupaten_kota',
            'nameLabel'     => 'Nama Kabupaten/Kota',
            'nameMaxLength' => 255,
            'parent'        => ['field' => 'id_provinsi', 'entity' => 'provinsi'],
            'fields'        => [
                'kd_area' => ['label' => 'Kode Area', 'rules' => 'max_length[4]', 'hint' => 'Kode area telepon, maksimal 4 karakter.'],
            ],
            'auditColumns' => self::AUDIT,
        ],
        'kecamatan' => [
            'label'         => 'Kecamatan',
            'controller'    => 'UmumController',
            'table'         => 'kecamatan',
            'primaryKey'    => 'id_kecamatan',
            'idDigits'      => 7,
            'nameField'     => 'kecamatan',
            'nameLabel'     => 'Nama Kecamatan',
            'nameMaxLength' => 255,
            'parent'        => ['field' => 'id_kabupaten_kota', 'entity' => 'kabupaten-kota'],
            'auditColumns'  => self::AUDIT,
        ],
        'kelurahan' => [
            'label'         => 'Kelurahan/Desa',
            'controller'    => 'UmumController',
            'table'         => 'kelurahan',
            'primaryKey'    => 'id_kelurahan',
            'idDigits'      => 10,
            'nameField'     => 'kelurahan',
            'nameLabel'     => 'Nama Kelurahan/Desa',
            'nameMaxLength' => 255,
            'parent'        => ['field' => 'id_kecamatan', 'entity' => 'kecamatan'],
            'fields'        => [
                // Legacy: satu atau beberapa kode pos (tagsinput), disimpan dipisah koma.
                'kd_pos' => [
                    'label' => 'Kode Pos',
                    'rules' => 'max_length[50]|regex_match[/^[0-9]{5}(,[0-9]{5})*$/]',
                    'hint'  => 'Satu atau beberapa kode pos 5 digit, pisahkan dengan koma (contoh: 10110,10120).',
                ],
            ],
            'auditColumns' => self::AUDIT,
        ],
    ];
}
