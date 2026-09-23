<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Registry master data Modul G (Fase 2) — satu sumber kebenaran untuk engine CRUD master generik
 * (App\Libraries\MasterData\MasterService), routing (Config\Routes), dan metadata form frontend (GET master/meta).
 *
 * Menambah master baru = tambah migration + 1 entri di sini (+ daftarkan key di controller grupnya).
 * Pola standar setiap master (02-MasterData.md): kolom `order` (urutan tampil) dan `status` ('1' aktif/'0' non-aktif).
 *
 * Struktur entri:
 *   key (slug URL) => [
 *     'label'         => nama tampilan,
 *     'controller'    => controller grup di App\Controllers\Api\MasterData (sesuai "Files touched" task),
 *     'table', 'primaryKey',
 *     'autoIncrement' => true kalau PK AUTO_INCREMENT (kode tidak diinput admin),
 *     'idMaxLength'   => panjang kode (PK string yang diinput admin),
 *     'nameField', 'nameLabel', 'nameMaxLength',
 *     'parent'        => null | ['field' => kolom FK, 'entity' => key master induk],
 *     'hasOrder', 'hasStatus' => default true,
 *     'fields'        => kolom tambahan (MasterField): label, type, required, rules, options, hint,
 *     'extraSearch'   => kolom tambahan yang ikut dicari,
 *   ]
 *
 * Keunikan nama berlaku per induk (mis. nama kecamatan unik dalam satu kabupaten/kota), termasuk entri non-aktif.
 * Nama kolom mengikuti DDL legacy `simpeg01` (Mapping Migrasi Prinsip #1), jadi banyak master memakai nama kolom
 * yang sama dengan nama tabelnya (`jabatan`, `pangkat`, `provinsi`, …).
 */
class MasterData extends BaseConfig
{
    private const FLAG_LEGACY = [1 => 'Ya', 2 => 'Tidak'];

    /**
     * @var array<string, array<string, mixed>>
     */
    public array $entities = [
        // ------------------------------------------------------------------
        // G-02 — Master Jabatan, Unit & Satker (JabatanController)
        // ------------------------------------------------------------------
        'group-jabatan' => [
            'label'         => 'Group Jabatan',
            'controller'    => 'JabatanController',
            'table'         => 'group_jabatan',
            'primaryKey'    => 'id_group_jabatan',
            'autoIncrement' => true,
            'nameField'     => 'group_jabatan',
            'nameLabel'     => 'Nama Group Jabatan',
            'nameMaxLength' => 45,
            'parent'        => null,
            'fields'        => [
                'kode_group_jabatan'    => ['label' => 'Kode', 'rules' => 'max_length[10]'],
                'group_jabatan_singkat' => ['label' => 'Singkatan', 'rules' => 'max_length[45]'],
            ],
            'extraSearch' => ['kode_group_jabatan'],
        ],
        'sub-group-jabatan' => [
            'label'         => 'Sub Group Jabatan',
            'controller'    => 'JabatanController',
            'table'         => 'sub_group_jabatan',
            'primaryKey'    => 'id_sub_group_jabatan',
            'autoIncrement' => true,
            'nameField'     => 'sub_group_jabatan',
            'nameLabel'     => 'Nama Sub Group Jabatan',
            'nameMaxLength' => 100,
            'parent'        => ['field' => 'id_group_jabatan', 'entity' => 'group-jabatan'],
            'fields'        => [
                'kode_sub_group_jabatan'    => ['label' => 'Kode', 'rules' => 'max_length[20]'],
                'sub_group_jabatan_singkat' => ['label' => 'Singkatan', 'rules' => 'max_length[100]'],
            ],
            'extraSearch' => ['kode_sub_group_jabatan'],
        ],
        'unit' => [
            'label'         => 'Unit Kerja',
            'controller'    => 'JabatanController',
            'table'         => 'unit',
            'primaryKey'    => 'id_unit',
            'autoIncrement' => true,
            'nameField'     => 'unit',
            'nameLabel'     => 'Nama Unit Kerja',
            'nameMaxLength' => 150,
            'parent'        => null,
            'fields'        => [
                'is_upt'            => ['label' => 'UPT', 'type' => 'select', 'options' => [0 => 'Bukan UPT', 1 => 'UPT']],
                'alamat_pdf_header' => ['label' => 'Alamat (header PDF)', 'type' => 'textarea'],
                'tembusan_kppn'     => ['label' => 'Tembusan KPPN', 'rules' => 'max_length[256]'],
                'lokasi_kppn'       => ['label' => 'Lokasi KPPN', 'rules' => 'max_length[50]'],
            ],
        ],
        'satker' => [
            'label'         => 'Satuan Kerja',
            'controller'    => 'JabatanController',
            'table'         => 'satker',
            'primaryKey'    => 'id_satker',
            'autoIncrement' => true,
            'nameField'     => 'satker',
            'nameLabel'     => 'Nama Satuan Kerja',
            'nameMaxLength' => 150,
            'parent'        => ['field' => 'id_unit', 'entity' => 'unit'],
            'fields'        => [
                'zonasi'            => ['label' => 'Zonasi presensi (menit dari WIB)', 'type' => 'int', 'rules' => 'less_than_equal_to[720]', 'hint' => 'Selisih menit terhadap WIB (0 = WIB, 60 = WITA, 120 = WIT).'],
                'is_upt'            => ['label' => 'UPT', 'type' => 'select', 'options' => [0 => 'Bukan UPT', 1 => 'UPT']],
                'alamat_pdf_header' => ['label' => 'Alamat (header PDF)', 'type' => 'textarea'],
                'tembusan_kppn'     => ['label' => 'Tembusan KPPN', 'rules' => 'max_length[256]'],
                'lokasi_kppn'       => ['label' => 'Lokasi KPPN', 'rules' => 'max_length[50]'],
                'logo_uns'          => ['label' => 'Logo (path/berkas)', 'rules' => 'max_length[256]'],
            ],
        ],
        'jabatan' => [
            'label'         => 'Jabatan',
            'controller'    => 'JabatanController',
            'table'         => 'jabatan',
            'primaryKey'    => 'id_jabatan',
            'autoIncrement' => true,
            'nameField'     => 'jabatan',
            'nameLabel'     => 'Nama Jabatan',
            'nameMaxLength' => 250,
            'parent'        => ['field' => 'id_sub_group_jabatan', 'entity' => 'sub-group-jabatan'],
            'fields'        => [
                'id_group_jabatan' => ['label' => 'Group Jabatan (id)', 'type' => 'int'],
                'id_satker'        => ['label' => 'Satuan Kerja (id)', 'type' => 'int'],
                'kelas_jabatan'    => ['label' => 'Kelas Jabatan', 'type' => 'int', 'rules' => 'less_than_equal_to[255]', 'hint' => 'Master kelas jabatan menyusul (DDL legacy belum tersedia).'],
                'id_jenjang_jf'    => ['label' => 'Jenjang JF (id)', 'type' => 'int', 'rules' => 'less_than_equal_to[255]'],
                'umur_pensiun'     => ['label' => 'Batas usia pensiun (tahun)', 'type' => 'int', 'rules' => 'less_than_equal_to[100]'],
            ],
        ],

        // ------------------------------------------------------------------
        // G-03 — Master Lokasi Presensi (LokasiController)
        // ------------------------------------------------------------------
        'lokasi-presensi' => [
            'label'         => 'Lokasi Presensi',
            'controller'    => 'LokasiController',
            'table'         => 'lokasi_presensi',
            'primaryKey'    => 'id_lokasi_presensi',
            'idMaxLength'   => 5,
            'nameField'     => 'nama_lokasi',
            'nameLabel'     => 'Nama Lokasi',
            'nameMaxLength' => 150,
            'parent'        => null,
            'fields'        => [
                'latitude'     => ['label' => 'Latitude', 'type' => 'decimal', 'required' => true, 'rules' => 'greater_than_equal_to[-90]|less_than_equal_to[90]'],
                'longitude'    => ['label' => 'Longitude', 'type' => 'decimal', 'required' => true, 'rules' => 'greater_than_equal_to[-180]|less_than_equal_to[180]'],
                'radius_meter' => ['label' => 'Radius (meter)', 'type' => 'int', 'required' => true, 'rules' => 'greater_than_equal_to[10]', 'hint' => 'Minimal 10 meter (G-03).'],
            ],
        ],
        'pegawai-lokasi-presensi' => [
            'label'         => 'Pemetaan Pegawai ke Lokasi',
            'controller'    => 'LokasiController',
            'table'         => 'user_lokasi_presensi',
            'primaryKey'    => 'id_user_lokasi_presensi',
            'autoIncrement' => true,
            'nameField'     => 'nip',
            'nameLabel'     => 'NIP Pegawai',
            'nameMaxLength' => 20,
            'parent'        => ['field' => 'id_lokasi_presensi', 'entity' => 'lokasi-presensi'],
            'hasOrder'      => false,
        ],

        // ------------------------------------------------------------------
        // G-04 — Master Kenaikan Pangkat (KpController)
        // ------------------------------------------------------------------
        'pangkat' => [
            'label'         => 'Pangkat / Golongan PNS',
            'controller'    => 'KpController',
            'table'         => 'pangkat',
            'primaryKey'    => 'id_pangkat',
            'autoIncrement' => true,
            'nameField'     => 'pangkat',
            'nameLabel'     => 'Nama Pangkat',
            'nameMaxLength' => 50,
            'parent'        => null,
            'fields'        => [
                'gol_ruang' => ['label' => 'Golongan/Ruang', 'type' => 'text', 'required' => true, 'rules' => 'max_length[10]'],
                'gol'       => ['label' => 'Golongan', 'type' => 'text', 'required' => true, 'rules' => 'max_length[10]'],
                'ruang'     => ['label' => 'Ruang', 'type' => 'text', 'required' => true, 'rules' => 'max_length[10]'],
                'cpns'      => ['label' => 'Berlaku untuk CPNS', 'type' => 'select', 'options' => self::FLAG_LEGACY],
            ],
            'extraSearch' => ['gol_ruang'],
        ],
        'jenis-kp' => [
            'label'         => 'Jenis Kenaikan Pangkat',
            'controller'    => 'KpController',
            'table'         => 'jenis_kp',
            'primaryKey'    => 'id_jenis_kp',
            'autoIncrement' => true,
            'nameField'     => 'jenis_kp',
            'nameLabel'     => 'Nama Jenis KP',
            'nameMaxLength' => 100,
            'parent'        => null,
        ],
        'gol-pppk' => [
            'label'         => 'Golongan PPPK',
            'controller'    => 'KpController',
            'table'         => 'gol_pppk',
            'primaryKey'    => 'id_gol_pppk',
            'idMaxLength'   => 5,
            'nameField'     => 'nama_gol_pppk',
            'nameLabel'     => 'Nama Golongan PPPK',
            'nameMaxLength' => 50,
            'parent'        => null,
        ],

        // ------------------------------------------------------------------
        // G-05 — Master Pendidikan (PendidikanController)
        // ------------------------------------------------------------------
        'jenjang-pendidikan' => [
            'label'         => 'Jenjang Pendidikan',
            'controller'    => 'PendidikanController',
            'table'         => 'jenjang_pendidikan',
            'primaryKey'    => 'id_jenjang_pendidikan',
            'autoIncrement' => true,
            'nameField'     => 'jenjang_pendidikan',
            'nameLabel'     => 'Nama Jenjang',
            'nameMaxLength' => 60,
            'parent'        => null,
            'fields'        => [
                'jenjang_pendidikan_singkat' => ['label' => 'Singkatan', 'rules' => 'max_length[15]'],
                'row_jurusan'                => ['label' => 'Kolom jurusan terkait', 'rules' => 'max_length[10]', 'hint' => 'Legacy: D_I, D_II, D_III, D_IV, S_1, S_2, S_3.'],
            ],
        ],
        'bidang-pendidikan' => [
            'label'         => 'Bidang Pendidikan',
            'controller'    => 'PendidikanController',
            'table'         => 'bidang_pendidikan',
            'primaryKey'    => 'id_bidang_pendidikan',
            'autoIncrement' => true,
            'nameField'     => 'bidang_pendidikan',
            'nameLabel'     => 'Nama Bidang',
            'nameMaxLength' => 100,
            'parent'        => null,
            'fields'        => [
                'bidang_pendidikan_english' => ['label' => 'Nama (Inggris)', 'rules' => 'max_length[100]'],
            ],
        ],
        'jurusan-pendidikan' => [
            'label'         => 'Jurusan Pendidikan',
            'controller'    => 'PendidikanController',
            'table'         => 'jurusan_pendidikan',
            'primaryKey'    => 'id_jurusan_pendidikan',
            'autoIncrement' => true,
            'nameField'     => 'jurusan_pendidikan',
            'nameLabel'     => 'Nama Jurusan',
            'nameMaxLength' => 100,
            'parent'        => ['field' => 'id_bidang_pendidikan', 'entity' => 'bidang-pendidikan'],
            'fields'        => [
                'jurusan_pendidikan_english' => ['label' => 'Nama (Inggris)', 'rules' => 'max_length[100]'],
                'gelar'                      => ['label' => 'Gelar', 'rules' => 'max_length[20]'],
                'D_I'                        => ['label' => 'Tersedia D-I', 'type' => 'select', 'options' => self::FLAG_LEGACY],
                'D_II'                       => ['label' => 'Tersedia D-II', 'type' => 'select', 'options' => self::FLAG_LEGACY],
                'D_III'                      => ['label' => 'Tersedia D-III', 'type' => 'select', 'options' => self::FLAG_LEGACY],
                'D_IV'                       => ['label' => 'Tersedia D-IV', 'type' => 'select', 'options' => self::FLAG_LEGACY],
                'S_1'                        => ['label' => 'Tersedia S-1', 'type' => 'select', 'options' => self::FLAG_LEGACY],
                'S_2'                        => ['label' => 'Tersedia S-2', 'type' => 'select', 'options' => self::FLAG_LEGACY],
                'S_3'                        => ['label' => 'Tersedia S-3', 'type' => 'select', 'options' => self::FLAG_LEGACY],
            ],
        ],

        // ------------------------------------------------------------------
        // G-06 — Diklat, Hukdis, Konket, Tanda Jasa (4 controller terpisah)
        // ------------------------------------------------------------------
        'diklat' => [
            'label'         => 'Diklat',
            'controller'    => 'DiklatController',
            'table'         => 'diklat',
            'primaryKey'    => 'id_diklat',
            'autoIncrement' => true,
            'nameField'     => 'nama_diklat',
            'nameLabel'     => 'Nama Diklat',
            'nameMaxLength' => 255,
            'parent'        => null,
            'fields'        => [
                'jenis_diklat' => ['label' => 'Jenis Diklat', 'type' => 'select', 'required' => true, 'options' => [1 => 'Struktural', 2 => 'Teknis']],
            ],
        ],
        'tingkat-hukdis' => [
            'label'         => 'Tingkat Hukuman Disiplin',
            'controller'    => 'HukdisController',
            'table'         => 'tingkat_hukdis',
            'primaryKey'    => 'id_tingkat_hukdis',
            'autoIncrement' => true,
            'nameField'     => 'tingkat_hukdis',
            'nameLabel'     => 'Nama Tingkat',
            'nameMaxLength' => 100,
            'parent'        => null,
        ],
        'jenis-hukdis' => [
            'label'         => 'Jenis Hukuman Disiplin',
            'controller'    => 'HukdisController',
            'table'         => 'jenis_hukdis',
            'primaryKey'    => 'id_jenis_hukdis',
            'autoIncrement' => true,
            'nameField'     => 'jenis_hukdis',
            'nameLabel'     => 'Nama Jenis (pasal pelanggaran)',
            'nameMaxLength' => 255,
            'parent'        => ['field' => 'id_tingkat_hukdis', 'entity' => 'tingkat-hukdis'],
        ],
        'jenis-konket' => [
            'label'         => 'Jenis Kondisi Kerja',
            'controller'    => 'KonketController',
            'table'         => 'jenis_konket',
            'primaryKey'    => 'id_jenis_konket',
            'idMaxLength'   => 5,
            'nameField'     => 'nama_jenis_konket',
            'nameLabel'     => 'Nama Kondisi Kerja',
            'nameMaxLength' => 100,
            'parent'        => null,
            'fields'        => [
                'affect_tukin' => ['label' => 'Mempengaruhi tukin', 'type' => 'select', 'options' => [0 => 'Tidak', 1 => 'Ya'], 'hint' => 'Dipakai kalkulasi tukin (Fase 5).'],
            ],
        ],
        'tanda-jasa' => [
            'label'         => 'Tanda Jasa',
            'controller'    => 'TandaJasaController',
            'table'         => 'tanda_jasa',
            'primaryKey'    => 'id_tanda_jasa',
            'autoIncrement' => true,
            'nameField'     => 'tanda_jasa',
            'nameLabel'     => 'Nama Tanda Jasa',
            'nameMaxLength' => 255,
            'parent'        => null,
        ],

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
        'kantor' => [
            'label'         => 'Kantor',
            'controller'    => 'UmumController',
            'table'         => 'kantor',
            'primaryKey'    => 'id_kantor',
            'idMaxLength'   => 5,
            'nameField'     => 'nama_kantor',
            'nameLabel'     => 'Nama Kantor',
            'nameMaxLength' => 150,
            'parent'        => null,
            'fields'        => [
                'alamat'            => ['label' => 'Alamat', 'type' => 'textarea', 'rules' => 'max_length[255]'],
                'id_provinsi'       => ['label' => 'Provinsi (kode)', 'rules' => 'max_length[2]'],
                'id_kabupaten_kota' => ['label' => 'Kabupaten/Kota (kode)', 'rules' => 'max_length[4]'],
                'id_kecamatan'      => ['label' => 'Kecamatan (kode)', 'rules' => 'max_length[7]'],
                'id_kelurahan'      => ['label' => 'Kelurahan (kode)', 'rules' => 'max_length[10]'],
            ],
        ],
        'provinsi' => [
            'label'         => 'Provinsi',
            'controller'    => 'UmumController',
            'table'         => 'provinsi',
            'primaryKey'    => 'id_provinsi',
            'idMaxLength'   => 2,
            'nameField'     => 'provinsi',
            'nameLabel'     => 'Nama Provinsi',
            'nameMaxLength' => 255,
            'parent'        => null,
        ],
        'kabupaten-kota' => [
            'label'         => 'Kabupaten/Kota',
            'controller'    => 'UmumController',
            'table'         => 'kabupaten_kota',
            'primaryKey'    => 'id_kabupaten_kota',
            'idMaxLength'   => 4,
            'nameField'     => 'kabupaten_kota',
            'nameLabel'     => 'Nama Kabupaten/Kota',
            'nameMaxLength' => 255,
            'parent'        => ['field' => 'id_provinsi', 'entity' => 'provinsi'],
            'fields'        => [
                'kd_area' => ['label' => 'Kode Area', 'rules' => 'max_length[4]'],
            ],
        ],
        'kecamatan' => [
            'label'         => 'Kecamatan',
            'controller'    => 'UmumController',
            'table'         => 'kecamatan',
            'primaryKey'    => 'id_kecamatan',
            'idMaxLength'   => 7,
            'nameField'     => 'kecamatan',
            'nameLabel'     => 'Nama Kecamatan',
            'nameMaxLength' => 255,
            'parent'        => ['field' => 'id_kabupaten_kota', 'entity' => 'kabupaten-kota'],
        ],
        'kelurahan' => [
            'label'         => 'Kelurahan/Desa',
            'controller'    => 'UmumController',
            'table'         => 'kelurahan',
            'primaryKey'    => 'id_kelurahan',
            'idMaxLength'   => 10,
            'nameField'     => 'kelurahan',
            'nameLabel'     => 'Nama Kelurahan/Desa',
            'nameMaxLength' => 255,
            'parent'        => ['field' => 'id_kecamatan', 'entity' => 'kecamatan'],
            'fields'        => [
                'kd_pos' => ['label' => 'Kode Pos', 'rules' => 'max_length[50]'],
            ],
        ],

        // ------------------------------------------------------------------
        // G-08 — Jenis hari libur (HariLiburController; hari_libur sendiri punya service khusus)
        // ------------------------------------------------------------------
        'jenis-libur' => [
            'label'         => 'Jenis Hari Libur',
            'controller'    => 'HariLiburController',
            'table'         => 'jenis_libur',
            'primaryKey'    => 'id_jenis_libur',
            'idMaxLength'   => 5,
            'nameField'     => 'nama_jenis_libur',
            'nameLabel'     => 'Nama Jenis Libur',
            'nameMaxLength' => 100,
            'parent'        => null,
        ],

        // ------------------------------------------------------------------
        // G-10 — FAQ (FaqController; view publik & rating di controller yang sama)
        // ------------------------------------------------------------------
        'faq-topic' => [
            'label'         => 'FAQ — Topik',
            'controller'    => 'FaqController',
            'table'         => 'faq_topic',
            'primaryKey'    => 'id_topic',
            'idMaxLength'   => 5,
            'nameField'     => 'nama_topic',
            'nameLabel'     => 'Nama Topik',
            'nameMaxLength' => 150,
            'parent'        => null,
        ],
        'faq-sub-topic' => [
            'label'         => 'FAQ — Sub Topik',
            'controller'    => 'FaqController',
            'table'         => 'faq_sub_topic',
            'primaryKey'    => 'id_sub_topic',
            'idMaxLength'   => 5,
            'nameField'     => 'nama_sub_topic',
            'nameLabel'     => 'Nama Sub Topik',
            'nameMaxLength' => 150,
            'parent'        => ['field' => 'id_topic', 'entity' => 'faq-topic'],
        ],
        'faq-article' => [
            'label'         => 'FAQ — Artikel',
            'controller'    => 'FaqController',
            'table'         => 'faq_article',
            'primaryKey'    => 'id_article',
            'idMaxLength'   => 5,
            'nameField'     => 'judul',
            'nameLabel'     => 'Judul Artikel',
            'nameMaxLength' => 255,
            'parent'        => ['field' => 'id_sub_topic', 'entity' => 'faq-sub-topic'],
            'fields'        => [
                'isi' => ['label' => 'Isi Artikel', 'type' => 'textarea', 'required' => true],
            ],
            'extraSearch' => ['isi'],
        ],
    ];
}
