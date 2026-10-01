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
 *                        allowSystem (tipe ref: boleh merujuk baris sistem master rujukan, CR-010), otherFor (tipe
 *                        text/textarea: isian "lainnya" milik field ref ber-allowSystem, CR-010),
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
 *     'systemIds'     => kode baris sistem (CR-010, mis. sentinel LAIN-LAIN wilayah): tidak tampil di options/daftar
 *                        admin, tidak ikut urutan, tidak bisa diubah/dihapus/menjadi induk; hanya bisa dirujuk field
 *                        ref ber-allowSystem,
 *     'hasOrder'      => false bila tabel tanpa kolom `order` (bawaan true; mis. jabatan & kelas jabatan, DBV-008),
 *     'codeAsName'    => kode (PK) sekaligus nama tampilan: nameField = primaryKey, tidak bisa diubah (CR-026, mis.
 *                        kelas_jabatan — PK alami tanpa kolom nama),
 *     'idRange'       => [min, max]: kode manual berupa bilangan bulat tanpa nol di depan dalam rentang itu (CR-026),
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

    /**
     * Kolom audit `kantor`: created_by diisi saat tambah, updated_by saat ubah (Lm_umum.php:1862, 1865).
     */
    private const AUDIT_KANTOR = ['created_at', 'created_by', 'updated_at', 'updated_by'];

    /**
     * Kursem tanpa kolom *_by (DDL simpeg_prod.sql:245-252, 1435-1442): aktor hanya tercatat di audit_logs.
     */
    private const AUDIT_KURSEM = ['created_at', 'updated_at'];

    /**
     * Kode wilayah kantor: dropdown berjenjang dari master wilayah, boleh LAIN-LAIN (baris sistem). Rantai antarlevel
     * diperiksa KantorHooks (checkDependsOn false), karena level di bawah LAIN-LAIN juga LAIN-LAIN, bukan anak riil.
     */
    private const KANTOR_WILAYAH = ['type' => 'ref', 'required' => true, 'allowSystem' => true, 'checkDependsOn' => false];

    /**
     * Isian teks wilayah bila kodenya LAIN-LAIN (wajib/NULL ditegakkan KantorHooks).
     */
    private const KANTOR_LAIN = ['rules' => 'max_length[255]'];

    // --- /DBV-003 ---

    // --- DBV-004 (konstanta) ---
    /**
     * DBV-004 (D1): kolom audit pola `bidang_pendidikan` [K] (updated_at NOT NULL ON UPDATE + updated_by, tanpa
     * created_*) untuk pangkat, jenis_kp, jenjang/bidang/jurusan pendidikan. gol_pppk memakai AUDIT_FAQ (DDL [K]).
     */
    private const DBV004_AUDIT = ['updated_at', 'updated_by'];

    /**
     * DBV-004 (P6/D5): pilihan jenjang_pendidikan.row_jurusan = nama kolom flag di jurusan_pendidikan (sama dengan
     * CHECK chk_jenjang_pendidikan_row_jurusan dan JurusanPendidikanHooks::FLAGS).
     */
    private const DBV004_ROW_JURUSAN = [
        'D_I' => 'D.I', 'D_II' => 'D.II', 'D_III' => 'D.III', 'D_IV' => 'D.IV', 'S_1' => 'S.1', 'S_2' => 'S.2', 'S_3' => 'S.3',
    ];
    // --- /DBV-004 ---

    // --- DBV-005 (konstanta) ---
    /**
     * Kolom audit G-06 (pola DDL legacy `diklat`, simpeg_prod.sql:449-450): tanpa created_*, updated_at/updated_by diisi
     * saat tambah maupun ubah (mode Batch 1).
     */
    private const AUDIT_G06 = ['updated_at', 'updated_by'];
    // --- /DBV-005 ---

    // --- DBV-008 (konstanta) ---
    /**
     * DBV-008 (G-02): flag UPT unit & satker — TINYINT(1) 1/0 [K] D1:6890, :7508 + CHECK chk_*_is_upt. Legacy form: radio YA/TIDAK.
     */
    private const DBV008_IS_UPT = ['label' => 'UPT', 'type' => 'boolean', 'hint' => 'Centang bila merupakan Unit Pelaksana Teknis (UPT).'];

    /**
     * DBV-008 (G-02): kolom kop dokumen & KPPN unit/satker [K] D1 (label legacy `unit/form.php`, `satker/form.php`; legacy
     * salah memberi label "Tembusan KPPN" pada `lokasi_kppn`).
     */
    private const DBV008_TEMBUSAN_KPPN = ['label' => 'Tembusan KPPN', 'rules' => 'max_length[256]'];

    private const DBV008_LOKASI_KPPN = ['label' => 'Lokasi KPPN', 'rules' => 'max_length[50]'];
    // --- /DBV-008 ---

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
     *     fields?: array<string, array{label: string, type?: string, required?: bool, rules?: string, options?: array<string|int, string>, hint?: string, maxBytes?: int, columnType?: string, min?: int|float, max?: int|float, entity?: string, dependsOn?: string, checkDependsOn?: bool, allowSystem?: bool, otherFor?: string}>,
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
     *     statusChain?: bool,
     *     systemIds?: list<string>,
     *     hasOrder?: bool,
     *     hasStatus?: bool,
     *     codeAsName?: bool,
     *     idRange?: array{0: int, 1: int}
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
            // Kolom `order` TINYINT (DBV-001): urutan maksimal 127 (CR-010, temuan QA CR-009).
            'orderColumnType' => 'tinyint',
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
            // Kolom `order` TINYINT (DBV-001): urutan maksimal 127 (CR-010, temuan QA CR-009).
            'orderColumnType' => 'tinyint',
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
            // Kolom `order` TINYINT (DBV-001): urutan maksimal 127 (CR-010, temuan QA CR-009).
            'orderColumnType' => 'tinyint',
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
            // Kolom `order` wilayah INT UNSIGNED (CreateWilayah, tidak diubah DBV-001).
            'orderColumnType' => 'int unsigned',
            // Sentinel LAIN-LAIN legacy (DBV-003, migration 2026-09-25-100200): baris sistem, lihat 'systemIds'.
            'systemIds' => ['99'],
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
            // Kolom `order` wilayah INT UNSIGNED (CreateWilayah, tidak diubah DBV-001).
            'orderColumnType' => 'int unsigned',
            // Sentinel LAIN-LAIN (DBV-003): baris sistem.
            'systemIds' => ['9999'],
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
            // Kolom `order` wilayah INT UNSIGNED (CreateWilayah, tidak diubah DBV-001).
            'orderColumnType' => 'int unsigned',
            // Sentinel LAIN-LAIN (DBV-003): baris sistem.
            'systemIds' => ['9999999'],
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
            // Kolom `order` wilayah INT UNSIGNED (CreateWilayah, tidak diubah DBV-001).
            'orderColumnType' => 'int unsigned',
            // Sentinel LAIN-LAIN (DBV-003): baris sistem.
            'systemIds' => ['9999999999'],
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
        // G-07 kantor (legacy hr/master/c_umum, Lm_umum.php:1576-1870): kode wilayah = field ref berjenjang yang boleh
        // LAIN-LAIN (99/9999/9999999/9999999999, teks di *_lain); rantai, *_lain, dan kode pos ditegakkan KantorHooks.
        // Nama kantor unik global (uq_kantor_nama). Hari libur (G-08) bukan entri engine: HariLiburController +
        // HariLiburService (route api/v1/hari-libur); master jenis-libur memakai engine di controller yang sama.
        'kantor' => [
            'label'         => 'Kantor',
            'controller'    => 'UmumController',
            'table'         => 'kantor',
            'primaryKey'    => 'id_kantor',
            'autoIncrement' => true,
            'idMaxLength'   => 10,
            'nameField'     => 'nama_kantor',
            'nameLabel'     => 'Nama Kantor',
            'nameMaxLength' => 255,
            'parent'        => null,
            'fields'        => [
                'alamat'         => ['label' => 'Alamat', 'type' => 'textarea', 'required' => true, 'maxBytes' => 65535],
                'id_provinsi'    => ['label' => 'Provinsi', 'entity' => 'provinsi', ...self::KANTOR_WILAYAH],
                'provinsi_lain'  => ['label' => 'Provinsi Lainnya', 'otherFor' => 'id_provinsi', ...self::KANTOR_LAIN],
                'id_kabupaten'   => ['label' => 'Kabupaten/Kota', 'entity' => 'kabupaten-kota', 'dependsOn' => 'id_provinsi', ...self::KANTOR_WILAYAH],
                'kabupaten_lain' => ['label' => 'Kabupaten/Kota Lainnya', 'otherFor' => 'id_kabupaten', ...self::KANTOR_LAIN],
                'id_kecamatan'   => ['label' => 'Kecamatan', 'entity' => 'kecamatan', 'dependsOn' => 'id_kabupaten', ...self::KANTOR_WILAYAH],
                'kecamatan_lain' => ['label' => 'Kecamatan Lainnya', 'otherFor' => 'id_kecamatan', ...self::KANTOR_LAIN],
                'id_kelurahan'   => ['label' => 'Kelurahan/Desa', 'entity' => 'kelurahan', 'dependsOn' => 'id_kecamatan', ...self::KANTOR_WILAYAH],
                'kelurahan_lain' => ['label' => 'Kelurahan/Desa Lainnya', 'otherFor' => 'id_kelurahan', ...self::KANTOR_LAIN],
                'kode_pos'       => [
                    'label' => 'Kode Pos',
                    'rules' => 'max_length[5]|regex_match[/^[0-9]{5}\z/]',
                    'hint'  => '5 digit angka; bila kelurahan punya daftar kode pos, pilih salah satunya.',
                ],
                'telp'   => ['label' => 'Telepon', 'rules' => 'max_length[50]'],
                'faks'   => ['label' => 'Faks', 'rules' => 'max_length[50]'],
                'remark' => ['label' => 'Keterangan', 'type' => 'textarea', 'maxBytes' => 65535],
            ],
            'extraSearch'  => ['alamat'],
            'auditColumns' => self::AUDIT_KANTOR,
            'hooks'        => \App\Libraries\MasterData\KantorHooks::class,
        ],
        // Kursem (DDL legacy, tanpa UI CRUD di legacy): dropdown riwayat kursus/seminar. `order` TINYINT [V2].
        'bidang-kursem' => [
            'label'           => 'Bidang Kursus/Seminar',
            'controller'      => 'UmumController',
            'table'           => 'bidang_kursem',
            'primaryKey'      => 'id_bidang_kursem',
            'autoIncrement'   => true,
            'idMaxLength'     => 3,
            'nameField'       => 'bidang_kursem',
            'nameLabel'       => 'Bidang Kursus/Seminar',
            'nameMaxLength'   => 255,
            'parent'          => null,
            'auditColumns'    => self::AUDIT_KURSEM,
            'orderColumnType' => 'tinyint',
        ],
        'instansi-kursem' => [
            'label'           => 'Instansi Penyelenggara Kursus/Seminar',
            'controller'      => 'UmumController',
            'table'           => 'instansi_kursem',
            'primaryKey'      => 'id_instansi_kursem',
            'autoIncrement'   => true,
            'idMaxLength'     => 3,
            'nameField'       => 'instansi_kursem',
            'nameLabel'       => 'Instansi Penyelenggara',
            'nameMaxLength'   => 255,
            'parent'          => null,
            'auditColumns'    => self::AUDIT_KURSEM,
            'orderColumnType' => 'tinyint',
        ],
        // G-08 jenis libur (legacy L_presensi.php:20362): dropdown form hari libur, UL_ALL.
        'jenis-libur' => [
            'label'           => 'Jenis Libur',
            'controller'      => 'HariLiburController',
            'table'           => 'jenis_libur',
            'primaryKey'      => 'id_jenis_libur',
            'autoIncrement'   => true,
            'idMaxLength'     => 3,
            'nameField'       => 'jenis_libur',
            'nameLabel'       => 'Jenis Libur',
            'nameMaxLength'   => 255,
            'parent'          => null,
            'auditColumns'    => self::AUDIT,
            'orderColumnType' => 'tinyint',
        ],
        // --- /DBV-003 ---

        // --- DBV-004 (G-04 kenaikan pangkat, G-05 pendidikan) ---
        // G-04 — Kenaikan Pangkat (KpController). Legacy: hr/master/c_kp (Lm_kp.php), DDL gol_pppk simpeg_prod.sql:1277-1289.
        // G-05 — Pendidikan (PendidikanController). Legacy: hr/master/c_pendidikan (Lm_pendidikan.php), DDL
        // bidang_pendidikan simpeg_prod.sql:257-265. Skema & keputusan: docs/db-review/G-04-G-05-pangkat-pendidikan-schema.md.
        // Dropdown (options) terbuka untuk semua role (publicOptions bawaan): dipakai form riwayat KP/pendidikan Fase 3.
        // ------------------------------------------------------------------
        'pangkat' => [
            'label'         => 'Pangkat/Golongan',
            'controller'    => 'KpController',
            'table'         => 'pangkat',
            'primaryKey'    => 'id_pangkat',
            'autoIncrement' => true,
            'idMaxLength'   => 3,
            // P1: label dropdown & kunci peta SIASN legacy = gol_ruang (teks bebas, mis. "CPNS III/b"), UNIQUE di DB.
            'nameField'     => 'gol_ruang',
            'nameLabel'     => 'Gol./Ruang',
            'nameMaxLength' => 10,
            'parent'        => null,
            'fields'        => [
                // Label & pilihan mengikuti form legacy views/hr/master/kp/form.php:37-81.
                'cpns' => [
                    'label'    => 'Jenis Pangkat',
                    'type'     => 'select',
                    'required' => true,
                    'options'  => [1 => 'CPNS', 2 => 'PNS'],
                ],
                'pangkat' => ['label' => 'Pangkat', 'required' => true, 'rules' => 'max_length[50]'],
                'gol'     => [
                    'label'    => 'Golongan',
                    'type'     => 'select',
                    'required' => true,
                    'options'  => ['I' => 'I', 'II' => 'II', 'III' => 'III', 'IV' => 'IV'],
                ],
                'ruang' => [
                    'label'    => 'Ruang',
                    'type'     => 'select',
                    'required' => true,
                    'options'  => ['a' => 'a', 'b' => 'b', 'c' => 'c', 'd' => 'd', 'e' => 'e'],
                ],
            ],
            'extraSearch'  => ['pangkat'],
            'auditColumns' => self::DBV004_AUDIT,
            // P2: `order` = level pangkat (KP berikutnya = order + 1) — disimpan apa adanya, tidak pernah digeser. Tanpa
            // orderScope: level legacy global (L_employee.php:2434-2441), tambah tanpa order = MAX+1 seluruh pangkat.
            'orderMode'       => 'manual',
            'orderColumnType' => 'tinyint',
            // Dropdown pangkat per jenis KP legacy (L_kp.php:56, 67): ?cpns=1 (CPNS) / ?cpns=2 (PNS).
            'filters' => ['cpns'],
        ],
        'jenis-kp' => [
            'label'           => 'Jenis Kenaikan Pangkat',
            'controller'      => 'KpController',
            'table'           => 'jenis_kp',
            'primaryKey'      => 'id_jenis_kp',
            'autoIncrement'   => true,
            'idMaxLength'     => 3,
            'nameField'       => 'jenis_kp',
            'nameLabel'       => 'Jenis KP',
            'nameMaxLength'   => 100,
            'parent'          => null,
            'auditColumns'    => self::DBV004_AUDIT,
            'orderColumnType' => 'tinyint',
        ],
        'gol-pppk' => [
            'label'         => 'Golongan PPPK',
            'controller'    => 'KpController',
            'table'         => 'gol_pppk',
            'primaryKey'    => 'id_gol_pppk',
            'autoIncrement' => true,
            'idMaxLength'   => 3,
            'nameField'     => 'gol_pppk',
            'nameLabel'     => 'Golongan PPPK',
            'nameMaxLength' => 10,
            'parent'        => null,
            'fields'        => [
                // K5 c(ii): dapat diubah Super Admin (legacy hanya lewat impor). DOUBLE NOT NULL → wajib (kosong = 1048)
                // dan dibatasi (angka raksasa = INF → error SQL) — batas atas pengaman aplikasi, bukan aturan legacy.
                'uang_makan' => [
                    'label'    => 'Uang Makan',
                    'type'     => 'decimal',
                    'required' => true,
                    'min'      => 0,
                    'max'      => 10000000,
                    'hint'     => 'Tarif uang makan PPPK per hari dalam rupiah, 0 sampai 10.000.000. Isi 0 bila belum ada tarif.',
                ],
                'keterangan' => ['label' => 'Keterangan', 'type' => 'textarea', 'maxBytes' => 255],
            ],
            // DDL [K] berpola sama dengan FAQ: created_by saat tambah, updated_by saat ubah.
            'auditColumns'    => self::AUDIT_FAQ,
            'orderColumnType' => 'tinyint',
        ],
        'jenjang-pendidikan' => [
            'label'         => 'Jenjang Pendidikan',
            'controller'    => 'PendidikanController',
            'table'         => 'jenjang_pendidikan',
            'primaryKey'    => 'id_jenjang_pendidikan',
            'autoIncrement' => true,
            'nameField'     => 'jenjang_pendidikan',
            'nameLabel'     => 'Jenjang Pendidikan',
            'nameMaxLength' => 100,
            'parent'        => null,
            'fields'        => [
                'jenjang_pendidikan_singkat' => [
                    'label'    => 'Singkatan',
                    'required' => true,
                    'rules'    => 'max_length[50]',
                    'hint'     => 'Mis. S.1. Dibandingkan sebagai kode oleh modul lain, jadi harus unik.',
                ],
                // P6/D5: nama kolom flag di jurusan_pendidikan, dari daftar tetap (CHECK DB lapis kedua). Kosong = NULL.
                'row_jurusan' => [
                    'label'   => 'Kolom Jurusan',
                    'type'    => 'select',
                    'options' => self::DBV004_ROW_JURUSAN,
                    'hint'    => 'Jenjang pada daftar jurusan yang dipakai dropdown jurusan. Kosongkan untuk jenjang tanpa jurusan (SD/SLTP/SLTA).',
                ],
            ],
            'extraSearch'  => ['jenjang_pendidikan_singkat'],
            'uniqueFields' => ['jenjang_pendidikan_singkat'],
            // P6: skor kualifikasi IP ASN (L_user.php:878-884) disimpan tetapi tidak dikelola/diekspos.
            'hiddenColumns'   => ['bobot_ipasn'],
            'auditColumns'    => self::DBV004_AUDIT,
            'orderColumnType' => 'tinyint',
        ],
        'bidang-pendidikan' => [
            'label'         => 'Bidang Pendidikan',
            'controller'    => 'PendidikanController',
            'table'         => 'bidang_pendidikan',
            'primaryKey'    => 'id_bidang_pendidikan',
            'autoIncrement' => true,
            'idMaxLength'   => 3,
            'nameField'     => 'bidang_pendidikan',
            'nameLabel'     => 'Bidang Pendidikan',
            'nameMaxLength' => 100,
            'parent'        => null,
            'fields'        => [
                'bidang_pendidikan_english' => ['label' => 'Nama (Inggris)', 'rules' => 'max_length[100]'],
            ],
            'extraSearch'  => ['bidang_pendidikan_english'],
            'auditColumns' => self::DBV004_AUDIT,
        ],
        'jurusan-pendidikan' => [
            'label'         => 'Jurusan Pendidikan',
            'controller'    => 'PendidikanController',
            'table'         => 'jurusan_pendidikan',
            'primaryKey'    => 'id_jurusan_pendidikan',
            'autoIncrement' => true,
            'nameField'     => 'jurusan_pendidikan',
            'nameLabel'     => 'Jurusan Pendidikan',
            'nameMaxLength' => 255,
            'parent'        => ['field' => 'id_bidang_pendidikan', 'entity' => 'bidang-pendidikan'],
            'fields'        => [
                'jurusan_pendidikan_english' => ['label' => 'Nama (Inggris)', 'rules' => 'max_length[255]'],
                'gelar'                      => ['label' => 'Gelar', 'rules' => 'max_length[50]'],
                // P10: flag jenjang 1/0 (label checkbox legacy jurusan/form.php:59-93); minimal satu = JurusanPendidikanHooks.
                'D_I'   => ['label' => 'D.I', 'type' => 'boolean', 'hint' => 'Centang minimal satu jenjang yang memiliki jurusan ini.'],
                'D_II'  => ['label' => 'D.II', 'type' => 'boolean'],
                'D_III' => ['label' => 'D.III', 'type' => 'boolean'],
                'D_IV'  => ['label' => 'D.IV', 'type' => 'boolean'],
                'S_1'   => ['label' => 'S.1', 'type' => 'boolean'],
                'S_2'   => ['label' => 'S.2', 'type' => 'boolean'],
                'S_3'   => ['label' => 'S.3', 'type' => 'boolean'],
            ],
            'extraSearch'  => ['jurusan_pendidikan_english'],
            'auditColumns' => self::DBV004_AUDIT,
            // FQCN (tanpa baris `use` baru) supaya kepala file tidak bentrok dengan grup DBV lain saat merge.
            'hooks' => \App\Libraries\MasterData\JurusanPendidikanHooks::class,
            // P7: dropdown jurusan hanya memuat jurusan aktif yang bidangnya juga aktif (memperbaiki Local.php:83, 87).
            'statusChain' => true,
        ],
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
                // K5a: nilai awal "Pengaruh ke Tukin" pengajuan (label legacy rwy/konket/form_ad.php:113). Wajib: kosong akan
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

        // --- DBV-008 (G-02 jabatan, unit, satker) ---
        // G-02 (DBV-008/CR-026 ⏳). Legacy hr/master/c_jabatan (Lm_jabatan.php), CRUD role 1; dropdown UL_ALL (ISSUE-012,
        // tanpa parameter `restrict` — usulan G-02 Bagian 8). Skema: backend/docs/db-review/G-02-jabatan-unit-satker-schema.md.
        // Baris ber-ID hard-coded legacy (G-02 Bagian 2.8) sengaja tidak dikunci. `peta_jabatan` dan tabel jabatan lain
        // tanpa DDL ditunda (Bagian 7). Kolom audit keenam tabel = self::AUDIT (created_at/updated_at/updated_by [K] D1).
        'unit' => [
            'label'         => 'Unit Kerja',
            'controller'    => 'JabatanController',
            'table'         => 'unit',
            'primaryKey'    => 'id_unit',
            'autoIncrement' => true,
            'nameField'     => 'unit',
            'nameLabel'     => 'Unit Kerja',
            'nameMaxLength' => 150,
            'parent'        => null,
            'fields'        => [
                'is_upt'            => self::DBV008_IS_UPT,
                'alamat_pdf_header' => ['label' => 'Alamat PDF Header', 'type' => 'textarea', 'maxBytes' => 255, 'hint' => 'Alamat unit pada kop dokumen PDF. Maksimal 255 byte.'],
                'tembusan_kppn'     => self::DBV008_TEMBUSAN_KPPN,
                'lokasi_kppn'       => self::DBV008_LOKASI_KPPN,
            ],
            'auditColumns'    => self::AUDIT,
            'orderColumnType' => 'int',
        ],
        // Satker per unit: nama unik per unit (uq_satker_nama), urutan per unit, dropdown `satker/options?parent={id_unit}`
        // hanya memuat satker aktif yang unitnya aktif (statusChain). `logo_uns` disimpan, belum dikelola (hiddenColumns).
        'satker' => [
            'label'         => 'Satuan Kerja',
            'controller'    => 'JabatanController',
            'table'         => 'satker',
            'primaryKey'    => 'id_satker',
            'autoIncrement' => true,
            'nameField'     => 'satker',
            'nameLabel'     => 'Satuan Kerja',
            'nameMaxLength' => 150,
            'parent'        => ['field' => 'id_unit', 'entity' => 'unit'],
            'fields'        => [
                // DoD G-02: offset waktu presensi (legacy "WIB + N menit", mask 0-120); CHECK chk_satker_zonasi lapis kedua.
                'zonasi' => [
                    'label'    => 'Zonasi Presensi (menit)',
                    'type'     => 'int',
                    'required' => true,
                    'min'      => 0,
                    'max'      => 120,
                    'hint'     => 'Selisih jam presensi dari WIB dalam menit: 0 = WIB, 60 = WITA, 120 = WIT.',
                ],
                'is_upt'            => self::DBV008_IS_UPT,
                'alamat_pdf_header' => ['label' => 'Alamat PDF Header', 'type' => 'textarea', 'maxBytes' => 65535, 'hint' => 'Alamat satker pada kop dokumen PDF.'],
                'tembusan_kppn'     => self::DBV008_TEMBUSAN_KPPN,
                'lokasi_kppn'       => self::DBV008_LOKASI_KPPN,
            ],
            'hiddenColumns'   => ['logo_uns'],
            'auditColumns'    => self::AUDIT,
            'orderColumnType' => 'smallint',
            'statusChain'     => true,
        ],
        'group-jabatan' => [
            'label'           => 'Group Jabatan',
            'controller'      => 'JabatanController',
            'table'           => 'group_jabatan',
            'primaryKey'      => 'id_group_jabatan',
            'autoIncrement'   => true,
            'nameField'       => 'group_jabatan',
            'nameLabel'       => 'Group Jabatan',
            'nameMaxLength'   => 45,
            'parent'          => null,
            'auditColumns'    => self::AUDIT,
            'orderColumnType' => 'tinyint',
        ],
        // Sub group per group ([K] D1:7183-7195): nama unik per group, urutan per group [V2], dropdown hanya sub group aktif yang
        // group-nya aktif (statusChain). Pindah group ditolak bila sudah dirujuk jabatan (SubGroupJabatanHooks).
        'sub-group-jabatan' => [
            'label'         => 'Sub Group Jabatan',
            'controller'    => 'JabatanController',
            'table'         => 'sub_group_jabatan',
            'primaryKey'    => 'id_sub_group_jabatan',
            'autoIncrement' => true,
            'nameField'     => 'sub_group_jabatan',
            'nameLabel'     => 'Sub Group Jabatan',
            'nameMaxLength' => 100,
            'parent'        => ['field' => 'id_group_jabatan', 'entity' => 'group-jabatan'],
            'fields'        => [
                // Legacy radio YA/TIDAK wajib (Lm_jabatan.php:973, :1015); dipakai riwayat jabatan (B-07).
                'need_satker' => [
                    'label'    => 'Butuh Satuan Kerja',
                    'type'     => 'select',
                    'required' => true,
                    'options'  => [1 => 'Ya', 2 => 'Tidak'],
                    'hint'     => 'Ya = jabatan pada sub group ini dipilih per satuan kerja di riwayat jabatan.',
                ],
            ],
            // FQCN (tanpa baris `use` baru) supaya kepala file tidak bentrok dengan grup DBV lain saat merge.
            'hooks'           => \App\Libraries\MasterData\SubGroupJabatanHooks::class,
            'auditColumns'    => self::AUDIT,
            'orderColumnType' => 'tinyint',
            'statusChain'     => true,
        ],
        // Kelas jabatan: PK alami = nomor kelas (TINYINT [K] D1:2028, bukan AUTO_INCREMENT) tanpa kolom nama → kode = nama
        // (codeAsName, CR-026), nomor 1-20 (mask form legacy kelas/form.php:73-74, CHECK chk_kelas_jabatan_kelas_jabatan).
        // Nomor kelas tidak bisa diubah; hanya `tukin` & status. Tanpa `order` (urutan alami = nomor kelas).
        'kelas-jabatan' => [
            'label'         => 'Kelas Jabatan',
            'controller'    => 'JabatanController',
            'table'         => 'kelas_jabatan',
            'primaryKey'    => 'kelas_jabatan',
            'idMaxLength'   => 2,
            'idRange'       => [1, 20],
            'codeAsName'    => true,
            'nameField'     => 'kelas_jabatan',
            'nameLabel'     => 'Kelas Jabatan',
            'nameMaxLength' => 2,
            'parent'        => null,
            'hasOrder'      => false,
            'fields'        => [
                'tukin' => [
                    'label'    => 'Tunjangan Kinerja',
                    'type'     => 'int',
                    'required' => true,
                    'min'      => 0,
                    'hint'     => 'Nominal tunjangan kinerja per bulan (rupiah) untuk kelas jabatan ini.',
                ],
            ],
            'auditColumns' => self::AUDIT,
        ],
        // Jabatan [K]: group & sub group wajib (dropdown berjenjang, sub group harus di bawah group yang dipilih), satker
        // dan kelas opsional (JF/Pelaksana tanpa satker, legacy form.php). Nama unik per (sub group, satker) — termasuk
        // satker kosong, yang hanya ditegakkan aplikasi (UNIQUE DB tidak membandingkan NULL). Tanpa `order` (legacy
        // urut kelas/id). `id_jenjang_jf` belum dikelola (tabel jenjang_jf ditunda, G-02 Bagian 7).
        'jabatan' => [
            'label'         => 'Jabatan',
            'controller'    => 'JabatanController',
            'table'         => 'jabatan',
            'primaryKey'    => 'id_jabatan',
            'autoIncrement' => true,
            'nameField'     => 'jabatan',
            'nameLabel'     => 'Jabatan',
            'nameMaxLength' => 250,
            'parent'        => null,
            'hasOrder'      => false,
            'fields'        => [
                'id_group_jabatan'     => ['label' => 'Group Jabatan', 'type' => 'ref', 'entity' => 'group-jabatan', 'required' => true],
                'id_sub_group_jabatan' => [
                    'label'     => 'Sub Group Jabatan',
                    'type'      => 'ref',
                    'entity'    => 'sub-group-jabatan',
                    'required'  => true,
                    'dependsOn' => 'id_group_jabatan',
                ],
                'id_satker' => [
                    'label'  => 'Satuan Kerja',
                    'type'   => 'ref',
                    'entity' => 'satker',
                    'hint'   => 'Kosongkan untuk jabatan yang tidak terikat satuan kerja (mis. jabatan fungsional/pelaksana).',
                ],
                'kelas_jabatan' => ['label' => 'Kelas Jabatan', 'type' => 'ref', 'entity' => 'kelas-jabatan'],
                // Legacy mask 50-80 (views/hr/master/jabatan/form.php:164-168), opsional.
                'umur_pensiun' => [
                    'label' => 'Umur Pensiun',
                    'type'  => 'int',
                    'min'   => 50,
                    'max'   => 80,
                    'hint'  => 'Opsional, 50 sampai 80. Kosongkan bila mengikuti batas usia pensiun umum.',
                ],
            ],
            'uniqueScope'   => ['id_sub_group_jabatan', 'id_satker'],
            'filters'       => ['id_group_jabatan', 'id_sub_group_jabatan', 'id_satker', 'kelas_jabatan'],
            'hiddenColumns' => ['id_jenjang_jf'],
            'auditColumns'  => self::AUDIT,
        ],
        // --- /DBV-008 ---
    ];
}
