<?php

declare(strict_types=1);

namespace Config;

use App\Libraries\MasterData\WebConfigTypes as T;
use CodeIgniter\Config\BaseConfig;

/**
 * G-09 — katalog key `web_config` bertipe (DBV-006/CR-030). Satu sumber kebenaran untuk: key yang boleh disimpan,
 * tipe dan validasi nilainya (App\Libraries\MasterData\WebConfigTypes), nilai bawaan bila baris belum ada, serta label
 * form admin. Tipe TIDAK disimpan di tabel (G-09 Bagian 4 #3): admin hanya mengubah nilai, bukan tipe.
 *
 * Sumber key: kode legacy (katalog riset J4.04 + temuan tambahan DBV-006), nama key legacy dipertahankan apa adanya
 * (konsumen lintas aplikasi, mis. mudig, membaca `simpeg01.web_config` dengan nama yang sama). Rujukan baris kode
 * legacy ada di G-09 Bagian 2.2. Menambah key = tambah entri di sini (+ dokumentasi G-09 Bagian 2.2), tanpa migration.
 *
 * Struktur entri: config_name => [
 *   'label', 'group' (pengelompokan di halaman admin), 'description' (bantuan form),
 *   'type'    => salah satu WebConfigTypes::TYPES,
 *   'default' => nilai bawaan ternormalisasi (string) atau null = belum diatur; dipakai bila baris tidak ada,
 *   batasan per tipe: 'maxLength' (text, karakter), 'min'/'max' (integer, decimal), 'scale' (decimal, digit desimal),
 *   'ref' (id_ref: tabel rujukan legacy), 'personal' => true bila nilai berisi data pribadi (jangan disalin ke seed/dokumen).
 * ]
 */
class WebConfig extends BaseConfig
{
    public const GROUP_IDENTITAS = 'Identitas Instansi';
    public const GROUP_KOP_PDF   = 'Kop Dokumen PDF';
    public const GROUP_PEJABAT   = 'Pejabat';
    public const GROUP_ACUAN     = 'Unit & Jabatan Acuan';
    public const GROUP_KGB       = 'SK KGB (KPPN)';
    public const GROUP_TUKIN     = 'Potongan Tunjangan Kinerja (%)';
    public const GROUP_UANG      = 'Uang Makan PNS (Rp per hari)';
    public const GROUP_EMAIL     = 'Email Ulang Tahun';

    /**
     * Tingkat potongan tukin legacy dalam persen (L_presensi.php:1483, dipakai :2102-2170): desimal 0..100, 2 digit.
     */
    private const PERSEN = ['type' => T::DECIMAL, 'group' => self::GROUP_TUKIN, 'default' => null, 'min' => 0, 'max' => 100, 'scale' => 2];

    /**
     * Uang makan PNS per golongan (L_presensi.php:4025): rupiah bulat, batas sama dengan `gol_pppk.uang_makan` (CR-011).
     */
    private const RUPIAH = ['type' => T::INTEGER, 'group' => self::GROUP_UANG, 'default' => null, 'min' => 0, 'max' => 10_000_000];

    /**
     * @var array<string, array<string, mixed>>
     */
    public array $keys = [
        // --- Identitas instansi ---
        'nama_kementerian' => [
            'label'       => 'Nama Kementerian', 'group' => self::GROUP_IDENTITAS, 'type' => T::TEXT, 'default' => '', 'maxLength' => 255,
            'description' => 'Nama resmi instansi di surat, laporan, dan notifikasi.',
        ],
        'nama_kementerian_short' => [
            'label'       => 'Nama Singkat Kementerian', 'group' => self::GROUP_IDENTITAS, 'type' => T::TEXT, 'default' => '', 'maxLength' => 100,
            'description' => 'Singkatan nama instansi.',
        ],
        'alamat_kementerian' => [
            'label'       => 'Alamat Kementerian', 'group' => self::GROUP_IDENTITAS, 'type' => T::TEXTAREA, 'default' => '',
            'description' => 'Alamat kantor (boleh beberapa baris).',
        ],

        // --- Kop dokumen PDF (HTML tepercaya, dirender di kop; disanitasi saat simpan) ---
        'pdf_header_nama_kementerian' => [
            'label'       => 'Kop PDF: Nama Kementerian', 'group' => self::GROUP_KOP_PDF, 'type' => T::HTML, 'default' => '',
            'description' => 'HTML kop (boleh <br>); ditampilkan huruf kapital.',
        ],
        'pdf_header_nama_unit' => [
            'label'       => 'Kop PDF: Nama Unit', 'group' => self::GROUP_KOP_PDF, 'type' => T::HTML, 'default' => '',
            'description' => 'Baris unit di kop Slip Gaji (key baru pascabaseline legacy).',
        ],
        'pdf_header_alamat_kementerian' => [
            'label'       => 'Kop PDF: Alamat', 'group' => self::GROUP_KOP_PDF, 'type' => T::HTML, 'default' => '',
            'description' => 'HTML alamat kop (boleh <br> dan tautan).',
        ],
        'logo_kementerian_pdf' => [
            'label'       => 'Kop PDF: Logo Kiri', 'group' => self::GROUP_KOP_PDF, 'type' => T::ASSET_PATH, 'default' => '',
            'description' => 'Path relatif berkas logo di penyimpanan aset v2; kosong = logo bawaan v2.',
        ],
        'logo_wonderful_pdf' => [
            'label'       => 'Kop PDF: Logo Kanan', 'group' => self::GROUP_KOP_PDF, 'type' => T::ASSET_PATH, 'default' => '',
            'description' => 'Path relatif berkas logo di penyimpanan aset v2; kosong = logo bawaan v2.',
        ],
        'logo_kementerian_url' => [
            'label'       => 'URL Logo Kiri (pratinjau)', 'group' => self::GROUP_KOP_PDF, 'type' => T::URL, 'default' => '',
            'description' => 'URL gambar logo untuk pratinjau HTML.',
        ],
        'logo_wonderful_url' => [
            'label'       => 'URL Logo Kanan (pratinjau)', 'group' => self::GROUP_KOP_PDF, 'type' => T::URL, 'default' => '',
            'description' => 'URL gambar logo untuk pratinjau HTML.',
        ],
        'kantor_pdf' => [
            'label'       => 'Nama Kantor di PDF', 'group' => self::GROUP_KOP_PDF, 'type' => T::TEXT, 'default' => '', 'maxLength' => 255,
            'description' => 'Nama kantor pada SK KGB.',
        ],

        // --- Pejabat ---
        'nama_menteri' => [
            'label'       => 'Nama Menteri', 'group' => self::GROUP_PEJABAT, 'type' => T::TEXT, 'default' => '', 'maxLength' => 255,
            'description' => 'Nama pejabat penandatangan (juga dibaca integrasi e-kinerja).',
        ],
        'jabatan_menteri_full' => [
            'label'       => 'Jabatan Menteri (lengkap)', 'group' => self::GROUP_PEJABAT, 'type' => T::TEXT, 'default' => '', 'maxLength' => 255,
            'description' => 'Nama jabatan menteri lengkap.',
        ],
        'jabatan_menteri_singkat' => [
            'label'       => 'Jabatan Menteri (singkat)', 'group' => self::GROUP_PEJABAT, 'type' => T::TEXT, 'default' => '', 'maxLength' => 255,
            'description' => 'Nama jabatan menteri singkat.',
        ],
        'nama_wakil_menteri' => [
            'label'       => 'Nama Wakil Menteri', 'group' => self::GROUP_PEJABAT, 'type' => T::TEXT, 'default' => '', 'maxLength' => 255,
            'description' => 'Nama wakil menteri.',
        ],
        'jabatan_wakil_menteri_singkat' => [
            'label'       => 'Jabatan Wakil Menteri (singkat)', 'group' => self::GROUP_PEJABAT, 'type' => T::TEXT, 'default' => '', 'maxLength' => 255,
            'description' => 'Nama jabatan wakil menteri singkat.',
        ],

        // --- Unit & jabatan acuan (id legacy; keberadaan baris dicek setelah tabel G-02 ada) ---
        'id_unit_kepegawaian' => [
            'label'       => 'Unit Kepegawaian', 'group' => self::GROUP_ACUAN, 'type' => T::ID_REF, 'default' => null, 'ref' => 'unit',
            'description' => 'ID unit (tabel unit) yang mengelola kepegawaian.',
        ],
        'id_satker_kepegawaian' => [
            'label'       => 'Satker Kepegawaian', 'group' => self::GROUP_ACUAN, 'type' => T::ID_REF, 'default' => null, 'ref' => 'satker',
            'description' => 'ID satker (tabel satker) yang mengelola kepegawaian.',
        ],
        'id_unit_inspektorat' => [
            'label'       => 'Unit Inspektorat', 'group' => self::GROUP_ACUAN, 'type' => T::ID_REF, 'default' => null, 'ref' => 'unit',
            'description' => 'ID unit (tabel unit) inspektorat.',
        ],
        'id_unit_kelembagaan' => [
            'label'       => 'Unit Kelembagaan', 'group' => self::GROUP_ACUAN, 'type' => T::ID_REF, 'default' => null, 'ref' => 'unit',
            'description' => 'ID unit (tabel unit) kelembagaan.',
        ],
        'id_jabatan_kabiro_kepegawaian' => [
            'label'       => 'Jabatan Kepala Biro Kepegawaian', 'group' => self::GROUP_ACUAN, 'type' => T::ID_REF, 'default' => null, 'ref' => 'jabatan',
            'description' => 'ID jabatan (tabel jabatan) Kepala Biro Kepegawaian.',
        ],

        // --- SK KGB (dibaca aplikasi mudig) ---
        'tembusan_kppn' => [
            'label'       => 'Tembusan KPPN', 'group' => self::GROUP_KGB, 'type' => T::TEXTAREA, 'default' => '',
            'description' => 'Tembusan SK KGB; bisa ditimpa per unit/satker.',
        ],
        'lokasi_kppn' => [
            'label'       => 'Lokasi KPPN', 'group' => self::GROUP_KGB, 'type' => T::TEXT, 'default' => '', 'maxLength' => 255,
            'description' => 'Lokasi KPPN di SK KGB; bisa ditimpa per unit/satker.',
        ],

        // --- Potongan tukin (persen) ---
        'TL1/PSW1' => self::PERSEN + ['label' => 'TL1 / PSW1', 'description' => 'Terlambat / pulang sebelum waktu ≤ 30 menit.'],
        'TL2/PSW2' => self::PERSEN + ['label' => 'TL2 / PSW2', 'description' => 'Terlambat / pulang sebelum waktu 31-60 menit.'],
        'TL3/PSW3' => self::PERSEN + ['label' => 'TL3 / PSW3', 'description' => 'Terlambat / pulang sebelum waktu > 60 menit.'],
        'TA'       => self::PERSEN + ['label' => 'TA', 'description' => 'Tidak absen (masuk atau pulang).'],
        'TK'       => self::PERSEN + ['label' => 'TK', 'description' => 'Tanpa keterangan (tidak masuk kerja).'],
        'LKH'      => self::PERSEN + ['label' => 'LKH', 'description' => 'Laporan kinerja harian tidak diisi.'],

        // --- Uang makan PNS per golongan (PPPK dari gol_pppk.uang_makan) ---
        'uang_makan_gol_1' => self::RUPIAH + ['label' => 'Golongan I', 'description' => 'Tarif uang makan PNS golongan I.'],
        'uang_makan_gol_2' => self::RUPIAH + ['label' => 'Golongan II', 'description' => 'Tarif uang makan PNS golongan II.'],
        'uang_makan_gol_3' => self::RUPIAH + ['label' => 'Golongan III', 'description' => 'Tarif uang makan PNS golongan III.'],
        'uang_makan_gol_4' => self::RUPIAH + ['label' => 'Golongan IV', 'description' => 'Tarif uang makan PNS golongan IV.'],

        // --- Email ulang tahun (cron legacy dimatikan; dipertahankan untuk keputusan TL) ---
        'email_sent_time' => [
            'label'       => 'Jam Kirim Email', 'group' => self::GROUP_EMAIL, 'type' => T::TIME, 'default' => '06:00:00',
            'description' => 'Jam kirim email ulang tahun (HH:MM atau HH:MM:SS).',
        ],
        'email_ultah_ad' => [
            'label'       => 'Penerima Email', 'group' => self::GROUP_EMAIL, 'type' => T::EMAIL_LIST, 'default' => '', 'personal' => true,
            'description' => 'Daftar email dipisah koma; alamat pertama = penerima, sisanya tembusan.',
        ],
    ];
}
