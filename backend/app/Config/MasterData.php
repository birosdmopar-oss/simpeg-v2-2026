<?php

declare(strict_types=1);

namespace Config;

use App\Libraries\MasterData\FaqArticleHooks;
use App\Libraries\MasterData\MasterHooks;
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
 *     'fields'        => kolom tambahan legacy (MasterField): label, type (text/textarea/int/decimal/date/select/html/
 *                        boolean/ref), required, rules, options, hint, maxBytes (batas BYTE, mis. 255 untuk TINYTEXT),
 *                        columnType (tipe kolom int: tinyint/smallint/mediumint/int/bigint [+ ' unsigned'] → batas
 *                        nilai, bawaan 'int'), min/max (batas eksplisit int/decimal), entity + dependsOn +
 *                        checkDependsOn (tipe ref: master rujukan, field ref induknya di form, cek rantai oleh engine),
 *     'extraSearch'   => kolom tambahan yang ikut dicari (LIKE) di daftar admin,
 *     'uniqueScope'   => kolom tambahan pembentuk lingkup keunikan nama (selain induk),
 *     'auditColumns'  => kolom audit legacy yang ada di tabel (created_at, created_by, updated_at, updated_by,
 *                        deleted_at),
 *     'hooks'         => kelas App\Libraries\MasterData\MasterHooks (sanitasi / kolom turunan saat tulis),
 *     'listExclude'   => kolom yang tidak dikirim di daftar admin (mis. LONGTEXT); detail tetap mengirimnya,
 *     'publicOptions' => false bila dropdown `{key}/options` hanya untuk role 1 (default true = UL_ALL, untuk modul lain),
 *     'hiddenColumns' => kolom tabel yang tidak dikelola engine & tidak pernah dikirim di respons admin (mis. `icon`),
 *     'orderMode'     => 'shift' (bawaan: posisi tampil, entri lain bergeser) | 'manual' (nilai bisnis, mis. level
 *                        pangkat: disimpan apa adanya, tidak pernah digeser/dinomori ulang) — CR-009,
 *     'orderScope'    => field wajib pembentuk lingkup urutan selain induk (mis. diklat per jenis_diklat); wajib juga
 *                        ada di 'filters' (daftar admin disaring per lingkup),
 *     'orderColumnType' => tipe kolom `order` (bawaan 'int'; mis. 'tinyint' → urutan maksimal 127),
 *     'uniqueFields'  => field selain nama yang ber-UNIQUE di DB: ['kolom', ...] (unik global) atau
 *                        ['kolom' => ['lingkup', ...]] → duplikat = 422 pada field itu (termasuk balapan 1062),
 *     'filters'       => field yang boleh dipakai filter `?kolom=nilai` di options & daftar admin (allowlist),
 *     'statusChain'   => true = options hanya memuat entri yang SELURUH rantai induknya aktif (pola U3 FAQ),
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
     * Kolom audit legacy FAQ (DDL produksi): created_by diisi saat tambah, updated_by saat ubah (L_faq.php).
     */
    private const AUDIT_FAQ = ['created_at', 'created_by', 'updated_at', 'updated_by'];

    /**
     * Keterangan (TINYTEXT legacy) topik & sub topik FAQ — dibatasi 255 BYTE.
     */
    private const FAQ_REMARK = ['label' => 'Keterangan', 'type' => 'textarea', 'maxBytes' => 255];

    // Konstanta bantu per grup DBV (CR-009): tambahkan hanya di dalam blok grup masing-masing.
    // --- DBV-003 (konstanta) ---
    // --- /DBV-003 ---

    // --- DBV-004 (konstanta) ---
    // --- /DBV-004 ---

    // --- DBV-005 (konstanta) ---
    /**
     * Kolom audit G-06 (pola DDL legacy `diklat`, simpeg_prod.sql:449-450): tanpa created_*, updated_at/updated_by diisi
     * saat tambah maupun ubah (mode Batch 1).
     */
    private const AUDIT_G06 = ['updated_at', 'updated_by'];
    // --- /DBV-005 ---

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
     *     fields?: array<string, array{label: string, type?: string, required?: bool, rules?: string, options?: array<string|int, string>, hint?: string, maxBytes?: int, columnType?: string, min?: int|float, max?: int|float, entity?: string, dependsOn?: string, checkDependsOn?: bool}>,
     *     extraSearch?: list<string>,
     *     uniqueScope?: list<string>,
     *     auditColumns?: list<string>,
     *     hooks?: class-string<MasterHooks>,
     *     listExclude?: list<string>,
     *     publicOptions?: bool,
     *     hiddenColumns?: list<string>,
     *     orderMode?: string,
     *     orderScope?: list<string>,
     *     orderColumnType?: string,
     *     uniqueFields?: array<int|string, string|list<string>>,
     *     filters?: list<string>,
     *     statusChain?: bool
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

        // ------------------------------------------------------------------
        // G-10 — FAQ (FaqController), pilot DBV-002. Legacy: hr/faq/admin (L_faq.php), DDL simpeg_prod.sql:949-1030.
        // Kelola konten = role 1 lewat route master generik; baca & rating pegawai = api/v1/faq (FaqService).
        // Dropdown options hanya role 1 (publicOptions false, CR-003): konsumennya hanya form admin, dan options tidak
        // menyaring rantai status (U3) sehingga terbuka untuk semua role akan membocorkan judul entri tersembunyi.
        // Ikon topik (`icon`) belum dikelola dan tidak dikirim di respons admin (hiddenColumns, D6; upload ditunda).
        // ------------------------------------------------------------------
        'faq-topic' => [
            'label'         => 'Topik FAQ',
            'controller'    => 'FaqController',
            'table'         => 'faq_topic',
            'primaryKey'    => 'id_faq_topic',
            'autoIncrement' => true,
            'nameField'     => 'faq_topic',
            'nameLabel'     => 'Topik FAQ',
            'nameMaxLength' => 255,
            'parent'        => null,
            'fields'        => ['remark' => self::FAQ_REMARK],
            'auditColumns'  => self::AUDIT_FAQ,
            'publicOptions' => false,
            'hiddenColumns' => ['icon'],
        ],
        'faq-sub-topic' => [
            'label'         => 'Sub Topik FAQ',
            'controller'    => 'FaqController',
            'table'         => 'faq_sub_topic',
            'primaryKey'    => 'id_faq_sub_topic',
            'autoIncrement' => true,
            'nameField'     => 'faq_sub_topic',
            'nameLabel'     => 'Sub Topik FAQ',
            'nameMaxLength' => 255,
            'parent'        => ['field' => 'id_faq_topic', 'entity' => 'faq-topic'],
            'fields'        => ['remark' => self::FAQ_REMARK],
            'auditColumns'  => self::AUDIT_FAQ,
            'publicOptions' => false,
        ],
        'faq-article' => [
            'label'         => 'Artikel FAQ',
            'controller'    => 'FaqController',
            'table'         => 'faq_article',
            'primaryKey'    => 'id_faq_article',
            'autoIncrement' => true,
            'nameField'     => 'title',
            'nameLabel'     => 'Judul Artikel',
            'nameMaxLength' => 255,
            'parent'        => ['field' => 'id_faq_sub_topic', 'entity' => 'faq-sub-topic'],
            'fields'        => [
                // HTML (legacy CKEditor): disanitasi HTMLPurifier saat tulis oleh FaqArticleHooks, yang juga menghitung
                // content_stripped (kolom turunan untuk FULLTEXT, bukan input).
                'content' => ['label' => 'Isi Artikel', 'type' => 'html', 'required' => true],
            ],
            'extraSearch'   => ['content_stripped'],
            'auditColumns'  => self::AUDIT_FAQ,
            'hooks'         => FaqArticleHooks::class,
            'listExclude'   => ['content', 'content_stripped'],
            'publicOptions' => false,
        ],

        // ------------------------------------------------------------------
        // Blok per grup DBV (CR-009): setiap grup HANYA menambah entri di dalam bloknya sendiri (antara penanda buka &
        // tutup), dengan urutan blok yang sama di MasterDataTestTrait::masterFixtures() dan MasterDataSeeder::run(),
        // supaya cabang grup yang dikerjakan paralel tidak saling konflik saat di-rebase/merge.
        // ------------------------------------------------------------------

        // --- DBV-003 (G-07 kantor, kursem, jenis libur; G-08 hari libur) ---
        // --- /DBV-003 ---

        // --- DBV-004 (G-04 kenaikan pangkat, G-05 pendidikan) ---
        // --- /DBV-004 ---

        // --- DBV-005 (G-06 diklat, hukdis, konket, tanda jasa) ---
        // G-06 (DBV-005/CR-012 ⏳). Legacy hr/master/c_diklat|c_hukdis|c_konket|c_tj (Lm_*.php), CRUD role 1; dropdown UL_ALL
        // (riwayat B-11/B-13/B-14/B-17, presensi D-06). Skema: backend/docs/db-review/G-06-diklat-hukdis-konket-tanda-jasa-schema.md.
        // Baris ber-ID hard-coded legacy (G-06 Bagian 2.6) sengaja tidak dikunci (B6). Kolom `order` TINYINT → orderColumnType.
        'diklat' => [
            'label'         => 'Pelatihan',
            'controller'    => 'DiklatController',
            'table'         => 'diklat',
            'primaryKey'    => 'id_diklat',
            'autoIncrement' => true,
            'idMaxLength'   => 3,
            'nameField'     => 'nama_diklat',
            'nameLabel'     => 'Nama Pelatihan',
            'nameMaxLength' => 255,
            'parent'        => null,
            'fields'        => [
                // Pilihan = COMMENT kolom legacy [K] simpeg_prod.sql:445 (B1); CHECK chk_diklat_jenis_diklat 1..5.
                'jenis_diklat' => [
                    'label'    => 'Jenis Pelatihan',
                    'type'     => 'select',
                    'required' => true,
                    'options'  => [1 => 'Struktural', 2 => 'Teknis', 3 => 'Fungsional', 4 => 'Prajabatan', 5 => 'Sertifikasi'],
                ],
            ],
            // UNIQUE uq_diklat_nama (jenis_diklat, nama_diklat); urutan per jenis (Lm_diklat.php:13), daftar & dropdown bisa
            // disaring per jenis (riwayat B-11 memuat pelatihan per jenis, rwy/L_diklat.php:67).
            'uniqueScope'     => ['jenis_diklat'],
            'orderScope'      => ['jenis_diklat'],
            'filters'         => ['jenis_diklat'],
            'orderColumnType' => 'tinyint',
            'auditColumns'    => self::AUDIT_G06,
        ],
        'tingkat-hukdis' => [
            'label'         => 'Tingkat Hukuman Disiplin',
            'controller'    => 'HukdisController',
            'table'         => 'tingkat_hukdis',
            'primaryKey'    => 'id_tingkat_hukdis',
            'autoIncrement' => true,
            'nameField'     => 'tingkat_hukdis',
            'nameLabel'     => 'Tingkat Hukuman Disiplin',
            'nameMaxLength' => 100,
            'parent'        => null,
            // bobot_ipasn: skor IPASN legacy (L_user.php:1059-1070), disimpan tetapi tidak dikelola/dikirim (B5).
            'hiddenColumns'   => ['bobot_ipasn'],
            'orderColumnType' => 'tinyint',
            'auditColumns'    => self::AUDIT_G06,
        ],
        'jenis-hukdis' => [
            'label'         => 'Jenis Hukuman Disiplin',
            'controller'    => 'HukdisController',
            'table'         => 'jenis_hukdis',
            'primaryKey'    => 'id_jenis_hukdis',
            'autoIncrement' => true,
            'nameField'     => 'jenis_hukdis',
            'nameLabel'     => 'Jenis Hukuman Disiplin',
            'nameMaxLength' => 255,
            'parent'        => ['field' => 'id_tingkat_hukdis', 'entity' => 'tingkat-hukdis'],
            'fields'        => [
                // K5b: nullable, TINYINT UNSIGNED + CHECK >= 1 → 1..255; kosong = NULL (tanpa masa).
                'masa_sanksi_bulan' => [
                    'label'      => 'Masa Sanksi (bulan)',
                    'type'       => 'int',
                    'columnType' => 'tinyint unsigned',
                    'min'        => 1,
                    'hint'       => 'Opsional. Nilai awal hitung akhir hukuman (TMT + masa); kosongkan bila tanpa masa. Riwayat tetap menyimpan masa per SK.',
                ],
            ],
            // Dropdown jenis hanya memuat jenis yang tingkatnya aktif (legacy hanya menawarkan tingkat status 1, Lm_hukdis.php:221).
            'statusChain'     => true,
            'orderColumnType' => 'tinyint',
            'auditColumns'    => self::AUDIT_G06,
        ],
        'jenis-konket' => [
            'label'         => 'Jenis Konfirmasi Ketidakhadiran',
            'controller'    => 'KonketController',
            'table'         => 'jenis_konket',
            'primaryKey'    => 'id_jenis_konket',
            'autoIncrement' => true,
            'nameField'     => 'jenis_konket',
            'nameLabel'     => 'Jenis Konfirmasi Ketidakhadiran',
            'nameMaxLength' => 255,
            'parent'        => null,
            'fields'        => [
                // B2: kode kategori = absen_ijin.kategori (value pilihan pengajuan legacy), wajib & unik (uq_jenis_konket_old_id).
                'old_id' => [
                    'label'    => 'Kode Kategori',
                    'type'     => 'int',
                    'required' => true,
                    'min'      => 1,
                    'hint'     => 'Kode yang disimpan di pengajuan konket (absen_ijin.kategori). Unik. Mengubahnya memutus pengajuan lama yang memakai kode ini.',
                ],
                // K5a: nilai awal "Pengaruh ke Tukin" pengajuan (label legacy rwy/konket/form_ad.php:112). Wajib: kosong akan
                // menjadi NULL di kolom NOT NULL (1048 → 500). Default kolom 1 tetap berlaku untuk impor/SQL.
                'affect_tukin' => [
                    'label'    => 'Pengaruh ke Tukin',
                    'type'     => 'select',
                    'required' => true,
                    'options'  => [1 => 'Ya', 2 => 'Tidak'],
                    'hint'     => 'Nilai awal saat pengajuan konket; admin tetap bisa mengubahnya per pengajuan.',
                ],
            ],
            'uniqueFields'    => ['old_id'],
            'orderColumnType' => 'tinyint',
            'auditColumns'    => self::AUDIT_G06,
        ],
        'tanda-jasa' => [
            'label'           => 'Tanda Jasa',
            'controller'      => 'TandaJasaController',
            'table'           => 'tanda_jasa',
            'primaryKey'      => 'id_tanda_jasa',
            'autoIncrement'   => true,
            'nameField'       => 'tanda_jasa',
            'nameLabel'       => 'Tanda Jasa',
            'nameMaxLength'   => 255,
            'parent'          => null,
            'orderColumnType' => 'tinyint',
            'auditColumns'    => self::AUDIT_G06,
        ],
        // --- /DBV-005 ---
    ];
}
