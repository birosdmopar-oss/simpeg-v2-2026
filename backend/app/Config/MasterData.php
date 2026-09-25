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
        // --- /DBV-005 ---
    ];
}
