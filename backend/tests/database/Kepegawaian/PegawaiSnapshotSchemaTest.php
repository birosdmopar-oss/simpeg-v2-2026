<?php

declare(strict_types=1);

namespace Tests\Database\Kepegawaian;

use CodeIgniter\Database\Migration;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use RuntimeException;
use Throwable;

/**
 * DBV-012 — skema B-01 hasil migration 2026-09-30-120000_CreatePegawai dan 2026-09-30-120100_CreatePegawaiSnapshot
 * harus sama dengan yang diajukan ke DB Validator (backend/docs/db-review/B-01-B-02-pegawai-riwayat-schema.md Bagian 2.1):
 * kolom (tipe, NULL, urutan), collation, index, FK (nama legacy [K-erd], RESTRICT/RESTRICT), CHECK [V2], perilaku
 * constraint, down()/up() bersih, dan pembersihan saat up() gagal di tengah.
 *
 * FK snapshot → riwayat (131000, DBV-013) dan snapshot → G-02 (ditahan) diuji di tempat lain; di sini hanya
 * dipastikan tidak ada FK lain di luar daftar yang dikenal.
 *
 * @internal
 */
final class PegawaiSnapshotSchemaTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = null;

    /**
     * Versi migration CreatePegawai; semua migration App sejak versi ini dilepas/dipasang ulang oleh test rollback.
     */
    private const FIRST_VERSION = '2026-09-30-120000';

    private const SNAPSHOT_VERSION = '2026-09-30-120100';

    private const SNAPSHOTS = [
        'pegawai_kp', 'pegawai_cpns', 'pegawai_pns', 'pegawai_kgb', 'pegawai_pendidikan', 'pegawai_diklat',
        'pegawai_hukdis', 'pegawai_ak', 'pegawai_keluarga', 'pegawai_alamat', 'pegawai_alamat_kantor',
        'pegawai_tanda_jasa', 'pegawai_mutasi_jabatan',
    ];

    private const TABLES = ['pegawai', 'pegawai_hist', 'pegawai_foto', ...self::SNAPSHOTS];

    /**
     * Kolom biodata `pegawai` [L] dari `id_provinsi_lahir` s.d. `siasn_lu`; `pegawai_hist` menyalinnya persis.
     */
    private const BIODATA = [
        'id_provinsi_lahir'         => 'char(2) null',
        'id_kabupaten_kota_lahir'   => 'char(4) null',
        'id_agama'                  => 'tinyint null',
        'id_jenis_pegawai'          => 'tinyint null',
        'id_jenis_status'           => 'tinyint null',
        'nip_lama'                  => 'varchar(18) null',
        'nama'                      => 'varchar(100)',
        'glr_awal'                  => 'varchar(50) null',
        'glr_akhir'                 => 'varchar(50) null',
        'tgl_lahir'                 => 'date',
        'provinsi_lahir'            => 'varchar(255) null',
        'provinsi_lahir_lain'       => 'varchar(255) null',
        'kabupaten_kota_lahir'      => 'varchar(255) null',
        'kabupaten_kota_lahir_lain' => 'varchar(255) null',
        'agama'                     => 'varchar(30) null',
        'jenis_kelamin'             => 'tinyint(1)',
        'npwp'                      => 'varchar(20) null',
        'nik'                       => 'varchar(20) null',
        'bpjs_kes'                  => 'varchar(50) null',
        'bpjs_ket'                  => 'varchar(50) null',
        'no_taspen'                 => 'varchar(50) null',
        'no_hp'                     => 'varchar(20) null',
        'jenis_kerabat'             => 'varchar(100) null',
        'no_telp_kerabat'           => 'varchar(20) null',
        'status_pernikahan'         => 'tinyint(1) null',
        'jenis_pegawai'             => 'varchar(255) null',
        'jenis_status'              => 'varchar(100) null',
        'tmt_status'                => 'date null',
        'foto'                      => 'varchar(255) null',
        'keterangan'                => 'tinytext null',
        'status'                    => 'tinyint',
        'flag_update'               => 'tinyint',
        'id_pns_siasn'              => 'varchar(100) null',
        'siasn_flag'                => 'tinyint null',
        'siasn_lu'                  => 'datetime null',
    ];

    /**
     * Kolom snapshot KP [L pegawai_kp]; `pegawai_cpns`/`pegawai_pns` identik [I].
     */
    private const KP = [
        'nip'                    => 'varchar(30)',
        'id_riwayat_kp'          => 'int null',
        'id_jenis_kp'            => 'tinyint null',
        'id_pangkat'             => 'tinyint null',
        'tmtsk'                  => 'date',
        'tgl_sk'                 => 'date',
        'no_sk'                  => 'varchar(100) null',
        'tgl_pertek_bkn'         => 'date null',
        'no_pertek_bkn'          => 'varchar(100) null',
        'jumlah_kredit_utama'    => 'double null',
        'jumlah_kredit_tambahan' => 'double null',
        'jenis_kp'               => 'varchar(100)',
        'gol'                    => 'varchar(10)',
        'ruang'                  => 'varchar(10)',
        'gol_ruang'              => 'varchar(10)',
        'pangkat'                => 'varchar(50)',
        'mker_th'                => 'tinyint null',
        'mker_bl'                => 'tinyint null',
        'gaji_pokok'             => 'int null',
        'keterangan'             => 'tinytext null',
        'id_rw_siasn'            => 'varchar(100) null',
        'updated_at'             => 'datetime',
    ];

    /**
     * Kolom alamat [I] (`jenis_alamat` s.d. `alamat`), sama untuk `pegawai_alamat` dan `pegawai_alamat_kantor`.
     */
    private const ALAMAT = [
        'jenis_alamat'        => 'tinyint(1) null',
        'id_provinsi'         => 'char(2) null',
        'id_kabupaten_kota'   => 'char(4) null',
        'id_kecamatan'        => 'char(7) null',
        'id_kelurahan'        => 'char(10) null',
        'provinsi'            => 'varchar(255) null',
        'provinsi_lain'       => 'varchar(255) null',
        'kabupaten_kota'      => 'varchar(255) null',
        'kabupaten_kota_lain' => 'varchar(255) null',
        'kecamatan'           => 'varchar(255) null',
        'kecamatan_lain'      => 'varchar(255) null',
        'kelurahan'           => 'varchar(255) null',
        'kelurahan_lain'      => 'varchar(255) null',
        'kd_pos'              => 'varchar(10) null',
        'alamat'              => 'text null',
    ];

    /**
     * Kolom yang diharapkan: tabel => [kolom => "COLUMN_TYPE[ null]"] urut posisi.
     */
    private const COLUMNS = [
        'pegawai' => [
            'nip' => 'varchar(30)',
            ...self::BIODATA,
            'created_at' => 'datetime',
            'updated_at' => 'datetime null',
            'updated_by' => 'int null',
            'deleted_at' => 'datetime null',
        ],
        'pegawai_hist' => [
            'id_pegawai_hist' => 'int',
            'nip'             => 'varchar(30)',
            ...self::BIODATA,
            'email'          => 'varchar(150) null',
            'created_at'     => 'datetime',
            'updated_at'     => 'datetime null',
            'updated_by'     => 'int null',
            'approved_by'    => 'int null',
            'reason_note'    => 'mediumtext null',
            'show_notif'     => 'tinyint',
            'notif_date'     => 'datetime null',
            'show_ua_upt'    => 'tinyint null',
            'show_ua_deputi' => 'tinyint null',
            'show_ua_biro'   => 'tinyint null',
        ],
        'pegawai_foto' => [
            'id_pegawai_foto' => 'int', 'nip' => 'varchar(30)', 'foto' => 'varchar(255)', 'created_at' => 'datetime',
        ],
        'pegawai_kp'   => self::KP,
        'pegawai_cpns' => self::KP,
        'pegawai_pns'  => self::KP,
        'pegawai_kgb'  => [
            'nip'            => 'varchar(30)',
            'id_riwayat_kgb' => 'int null',
            'id_gol_pppk'    => 'tinyint null',
            'tmtsk'          => 'date',
            'no_sk'          => 'varchar(100) null',
            'tgl_sk'         => 'date null',
            'mker_gol_th'    => 'tinyint null',
            'gaji_pokok'     => 'int null',
            'jym'            => 'varchar(255) null',
            'keterangan'     => 'tinytext null',
            'updated_at'     => 'datetime',
        ],
        'pegawai_pendidikan' => [
            'nip'                        => 'varchar(30)',
            'id_riwayat_pendidikan'      => 'int null',
            'id_jenjang_pendidikan'      => 'int null',
            'id_bidang_pendidikan'       => 'tinyint null',
            'id_jurusan_pendidikan'      => 'int null',
            'tgl_lulus'                  => 'date null',
            'no_ijazah'                  => 'varchar(100) null',
            'no_sk_penc_glr'             => 'varchar(100) null',
            'institusi_pendidikan'       => 'varchar(255) null',
            'jenjang_pendidikan_singkat' => 'varchar(50) null',
            'bidang_pendidikan'          => 'varchar(100) null',
            'bidang_pendidikan_lain'     => 'varchar(255) null',
            'jurusan_pendidikan'         => 'varchar(255) null',
            'jurusan_pendidikan_lain'    => 'varchar(255) null',
            'nem'                        => 'double null',
            'ipk'                        => 'double null',
            'keterangan'                 => 'tinytext null',
            'id_rw_siasn'                => 'varchar(100) null',
            'updated_at'                 => 'datetime',
        ],
        'pegawai_diklat' => [
            'nip'                    => 'varchar(30)',
            'id_riwayat_diklat'      => 'int null',
            'id_diklat'              => 'tinyint null',
            'jenis_diklat'           => 'tinyint(1) null',
            'nama_diklat'            => 'varchar(255) null',
            'sub_group_jabatan'      => 'varchar(100) null',
            'tgl_sertifikat'         => 'date null',
            'no_sertifikat'          => 'varchar(100) null',
            'instansi_penyelenggara' => 'varchar(255) null',
            'deskripsi'              => 'text null',
            'jumlah_jp'              => 'smallint null',
            'keterangan'             => 'text null',
            'updated_at'             => 'datetime',
        ],
        'pegawai_hukdis' => [
            'nip'               => 'varchar(30)',
            'id_riwayat_hukdis' => 'int null',
            'id_tingkat_hukdis' => 'int null',
            'tingkat_hukdis'    => 'varchar(100) null',
            'id_jenis_hukdis'   => 'int null',
            'jenis_hukdis'      => 'varchar(255) null',
            'id_pangkat'        => 'tinyint null',
            'gol'               => 'varchar(10) null',
            'ruang'             => 'varchar(10) null',
            'gol_ruang'         => 'varchar(10) null',
            'pangkat'           => 'varchar(50) null',
            'no_sk'             => 'varchar(100) null',
            'tgl_sk'            => 'date null',
            'tmtsk'             => 'date null',
            'masa_hukuman'      => 'text null',
            'akhir_hukdis'      => 'date null',
            'aturan_dilanggar'  => 'text null',
            'alasan_hukuman'    => 'text null',
            'keterangan'        => 'text null',
            'updated_at'        => 'datetime',
        ],
        'pegawai_ak' => [
            'nip'           => 'varchar(30)',
            'id_riwayat_ak' => 'int null',
            'id_jabatan'    => 'int null',
            'id_pangkat'    => 'tinyint null',
            'jenis_ak'      => 'tinyint null',
            'no_hpak'       => 'varchar(100) null',
            'tgl_pak'       => 'date null',
            'periode_awal'  => 'date null',
            'periode_akhir' => 'date null',
            'nilai_ak'      => 'decimal(8,3) null',
            'file_pak'      => 'varchar(255) null',
            'updated_at'    => 'datetime',
        ],
        'pegawai_keluarga' => [
            'nip'                 => 'varchar(30)',
            'id_riwayat_keluarga' => 'int null',
            'urutan_perkawinan'   => 'tinyint null',
            'tgl_perkawinan'      => 'date null',
            'kota_perkawinan'     => 'varchar(255) null',
            'nama_pasangan'       => 'varchar(255) null',
            'jumlah_anak'         => 'tinyint',
            'keterangan'          => 'tinytext null',
            'updated_at'          => 'datetime',
        ],
        'pegawai_alamat' => [
            'nip'               => 'varchar(30)',
            'id_riwayat_alamat' => 'int null',
            ...self::ALAMAT,
            'updated_at' => 'datetime',
        ],
        'pegawai_alamat_kantor' => [
            'nip'               => 'varchar(30)',
            'id_riwayat_alamat' => 'int null',
            ...self::ALAMAT,
            'nama_kantor' => 'varchar(255) null',
            'lantai'      => 'varchar(50) null',
            'telp'        => 'varchar(50) null',
            'faks'        => 'varchar(50) null',
            'updated_at'  => 'datetime',
        ],
        'pegawai_tanda_jasa' => [
            'nip'                   => 'varchar(30)',
            'id_riwayat_tanda_jasa' => 'int null',
            'id_tanda_jasa'         => 'int null',
            'tanda_jasa'            => 'varchar(255) null',
            'tanda_jasa_lain'       => 'varchar(255) null',
            'tgl_sertifikat'        => 'date null',
            'no_sertifikat'         => 'varchar(100) null',
            'negara'                => 'varchar(100) null',
            'keterangan'            => 'text null',
            'updated_at'            => 'datetime',
        ],
        'pegawai_mutasi_jabatan' => [
            'nip'                       => 'varchar(30)',
            'id_riwayat_mutasi_jabatan' => 'int null',
            'id_group_jabatan'          => 'int null',
            'id_sub_group_jabatan'      => 'int null',
            'id_unit'                   => 'int null',
            'id_satker'                 => 'int null',
            'id_atasan_es_1'            => 'int null',
            'id_atasan_es_2'            => 'int null',
            'id_atasan_es_3'            => 'int null',
            'id_atasan_es_3_koord'      => 'int null',
            'id_atasan_es_4'            => 'int null',
            'id_atasan_es_4_koord'      => 'int null',
            'id_jabatan'                => 'int null',
            'id_jabatan_koord'          => 'int null',
            'id_gol_pppk'               => 'tinyint null',
            'id_rumpun_jabatan'         => 'tinyint null',
            'jenis_jabatan'             => 'tinyint(1)',
            'jenis_jabatan_koord'       => 'tinyint(1)',
            'jenis_mutasi'              => 'tinyint(1)',
            'tmtsk'                     => 'date',
            'no_sk'                     => 'varchar(100) null',
            'tgl_sk'                    => 'date null',
            'pmk'                       => 'year null',
            'mhpk_mulai'                => 'date null',
            'mhpk_akhir'                => 'date null',
            'gol_pppk'                  => 'varchar(10) null',
            'nama_instansi'             => 'varchar(255) null',
            'group_jabatan'             => 'varchar(45) null',
            'sub_group_jabatan'         => 'varchar(100) null',
            'unit'                      => 'varchar(255) null',
            'satker'                    => 'varchar(255) null',
            'atasan_es_1'               => 'varchar(255) null',
            'atasan_es_2'               => 'varchar(255) null',
            'atasan_es_3'               => 'varchar(255) null',
            'atasan_es_3_koord'         => 'varchar(255) null',
            'atasan_es_4'               => 'varchar(255) null',
            'atasan_es_4_koord'         => 'varchar(255) null',
            'jabatan'                   => 'varchar(255) null',
            'jabatan_koord'             => 'varchar(255) null',
            'jabatan_lain'              => 'varchar(255) null',
            'rumpun_jabatan'            => 'varchar(50) null',
            'subrumpun_jabatan'         => 'text null',
            'kelas_jabatan'             => 'tinyint null',
            'kredit_jft'                => 'int null',
            'no_induk_jft'              => 'varchar(50) null',
            'status_jft'                => 'tinyint(1) null',
            'ser_dosen'                 => 'varchar(255) null',
            'f_spmt'                    => 'varchar(255) null',
            'f_bapel'                   => 'varchar(255) null',
            'keterangan'                => 'mediumtext null',
            'id_rw_siasn'               => 'varchar(100) null',
            'updated_at'                => 'datetime',
        ],
    ];

    /**
     * Index non-PRIMARY per tabel: tabel => [nama => kolom berurutan] (semua non-unique BTREE). PRIMARY diperiksa
     * terpisah (self::PRIMARY_KEYS).
     */
    private const INDEXES = [
        'pegawai' => [
            'status'                                     => ['status'],
            'fk_id_provinsi_lahir_peg_to_prov'           => ['id_provinsi_lahir'],
            'fk_id_kabupaten_kota_lahir_peg_to_kab_kota' => ['id_kabupaten_kota_lahir'],
            'fk_id_agama_peg_to_agama'                   => ['id_agama'],
            'fk_id_jenis_pegawai_peg_to_jenis_pegawai'   => ['id_jenis_pegawai'],
            'fk_id_jenis_status_peg_to_jenis_status'     => ['id_jenis_status'],
        ],
        'pegawai_hist' => [
            'nip'                 => ['nip'],
            'flag_update'         => ['flag_update'],
            'show_notif'          => ['show_notif', 'notif_date'],
            'fk_peg_hist_ibfk_02' => ['id_provinsi_lahir'],
            'fk_peg_hist_ibfk_03' => ['id_kabupaten_kota_lahir'],
            'fk_peg_hist_ibfk_04' => ['id_agama'],
            'fk_peg_hist_ibfk_05' => ['id_jenis_pegawai'],
            'fk_peg_hist_ibfk_06' => ['id_jenis_status'],
        ],
        'pegawai_foto' => ['fk_nip_pegfoto_to_peg' => ['nip']],
        'pegawai_kp'   => [
            'fk_id_riwayat_kp_peg_kp_to_rwy_kp' => ['id_riwayat_kp'],
            'fk_id_jenis_kp_peg_kp_to_jenis_kp' => ['id_jenis_kp'],
            'fk_id_pangkat_peg_kp_to_pangkat'   => ['id_pangkat'],
        ],
        'pegawai_cpns' => [
            'fk_id_riwayat_kp_pcpns_to_rwy_kp' => ['id_riwayat_kp'],
            'fk_id_jenis_kp_pcpns_to_jenis_kp' => ['id_jenis_kp'],
            'fk_id_pangkat_pcpns_to_pangkat'   => ['id_pangkat'],
        ],
        'pegawai_pns' => [
            'fk_id_riwayat_kp_ppns_to_rwy_kp' => ['id_riwayat_kp'],
            'fk_id_jenis_kp_ppns_to_jenis_kp' => ['id_jenis_kp'],
            'fk_id_pangkat_ppns_to_pangkat'   => ['id_pangkat'],
        ],
        'pegawai_kgb' => [
            'fk_id_riwayat_kgb_pkgb_to_rkgb' => ['id_riwayat_kgb'],
            'pegawai_kgb_ibfk_01'            => ['id_gol_pppk'],
        ],
        'pegawai_pendidikan' => [
            'fk_id_riwayat_pendidikan_pegpend_to_rpend'  => ['id_riwayat_pendidikan'],
            'fk_id_jenjang_pendidikan_pegpend_to_jpend'  => ['id_jenjang_pendidikan'],
            'fk_id_bidang_pendidikan_pegpend_to_bpend'   => ['id_bidang_pendidikan'],
            'fk_id_jurusan_pendidikan_pegpend_to_jupend' => ['id_jurusan_pendidikan'],
        ],
        'pegawai_diklat' => [
            'fk_id_riwayat_diklat_pegdiklat_to_rwydiklat' => ['id_riwayat_diklat'],
            'fk_id_diklat_pegdiklat_to_diklat'            => ['id_diklat'],
        ],
        'pegawai_hukdis' => [
            'fk_id_riwayat_hukdis_peg_hukdis_to_rwy_hukdis'     => ['id_riwayat_hukdis'],
            'fk_id_tingkat_hukdis_peg_hukdis_to_tingkat_hukdis' => ['id_tingkat_hukdis'],
            'fk_id_jenis_hukdis_peg_hukdis_to_jenis_hukdis'     => ['id_jenis_hukdis'],
            'fk_id_pangkat_peg_hukdis_to_pangkat'               => ['id_pangkat'],
        ],
        'pegawai_ak' => [
            'fk_id_riwayat_ak_cak_to_rak'  => ['id_riwayat_ak'],
            'fk_id_jabatan_cak_to_jab'     => ['id_jabatan'],
            'fk_id_pangkat_cak_to_pangkat' => ['id_pangkat'],
        ],
        'pegawai_keluarga' => [
            'fk_id_riwayat_keluarga_pegkeluarga_rwykeluarga' => ['id_riwayat_keluarga'],
        ],
        'pegawai_alamat' => [
            'fk_id_riwayat_alamat_pegalamat_riwalamat' => ['id_riwayat_alamat'],
            'fk_id_provinsi_pegalamat_provinsi'        => ['id_provinsi'],
            'fk_id_kabupaten_kota_pegalamat_kabkota'   => ['id_kabupaten_kota'],
            'fk_id_kecamatan_pegalamat_kecamatan'      => ['id_kecamatan'],
            'fk_id_kelurahan_pegalamat_kelurahan'      => ['id_kelurahan'],
        ],
        'pegawai_alamat_kantor' => [
            'pegawai_alamat_kantor_ibfk_1' => ['id_kabupaten_kota'],
            'pegawai_alamat_kantor_ibfk_2' => ['id_kecamatan'],
            'pegawai_alamat_kantor_ibfk_3' => ['id_kelurahan'],
            'pegawai_alamat_kantor_ibfk_4' => ['id_provinsi'],
            'pegawai_alamat_kantor_ibfk_5' => ['id_riwayat_alamat'],
        ],
        'pegawai_tanda_jasa' => [
            'fk_id_riwayat_tanda_jasa_ptj_to_rtj' => ['id_riwayat_tanda_jasa'],
            'fk_id_tanda_jasa_pegtj_to_tj'        => ['id_tanda_jasa'],
        ],
        'pegawai_mutasi_jabatan' => [
            'fk_id_unit_pmj_to_unit'                  => ['id_unit'],
            'fk_id_satker_pmj_to_satker'              => ['id_satker'],
            'fk_id_riwayat_mutasi_jabatan_pmj_to_rmj' => ['id_riwayat_mutasi_jabatan'],
            'fk_id_group_jabatan_pmj_to_gj'           => ['id_group_jabatan'],
            'fk_id_sub_group_jabatan_pmj_to_sgj'      => ['id_sub_group_jabatan'],
            'fk_id_jabatan_pmj_to_jabatan'            => ['id_jabatan'],
            'fk_id_atasan_es_1_pmj_to_jabatan'        => ['id_atasan_es_1'],
            'fk_id_atasan_es_2_pmj_to_jabatan'        => ['id_atasan_es_2'],
            'fk_id_atasan_es_3_pmj_to_jabatan'        => ['id_atasan_es_3'],
            'fk_id_atasan_es_4_pmj_to_jabatan'        => ['id_atasan_es_4'],
            'fk_id_jabatan_koord_pmj_to_jabkoor'      => ['id_jabatan_koord'],
            'fk_id_atasan_es_3_koord_pmj_to_jabkoor'  => ['id_atasan_es_3_koord'],
            'fk_id_atasan_es_4_koord_pmj_to_jabkoor'  => ['id_atasan_es_4_koord'],
            'pegawai_mutasi_jabatan_ibfk_01'          => ['id_gol_pppk'],
            'pegawai_mutasi_jabatan_ibfk_02'          => ['id_rumpun_jabatan'],
        ],
    ];

    private const PRIMARY_KEYS = [
        'pegawai'      => ['nip'],
        'pegawai_hist' => ['id_pegawai_hist'],
        'pegawai_foto' => ['id_pegawai_foto'],
    ];

    /**
     * FK yang dipasang saat CREATE (target sudah ada di `main` + `pegawai`), urut nama: [tabel anak, kolom, tabel
     * induk, kolom induk]. Semua RESTRICT/RESTRICT (diperiksa terpisah).
     */
    private const FOREIGN_KEYS = [
        'fk_id_agama_peg_to_agama'                          => ['pegawai', 'id_agama', 'agama', 'id_agama'],
        'fk_id_bidang_pendidikan_pegpend_to_bpend'          => ['pegawai_pendidikan', 'id_bidang_pendidikan', 'bidang_pendidikan', 'id_bidang_pendidikan'],
        'fk_id_diklat_pegdiklat_to_diklat'                  => ['pegawai_diklat', 'id_diklat', 'diklat', 'id_diklat'],
        'fk_id_jenis_hukdis_peg_hukdis_to_jenis_hukdis'     => ['pegawai_hukdis', 'id_jenis_hukdis', 'jenis_hukdis', 'id_jenis_hukdis'],
        'fk_id_jenis_kp_pcpns_to_jenis_kp'                  => ['pegawai_cpns', 'id_jenis_kp', 'jenis_kp', 'id_jenis_kp'],
        'fk_id_jenis_kp_peg_kp_to_jenis_kp'                 => ['pegawai_kp', 'id_jenis_kp', 'jenis_kp', 'id_jenis_kp'],
        'fk_id_jenis_kp_ppns_to_jenis_kp'                   => ['pegawai_pns', 'id_jenis_kp', 'jenis_kp', 'id_jenis_kp'],
        'fk_id_jenis_pegawai_peg_to_jenis_pegawai'          => ['pegawai', 'id_jenis_pegawai', 'jenis_pegawai', 'id_jenis_pegawai'],
        'fk_id_jenis_status_peg_to_jenis_status'            => ['pegawai', 'id_jenis_status', 'jenis_status', 'id_jenis_status'],
        'fk_id_jenjang_pendidikan_pegpend_to_jpend'         => ['pegawai_pendidikan', 'id_jenjang_pendidikan', 'jenjang_pendidikan', 'id_jenjang_pendidikan'],
        'fk_id_jurusan_pendidikan_pegpend_to_jupend'        => ['pegawai_pendidikan', 'id_jurusan_pendidikan', 'jurusan_pendidikan', 'id_jurusan_pendidikan'],
        'fk_id_kabupaten_kota_lahir_peg_to_kab_kota'        => ['pegawai', 'id_kabupaten_kota_lahir', 'kabupaten_kota', 'id_kabupaten_kota'],
        'fk_id_kabupaten_kota_pegalamat_kabkota'            => ['pegawai_alamat', 'id_kabupaten_kota', 'kabupaten_kota', 'id_kabupaten_kota'],
        'fk_id_kecamatan_pegalamat_kecamatan'               => ['pegawai_alamat', 'id_kecamatan', 'kecamatan', 'id_kecamatan'],
        'fk_id_kelurahan_pegalamat_kelurahan'               => ['pegawai_alamat', 'id_kelurahan', 'kelurahan', 'id_kelurahan'],
        'fk_id_pangkat_cak_to_pangkat'                      => ['pegawai_ak', 'id_pangkat', 'pangkat', 'id_pangkat'],
        'fk_id_pangkat_pcpns_to_pangkat'                    => ['pegawai_cpns', 'id_pangkat', 'pangkat', 'id_pangkat'],
        'fk_id_pangkat_peg_hukdis_to_pangkat'               => ['pegawai_hukdis', 'id_pangkat', 'pangkat', 'id_pangkat'],
        'fk_id_pangkat_peg_kp_to_pangkat'                   => ['pegawai_kp', 'id_pangkat', 'pangkat', 'id_pangkat'],
        'fk_id_pangkat_ppns_to_pangkat'                     => ['pegawai_pns', 'id_pangkat', 'pangkat', 'id_pangkat'],
        'fk_id_provinsi_lahir_peg_to_prov'                  => ['pegawai', 'id_provinsi_lahir', 'provinsi', 'id_provinsi'],
        'fk_id_provinsi_pegalamat_provinsi'                 => ['pegawai_alamat', 'id_provinsi', 'provinsi', 'id_provinsi'],
        'fk_id_tanda_jasa_pegtj_to_tj'                      => ['pegawai_tanda_jasa', 'id_tanda_jasa', 'tanda_jasa', 'id_tanda_jasa'],
        'fk_id_tingkat_hukdis_peg_hukdis_to_tingkat_hukdis' => ['pegawai_hukdis', 'id_tingkat_hukdis', 'tingkat_hukdis', 'id_tingkat_hukdis'],
        'fk_nip_cak_to_peg'                                 => ['pegawai_ak', 'nip', 'pegawai', 'nip'],
        'fk_nip_pcpns_to_pegawai'                           => ['pegawai_cpns', 'nip', 'pegawai', 'nip'],
        'fk_nip_pegalamat_to_pegawai'                       => ['pegawai_alamat', 'nip', 'pegawai', 'nip'],
        'fk_nip_pegdiklat_to_pegawai'                       => ['pegawai_diklat', 'nip', 'pegawai', 'nip'],
        'fk_nip_pegfoto_to_peg'                             => ['pegawai_foto', 'nip', 'pegawai', 'nip'],
        'fk_nip_peghukdis_to_pegawai'                       => ['pegawai_hukdis', 'nip', 'pegawai', 'nip'],
        'fk_nip_pegkeluarga_to_pegawai'                     => ['pegawai_keluarga', 'nip', 'pegawai', 'nip'],
        'fk_nip_pegkgb_to_pegawai'                          => ['pegawai_kgb', 'nip', 'pegawai', 'nip'],
        'fk_nip_pegkp_to_pegawai'                           => ['pegawai_kp', 'nip', 'pegawai', 'nip'],
        'fk_nip_pegpend_to_pegawai'                         => ['pegawai_pendidikan', 'nip', 'pegawai', 'nip'],
        'fk_nip_pegtj_to_pegawai'                           => ['pegawai_tanda_jasa', 'nip', 'pegawai', 'nip'],
        'fk_nip_pmj_to_pegawai'                             => ['pegawai_mutasi_jabatan', 'nip', 'pegawai', 'nip'],
        'fk_nip_ppns_to_pegawai'                            => ['pegawai_pns', 'nip', 'pegawai', 'nip'],
        'fk_peg_hist_ibfk_01'                               => ['pegawai_hist', 'nip', 'pegawai', 'nip'],
        'fk_peg_hist_ibfk_02'                               => ['pegawai_hist', 'id_provinsi_lahir', 'provinsi', 'id_provinsi'],
        'fk_peg_hist_ibfk_03'                               => ['pegawai_hist', 'id_kabupaten_kota_lahir', 'kabupaten_kota', 'id_kabupaten_kota'],
        'fk_peg_hist_ibfk_04'                               => ['pegawai_hist', 'id_agama', 'agama', 'id_agama'],
        'fk_peg_hist_ibfk_05'                               => ['pegawai_hist', 'id_jenis_pegawai', 'jenis_pegawai', 'id_jenis_pegawai'],
        'fk_peg_hist_ibfk_06'                               => ['pegawai_hist', 'id_jenis_status', 'jenis_status', 'id_jenis_status'],
        'pegawai_alamat_kantor_ibfk_1'                      => ['pegawai_alamat_kantor', 'id_kabupaten_kota', 'kabupaten_kota', 'id_kabupaten_kota'],
        'pegawai_alamat_kantor_ibfk_2'                      => ['pegawai_alamat_kantor', 'id_kecamatan', 'kecamatan', 'id_kecamatan'],
        'pegawai_alamat_kantor_ibfk_3'                      => ['pegawai_alamat_kantor', 'id_kelurahan', 'kelurahan', 'id_kelurahan'],
        'pegawai_alamat_kantor_ibfk_4'                      => ['pegawai_alamat_kantor', 'id_provinsi', 'provinsi', 'id_provinsi'],
        'pegawai_alamat_kantor_ibfk_6'                      => ['pegawai_alamat_kantor', 'nip', 'pegawai', 'nip'],
        'pegawai_kgb_ibfk_01'                               => ['pegawai_kgb', 'id_gol_pppk', 'gol_pppk', 'id_gol_pppk'],
        'pegawai_mutasi_jabatan_ibfk_01'                    => ['pegawai_mutasi_jabatan', 'id_gol_pppk', 'gol_pppk', 'id_gol_pppk'],
    ];

    /**
     * FK dari tabel B-01 yang dipasang migration lain (boleh ada, tidak dibandingkan di sini): snapshot → riwayat
     * (131000, DBV-013) dan snapshot → G-02 (ditahan sampai DBV-008). Nama = nama KEY yang sudah dibuat 120100.
     */
    private const LATER_FOREIGN_KEYS = [
        'fk_id_riwayat_kp_peg_kp_to_rwy_kp', 'fk_id_riwayat_kp_pcpns_to_rwy_kp', 'fk_id_riwayat_kp_ppns_to_rwy_kp',
        'fk_id_riwayat_kgb_pkgb_to_rkgb', 'fk_id_riwayat_pendidikan_pegpend_to_rpend',
        'fk_id_riwayat_diklat_pegdiklat_to_rwydiklat', 'fk_id_riwayat_hukdis_peg_hukdis_to_rwy_hukdis',
        'fk_id_riwayat_ak_cak_to_rak', 'fk_id_riwayat_keluarga_pegkeluarga_rwykeluarga',
        'fk_id_riwayat_alamat_pegalamat_riwalamat', 'pegawai_alamat_kantor_ibfk_5',
        'fk_id_riwayat_tanda_jasa_ptj_to_rtj', 'fk_id_riwayat_mutasi_jabatan_pmj_to_rmj',
        'fk_id_group_jabatan_pmj_to_gj', 'fk_id_sub_group_jabatan_pmj_to_sgj', 'fk_id_unit_pmj_to_unit',
        'fk_id_satker_pmj_to_satker', 'fk_id_jabatan_pmj_to_jabatan', 'fk_id_atasan_es_1_pmj_to_jabatan',
        'fk_id_atasan_es_2_pmj_to_jabatan', 'fk_id_atasan_es_3_pmj_to_jabatan', 'fk_id_atasan_es_4_pmj_to_jabatan',
        'fk_id_jabatan_koord_pmj_to_jabkoor', 'fk_id_atasan_es_3_koord_pmj_to_jabkoor',
        'fk_id_atasan_es_4_koord_pmj_to_jabkoor', 'pegawai_mutasi_jabatan_ibfk_02', 'fk_id_jabatan_cak_to_jab',
    ];

    private const CHECKS = [
        'pegawai'      => ['chk_pegawai_flag_update', 'chk_pegawai_jenis_kelamin', 'chk_pegawai_status'],
        'pegawai_hist' => ['chk_pegawai_hist_flag_update', 'chk_pegawai_hist_jenis_kelamin', 'chk_pegawai_hist_status'],
    ];

    /**
     * Baris minimal (kolom NOT NULL tanpa default) per snapshot, tanpa `nip`.
     */
    private const MINIMAL_SNAPSHOT_ROW = [
        'pegawai_kp'             => ['tmtsk' => '2022-04-01', 'tgl_sk' => '2022-03-15', 'jenis_kp' => 'Reguler', 'gol' => 'III', 'ruang' => 'c', 'gol_ruang' => 'III/c', 'pangkat' => 'Penata'],
        'pegawai_cpns'           => ['tmtsk' => '2010-01-01', 'tgl_sk' => '2009-12-01', 'jenis_kp' => 'Pengangkatan CPNS', 'gol' => 'III', 'ruang' => 'a', 'gol_ruang' => 'III/a', 'pangkat' => 'Penata Muda'],
        'pegawai_pns'            => ['tmtsk' => '2011-01-01', 'tgl_sk' => '2010-12-01', 'jenis_kp' => 'Pengangkatan PNS', 'gol' => 'III', 'ruang' => 'a', 'gol_ruang' => 'III/a', 'pangkat' => 'Penata Muda'],
        'pegawai_kgb'            => ['tmtsk' => '2024-04-01'],
        'pegawai_pendidikan'     => [],
        'pegawai_diklat'         => [],
        'pegawai_hukdis'         => [],
        'pegawai_ak'             => [],
        'pegawai_keluarga'       => [],
        'pegawai_alamat'         => [],
        'pegawai_alamat_kantor'  => [],
        'pegawai_tanda_jasa'     => [],
        'pegawai_mutasi_jabatan' => ['tmtsk' => '2020-01-01'],
    ];

    private const NIP = '198501012010011001';

    private const ERR_CHECK = [3819, 4025];

    private const ERR_FK_CHILD = [1452];

    private const ERR_FK_PARENT = [1451];

    private const ERR_DUPLICATE = [1062];

    /**
     * Tabel penghalang simulasi kegagalan up() (nama tabel snapshot di tengah urutan pembuatan).
     */
    private const BLOCKER = 'pegawai_alamat';

    /**
     * Migration yang sedang dilepas oleh test rollback, urut lepas (versi menurun).
     *
     * @var list<array{0: string, 1: Migration}>
     */
    private array $detached = [];

    /**
     * Test rollback/kegagalan melepas migration di tengah jalan; bila assertion gagal sebelum dipasang ulang, skema
     * dikembalikan di sini (penghalang dibuang, migration dipasang ulang urut naik) agar regress/migrate test berikutnya
     * konsisten dengan tabel migrations.
     */
    protected function tearDown(): void
    {
        if ($this->detached !== []) {
            if ($this->tableExists(self::BLOCKER) && array_key_exists('penghalang', $this->columns(self::BLOCKER))) {
                $this->db->query('DROP TABLE ' . $this->quoted(self::BLOCKER));
            }

            $this->reattach();
        }

        parent::tearDown();
    }

    public function testSchemaMatchesProposal(): void
    {
        $this->assertSchema();
    }

    public function testColumnDefaultsAndComments(): void
    {
        $pegawaiStatus = $this->columnInfo('pegawai', 'status');
        $this->assertSame('1', $this->unquote($pegawaiStatus['COLUMN_DEFAULT']));
        $this->assertSame('1: Aktif, 2: Tidak Aktif, 10: Dihapus', $pegawaiStatus['COLUMN_COMMENT']);

        $flag = $this->columnInfo('pegawai', 'flag_update');
        $this->assertSame('0', $this->unquote($flag['COLUMN_DEFAULT']));
        $this->assertSame('0: Tidak ada pengajuan, 1: Diajukan, 2: Ditolak/Dibatalkan, 3: Disetujui', $flag['COLUMN_COMMENT']);

        $histFlag = $this->columnInfo('pegawai_hist', 'flag_update');
        $this->assertSame('1', $this->unquote($histFlag['COLUMN_DEFAULT']), 'pegawai_hist.flag_update default 1 (Diajukan)');
        $this->assertSame('1: Diajukan, 2: Ditolak/Dibatalkan, 3: Disetujui', $histFlag['COLUMN_COMMENT']);

        foreach (['pegawai', 'pegawai_hist'] as $table) {
            $jk = $this->columnInfo($table, 'jenis_kelamin');
            $this->assertSame('1', $this->unquote($jk['COLUMN_DEFAULT']), "{$table}.jenis_kelamin default 1");
            $this->assertSame('1: Laki-laki, 2: Perempuan', $jk['COLUMN_COMMENT']);
            $this->assertSame('0', $this->unquote($this->columnInfo($table, 'siasn_flag')['COLUMN_DEFAULT']), "{$table}.siasn_flag default 0");

            $created = $this->columnInfo($table, 'created_at');
            $this->assertTrue($this->isCurrentTimestamp($created['COLUMN_DEFAULT']), "{$table}.created_at default CURRENT_TIMESTAMP");

            $updated = $this->columnInfo($table, 'updated_at');
            $this->assertTrue($this->isNullDefault($updated['COLUMN_DEFAULT']), "{$table}.updated_at default NULL");
            $this->assertStringContainsString('on update current_timestamp', strtolower((string) $updated['EXTRA']), "{$table}.updated_at ON UPDATE");
        }

        $this->assertSame('0', $this->unquote($this->columnInfo('pegawai_hist', 'show_notif')['COLUMN_DEFAULT']));
        $this->assertStringContainsString('auto_increment', strtolower((string) $this->columnInfo('pegawai_hist', 'id_pegawai_hist')['EXTRA']));
        $this->assertStringContainsString('auto_increment', strtolower((string) $this->columnInfo('pegawai_foto', 'id_pegawai_foto')['EXTRA']));

        foreach (self::SNAPSHOTS as $table) {
            $updated = $this->columnInfo($table, 'updated_at');
            $this->assertTrue($this->isCurrentTimestamp($updated['COLUMN_DEFAULT']), "{$table}.updated_at default CURRENT_TIMESTAMP");
            $this->assertStringContainsString('on update current_timestamp', strtolower((string) $updated['EXTRA']), "{$table}.updated_at ON UPDATE");
        }

        // [L] pegawai_mutasi_jabatan: default jenis_jabatan 1, jenis_jabatan_koord 3, jenis_mutasi 1.
        foreach (['jenis_jabatan' => '1', 'jenis_jabatan_koord' => '3', 'jenis_mutasi' => '1'] as $column => $default) {
            $this->assertSame($default, $this->unquote($this->columnInfo('pegawai_mutasi_jabatan', $column)['COLUMN_DEFAULT']), "pegawai_mutasi_jabatan.{$column}");
        }

        $this->assertSame('0', $this->unquote($this->columnInfo('pegawai_keluarga', 'jumlah_anak')['COLUMN_DEFAULT']));
    }

    public function testMigrationCreatesNoRows(): void
    {
        foreach (self::TABLES as $table) {
            $this->assertSame(0, $this->db->table($table)->countAllResults(), "{$table} harus kosong setelah migration");
        }
    }

    /**
     * Lapis DB (strictOn=true): CHECK, FK anak (1452), FK induk RESTRICT (1451), PK snapshot satu baris per nip.
     */
    public function testConstraintsAreEnforced(): void
    {
        $this->db->table('agama')->insert(['id_agama' => 1, 'agama' => 'Islam']);
        $this->db->table('pegawai')->insert(['nip' => self::NIP, 'nama' => 'Ahmad Wijaya', 'tgl_lahir' => '1985-01-01', 'id_agama' => 1]);
        $this->seeInDatabase('pegawai', ['nip' => self::NIP, 'status' => 1, 'flag_update' => 0, 'jenis_kelamin' => 1, 'deleted_at' => null]);

        // CHECK pegawai [V2].
        $this->assertDbError(self::ERR_CHECK, fn () => $this->insertPegawai(['status' => 3]));
        $this->assertDbError(self::ERR_CHECK, fn () => $this->insertPegawai(['flag_update' => 4]));
        $this->assertDbError(self::ERR_CHECK, fn () => $this->insertPegawai(['jenis_kelamin' => 0]));
        $this->insertPegawai(['status' => 10, 'flag_update' => 3, 'jenis_kelamin' => 2]);

        // FK master (target kosong kecuali agama 1).
        $this->assertDbError(self::ERR_FK_CHILD, fn () => $this->db->table('pegawai')->update(['id_agama' => 99], ['nip' => self::NIP]));
        $this->assertDbError(self::ERR_FK_CHILD, fn () => $this->db->table('pegawai')->update(['id_provinsi_lahir' => '31'], ['nip' => self::NIP]));

        // pegawai_hist: nip wajib ada & NOT NULL, flag_update 1/2/3 (default 1), status/jenis_kelamin seperti pegawai.
        $hist = ['nip' => self::NIP, 'nama' => 'Ahmad Wijaya', 'tgl_lahir' => '1985-01-01'];
        $this->db->table('pegawai_hist')->insert($hist);
        $this->seeInDatabase('pegawai_hist', ['nip' => self::NIP, 'flag_update' => 1, 'show_notif' => 0, 'approved_by' => null]);
        $this->assertDbError(self::ERR_CHECK, fn () => $this->db->table('pegawai_hist')->insert([...$hist, 'flag_update' => 0]));
        $this->assertDbError(self::ERR_CHECK, fn () => $this->db->table('pegawai_hist')->insert([...$hist, 'status' => 0]));
        $this->assertDbError(self::ERR_CHECK, fn () => $this->db->table('pegawai_hist')->insert([...$hist, 'jenis_kelamin' => 3]));
        $this->assertDbError(self::ERR_FK_CHILD, fn () => $this->db->table('pegawai_hist')->insert([...$hist, 'nip' => '199912312099121001']));
        $this->assertDbWriteFails(fn () => $this->db->table('pegawai_hist')->insert([...$hist, 'nip' => null]));

        // pegawai_foto: banyak baris per nip, nip wajib ada.
        $this->db->table('pegawai_foto')->insert(['nip' => self::NIP, 'foto' => 'arsip/' . self::NIP . '/umum/foto_1.jpg']);
        $this->db->table('pegawai_foto')->insert(['nip' => self::NIP, 'foto' => 'arsip/' . self::NIP . '/umum/foto_2.jpg']);
        $this->assertDbError(self::ERR_FK_CHILD, fn () => $this->db->table('pegawai_foto')->insert(['nip' => '199912312099121001', 'foto' => 'x.jpg']));

        // Snapshot: FK nip, satu baris per nip (PK), tanpa kolom status.
        foreach (self::MINIMAL_SNAPSHOT_ROW as $table => $row) {
            $this->assertDbError(self::ERR_FK_CHILD, fn () => $this->db->table($table)->insert(['nip' => '199912312099121001', ...$row]));
            $this->db->table($table)->insert(['nip' => self::NIP, ...$row]);
            $this->assertDbError(self::ERR_DUPLICATE, fn () => $this->db->table($table)->insert(['nip' => self::NIP, ...$row]));
            $this->assertSame(1, $this->db->table($table)->where('nip', self::NIP)->countAllResults(), "{$table} satu baris per nip");
        }

        // FK master snapshot (target kosong) ditolak; kolom G-02/riwayat tanpa FK di tahap ini boleh diisi bebas
        // hanya bila migration FK-nya belum jalan — tidak diuji di sini.
        $this->assertDbError(self::ERR_FK_CHILD, fn () => $this->db->table('pegawai_kp')->update(['id_pangkat' => 33], ['nip' => self::NIP]));
        $this->assertDbError(self::ERR_FK_CHILD, fn () => $this->db->table('pegawai_alamat')->update(['id_provinsi' => '31'], ['nip' => self::NIP]));
        $this->assertDbError(self::ERR_FK_CHILD, fn () => $this->db->table('pegawai_mutasi_jabatan')->update(['id_gol_pppk' => 1], ['nip' => self::NIP]));

        // RESTRICT: pegawai yang masih dirujuk tidak bisa dihapus keras maupun diganti NIP-nya (B-06 = salin → arahkan
        // ulang → hapus).
        $this->assertDbError(self::ERR_FK_PARENT, fn () => $this->db->table('pegawai')->where('nip', self::NIP)->delete());
        $this->assertDbError(self::ERR_FK_PARENT, fn () => $this->db->table('pegawai')->update(['nip' => '198501012010011999'], ['nip' => self::NIP]));
        $this->seeInDatabase('pegawai', ['nip' => self::NIP]);

        // Master yang dirujuk pegawai juga RESTRICT.
        $this->assertDbError(self::ERR_FK_PARENT, fn () => $this->db->table('agama')->where('id_agama', 1)->delete());
    }

    /**
     * down() CreatePegawaiSnapshot lalu CreatePegawai (setelah migration sesudahnya dilepas, termasuk DBV-013 bila ada)
     * menghapus ke-16 tabel; up() membuatnya kembali persis. Tabel lain tidak tersentuh.
     */
    public function testMigrationRollsBackAndUpAgain(): void
    {
        $this->detachFrom(self::FIRST_VERSION);

        foreach (self::TABLES as $table) {
            $this->assertFalse($this->tableExists($table), "{$table} harus terhapus oleh down()");
        }

        $this->assertTrue($this->tableExists('pengguna'), 'tabel auth tidak tersentuh');
        $this->assertTrue($this->tableExists('provinsi'), 'tabel master tidak tersentuh');
        $this->assertTrue($this->tableExists('faq_rate'), 'tabel FAQ tidak tersentuh');

        $this->reattach();
        $this->assertSchema();
    }

    /**
     * Bila salah satu CREATE snapshot gagal di tengah up(), tabel yang sempat dibuat PADA RUN ITU di-drop (urutan
     * terbalik) lalu error dilempar ulang; tabel yang sudah ada sebelum run tidak disentuh. Kegagalan disimulasikan
     * dengan tabel penghalang `pegawai_alamat` (CREATE ke-10 gagal, 1050).
     */
    public function testFailedSnapshotUpDropsOnlyTablesCreatedInThatRun(): void
    {
        $this->detachFrom(self::SNAPSHOT_VERSION);
        $this->db->query('CREATE TABLE ' . $this->quoted(self::BLOCKER) . ' (`penghalang` INT NOT NULL) ENGINE=InnoDB');

        $snapshot = $this->migrationInstance(self::SNAPSHOT_VERSION);
        $error    = null;

        try {
            $snapshot->up();
        } catch (Throwable $e) {
            $error = $e;
        }

        $this->assertNotNull($error, 'up() harus gagal karena pegawai_alamat sudah ada.');
        $this->assertStringContainsString('pegawai_alamat', $error->getMessage());

        foreach (self::SNAPSHOTS as $table) {
            if ($table !== self::BLOCKER) {
                $this->assertFalse($this->tableExists($table), "{$table} tidak boleh tertinggal setelah up() gagal");
            }
        }

        $this->assertSame(['penghalang' => 'int'], $this->columns(self::BLOCKER), 'tabel yang ada sebelum run tidak di-drop');
        $this->assertTrue($this->tableExists('pegawai'), 'pegawai (migration sebelumnya) tidak tersentuh');

        $this->db->query('DROP TABLE ' . $this->quoted(self::BLOCKER));
        $this->reattach();
        $this->assertSchema();
    }

    private function insertPegawai(array $overrides): void
    {
        static $seq = 0;
        $seq++;

        $this->db->table('pegawai')->insert([
            'nip'       => sprintf('19700101200001%04d', $seq),
            'nama'      => "Pegawai Uji {$seq}",
            'tgl_lahir' => '1970-01-01',
            ...$overrides,
        ]);
    }

    private function assertSchema(): void
    {
        foreach (self::TABLES as $table) {
            $this->assertSame(self::COLUMNS[$table], $this->columns($table), "kolom {$table}");
            $this->assertSame('utf8mb4_unicode_ci', $this->tableInfo($table)['TABLE_COLLATION'] ?? null, "collation {$table}");
            $this->assertSame('InnoDB', $this->tableInfo($table)['ENGINE'] ?? null, "engine {$table}");

            foreach ($this->stringColumnCollations($table) as $column => $collation) {
                $this->assertSame('utf8mb4_unicode_ci', $collation, "collation {$table}.{$column}");
            }

            [$primary, $others] = $this->indexes($table);
            $this->assertSame(self::PRIMARY_KEYS[$table] ?? ['nip'], $primary, "PRIMARY {$table}");
            $this->assertSame(self::INDEXES[$table], $others, "index {$table}");
            $this->assertSame(self::CHECKS[$table] ?? [], $this->checkConstraints($table), "CHECK {$table}");
        }

        [$createTime, $later, $rules] = $this->foreignKeysFromTables();
        $this->assertSame(self::FOREIGN_KEYS, $createTime, 'FK B-01 yang dipasang saat CREATE');
        $this->assertSame([], array_values(array_diff($later, self::LATER_FOREIGN_KEYS)), 'FK lain dari tabel B-01 harus terdaftar');
        $this->assertSame([], $rules, 'semua FK dari tabel B-01 RESTRICT/RESTRICT');
    }

    /**
     * Lepas (down) semua migration App yang sudah jalan sejak $version, urut versi menurun.
     */
    private function detachFrom(string $version): void
    {
        $applied = $this->db->table('migrations')->select('version, class')->where('namespace', 'App')
            ->where('version >=', $version)->orderBy('version', 'DESC')->get()->getResultArray();

        $this->assertNotSame([], $applied, "prasyarat: migration {$version} sudah jalan");

        foreach ($applied as $row) {
            $migration = $this->migrationInstance((string) $row['version']);
            $migration->down();
            $this->detached[] = [(string) $row['version'], $migration];
        }
    }

    /**
     * Pasang ulang (up) migration yang dilepas, urut versi menaik.
     */
    private function reattach(): void
    {
        foreach (array_reverse($this->detached) as [, $migration]) {
            $migration->up();
        }

        $this->detached = [];
    }

    private function migrationInstance(string $version): Migration
    {
        $files = glob(APPPATH . 'Database/Migrations/' . $version . '_*.php') ?: [];

        if (count($files) !== 1) {
            throw new RuntimeException("File migration {$version} tidak ditemukan (atau lebih dari satu).");
        }

        require_once $files[0];
        $class = 'App\\Database\\Migrations\\' . substr(basename($files[0], '.php'), strlen($version) + 1);

        /** @var Migration $migration */
        $migration = new $class();

        return $migration;
    }

    private function assertDbError(array $codes, callable $write): void
    {
        $code = null;

        try {
            if ($write() === false) {
                $code = (int) ($this->db->error()['code'] ?? 0);
            }
        } catch (Throwable $e) {
            for ($t = $e; $t !== null; $t = $t->getPrevious()) {
                if (in_array((int) $t->getCode(), $codes, true)) {
                    $code = (int) $t->getCode();

                    break;
                }
            }

            $code ??= (int) $e->getCode();
        }

        $this->assertContains($code, $codes, 'kode error DB yang diharapkan: ' . implode('/', $codes));
    }

    private function assertDbWriteFails(callable $write): void
    {
        $failed = false;

        try {
            $failed = $write() === false;
        } catch (Throwable) {
            $failed = true;
        }

        $this->assertTrue($failed, 'Constraint DB harus menolak penulisan ini.');
    }

    private function unquote(mixed $value): string
    {
        return trim((string) $value, "'");
    }

    /**
     * Default NULL: MySQL 8 mengembalikan null, MariaDB ≥ 10.2.7 mengembalikan string 'NULL'.
     */
    private function isNullDefault(mixed $value): bool
    {
        return $value === null || strtoupper((string) $value) === 'NULL';
    }

    private function isCurrentTimestamp(mixed $value): bool
    {
        return strtolower(trim((string) $value, "'()")) === 'current_timestamp';
    }

    private function quoted(string $table): string
    {
        return $this->db->escapeIdentifiers($this->db->prefixTable($table));
    }

    private function tableExists(string $table): bool
    {
        return (int) $this->db->query(
            'SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
            [$this->db->getDatabase(), $this->db->prefixTable($table)],
        )->getRowArray()['n'] > 0;
    }

    /**
     * @return array<string, string> kolom => "COLUMN_TYPE[ null]", urut posisi
     */
    private function columns(string $table): array
    {
        $rows = $this->db->query(
            'SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION',
            [$this->db->getDatabase(), $this->db->prefixTable($table)],
        )->getResultArray();

        $columns = [];

        foreach ($rows as $row) {
            // MariaDB/MySQL lama menulis lebar tampilan (tinyint(4), int(11), year(4)); samakan dengan MySQL 8.
            // tinyint(1) tetap: kedua server menampilkannya, jadi TINYINT(1) [L] terbukti persis.
            $type = (string) preg_replace('/^(tinyint|smallint|mediumint|int|bigint)\((?!1\))\d+\)/', '$1', strtolower((string) $row['COLUMN_TYPE']));
            $type = $type === 'year(4)' ? 'year' : $type;

            $columns[(string) $row['COLUMN_NAME']] = $type . ($row['IS_NULLABLE'] === 'YES' ? ' null' : '');
        }

        return $columns;
    }

    /**
     * @return array<string, mixed>
     */
    private function columnInfo(string $table, string $column): array
    {
        return $this->db->query(
            'SELECT EXTRA, COLUMN_DEFAULT, COLUMN_COMMENT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$this->db->getDatabase(), $this->db->prefixTable($table), $column],
        )->getRowArray() ?? [];
    }

    /**
     * @return array<string, string> kolom string => collation
     */
    private function stringColumnCollations(string $table): array
    {
        $rows = $this->db->query(
            'SELECT COLUMN_NAME, COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLLATION_NAME IS NOT NULL',
            [$this->db->getDatabase(), $this->db->prefixTable($table)],
        )->getResultArray();

        return array_column($rows, 'COLLATION_NAME', 'COLUMN_NAME');
    }

    /**
     * @return array<string, mixed>
     */
    private function tableInfo(string $table): array
    {
        return $this->db->query(
            'SELECT TABLE_COLLATION, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
            [$this->db->getDatabase(), $this->db->prefixTable($table)],
        )->getRowArray() ?? [];
    }

    /**
     * @return array{0: list<string>, 1: array<string, list<string>>} [kolom PRIMARY, index lain urut konstanta]
     */
    private function indexes(string $table): array
    {
        $rows = $this->db->query(
            'SELECT INDEX_NAME, NON_UNIQUE, INDEX_TYPE, COLUMN_NAME FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY INDEX_NAME, SEQ_IN_INDEX',
            [$this->db->getDatabase(), $this->db->prefixTable($table)],
        )->getResultArray();

        $primary = [];
        $others  = [];

        foreach ($rows as $row) {
            $name = (string) $row['INDEX_NAME'];

            if ($name === 'PRIMARY') {
                $primary[] = (string) $row['COLUMN_NAME'];

                continue;
            }

            $this->assertSame('1', (string) $row['NON_UNIQUE'], "{$table}.{$name} non-unique");
            $this->assertSame('BTREE', (string) $row['INDEX_TYPE'], "{$table}.{$name} BTREE");
            $others[$name][] = (string) $row['COLUMN_NAME'];
        }

        $ordered = [];

        foreach (array_keys(self::INDEXES[$table]) as $name) {
            if (isset($others[$name])) {
                $ordered[$name] = $others[$name];
                unset($others[$name]);
            }
        }

        return [$primary, $ordered + $others];
    }

    /**
     * FK dari ke-16 tabel B-01: [FK saat CREATE (target main/pegawai) urut nama, nama FK lain, FK yang bukan
     * RESTRICT/RESTRICT].
     *
     * @return array{0: array<string, array{0: string, 1: string, 2: string, 3: string}>, 1: list<string>, 2: list<string>}
     */
    private function foreignKeysFromTables(): array
    {
        $tables = array_map(fn (string $t): string => $this->db->prefixTable($t), self::TABLES);

        $rows = $this->db->query(
            'SELECT k.CONSTRAINT_NAME, k.TABLE_NAME, k.COLUMN_NAME, k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME, r.UPDATE_RULE, r.DELETE_RULE
             FROM information_schema.KEY_COLUMN_USAGE k
             JOIN information_schema.REFERENTIAL_CONSTRAINTS r
               ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME AND r.TABLE_NAME = k.TABLE_NAME
             WHERE k.TABLE_SCHEMA = ? AND k.REFERENCED_TABLE_NAME IS NOT NULL AND k.TABLE_NAME IN ?
             ORDER BY k.CONSTRAINT_NAME',
            [$this->db->getDatabase(), $tables],
        )->getResultArray();

        $createTime  = [];
        $later       = [];
        $notRestrict = [];

        foreach ($rows as $row) {
            $name = (string) $row['CONSTRAINT_NAME'];

            if ($row['UPDATE_RULE'] !== 'RESTRICT' || $row['DELETE_RULE'] !== 'RESTRICT') {
                $notRestrict[] = "{$name} ({$row['UPDATE_RULE']}/{$row['DELETE_RULE']})";
            }

            if (in_array($name, self::LATER_FOREIGN_KEYS, true)) {
                $later[] = $name;

                continue;
            }

            $createTime[$name] = [
                $this->stripPrefix((string) $row['TABLE_NAME']),
                (string) $row['COLUMN_NAME'],
                $this->stripPrefix((string) $row['REFERENCED_TABLE_NAME']),
                (string) $row['REFERENCED_COLUMN_NAME'],
            ];
        }

        ksort($createTime);

        return [$createTime, $later, $notRestrict];
    }

    /**
     * @return list<string> nama CHECK tabel ini, urut nama
     */
    private function checkConstraints(string $table): array
    {
        $rows = $this->db->query(
            "SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = ? AND TABLE_NAME = ? AND CONSTRAINT_TYPE = 'CHECK'",
            [$this->db->getDatabase(), $this->db->prefixTable($table)],
        )->getResultArray();

        $names = array_map(static fn (array $r): string => (string) $r['CONSTRAINT_NAME'], $rows);
        sort($names);

        return $names;
    }

    private function stripPrefix(string $table): string
    {
        $prefix = $this->db->getPrefix();

        return $prefix !== '' && str_starts_with($table, $prefix) ? substr($table, strlen($prefix)) : $table;
    }
}
