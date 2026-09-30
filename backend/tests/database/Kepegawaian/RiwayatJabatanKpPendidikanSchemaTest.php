<?php

declare(strict_types=1);

namespace Tests\Database\Kepegawaian;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\SkemaKepegawaianTestTrait;
use Throwable;

/**
 * DBV-013 — skema B-02 kelompok 2 hasil migration 2026-09-30-130100_CreateRiwayatJabatan, 130200_CreateRiwayatKpKgb,
 * 130300_CreateRiwayatPendidikanDiklatSeminar harus sama dengan yang diajukan ke DB Validator
 * (backend/docs/db-review/B-01-B-02-pegawai-riwayat-schema.md Bagian 2.2): kolom (tipe, NULL, urutan), default,
 * collation, PRIMARY/index, FK (nama [K-erd], RESTRICT/RESTRICT), CHECK (nama & domain), perilaku constraint,
 * down()/up() bersih, dan pembersihan saat up() gagal di tengah. FK ke tabel G-02 (ditahan sampai DBV-008) hanya
 * KEY-nya; constraint-nya boleh muncul kelak (self::LATER_FOREIGN_KEYS).
 *
 * @internal
 */
final class RiwayatJabatanKpPendidikanSchemaTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use SkemaKepegawaianTestTrait;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = null;

    private const NIP = '198501012010011001';

    private const NIP_ASING = '199912312099121001';

    private const ERR_CHECK = [3819, 4025];

    private const FIRST_VERSION = '2026-09-30-130100';

    private const BLOCKER_VERSION = '2026-09-30-130300';

    /**
     * Tabel penghalang (CREATE ke-2 migration 130300) dan tabel lain migration itu yang tidak boleh tertinggal.
     */
    private const BLOCKER = 'riwayat_diklat';

    private const BLOCKER_SIBLINGS = ['riwayat_pendidikan', 'riwayat_seminar'];

    private const TABLES = [
        'riwayat_mutasi_jabatan', 'pegawai_plt', 'pegawai_plh', 'riwayat_kp', 'riwayat_kgb', 'riwayat_pendidikan',
        'riwayat_diklat', 'riwayat_seminar',
    ];

    /**
     * FK G-02 (migration AddFkG02Riwayat, ditahan sampai DBV-008): KEY-nya sudah ada, constraint-nya boleh muncul kelak.
     */
    private const LATER_FOREIGN_KEYS = [
        'fk_id_group_jabatan_rwymj_to_gj', 'fk_id_sub_group_jabatan_rwymj_to_sgj', 'fk_id_unit_rwymj_to_unit',
        'fk_id_satker_rwymj_to_satker', 'fk_id_jabatan_rwymj_to_jabatan', 'fk_id_atasan_es_1_rwymj_to_jabatan',
        'fk_id_atasan_es_2_rwymj_to_jabatan', 'fk_id_atasan_es_3_rwymj_to_jabatan', 'fk_id_atasan_es_4_rwymj_to_jabatan',
        'fk_id_jabatan_koord_rmj_to_jabkoor', 'fk_id_atasan_es_3_koord_rmj_to_jabkoor',
        'fk_id_atasan_es_4_koord_rmj_to_jabkoor', 'riwayat_mutasi_jabatan_ibfk_02',
        'fk_id_group_jabatan_plt_to_gj', 'fk_id_group_jabatan_plt_plt_to_gj', 'fk_id_sub_group_jabatan_plt_to_sgj',
        'fk_id_sub_group_jabatan_plt_plt_to_sgj', 'fk_id_unit_plt_to_unit', 'fk_id_unit_plt_plt_to_unit',
        'fk_id_satker_plt_to_satker', 'fk_id_satker_plt_plt_to_satker', 'fk_id_jabatan_plt_to_jabatan',
        'fk_id_jabatan_koord_plt_to_jabkoord',
        'fk_pegawai_plh_ibfk_02', 'fk_pegawai_plh_ibfk_03', 'fk_pegawai_plh_ibfk_04', 'fk_pegawai_plh_ibfk_05',
        'fk_pegawai_plh_ibfk_06', 'fk_pegawai_plh_ibfk_07', 'fk_pegawai_plh_ibfk_08', 'fk_pegawai_plh_ibfk_09',
        'fk_pegawai_plh_ibfk_10', 'fk_pegawai_plh_ibfk_11',
    ];

    private const COLUMNS = [
        'riwayat_mutasi_jabatan' => [
            'id_riwayat_mutasi_jabatan' => 'int',
            'nip'                       => 'varchar(30)',
            'id_group_jabatan'          => 'int null',
            'jenis_jabatan'             => 'tinyint(1) null',
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
            'siasn_flag'                => 'tinyint',
            'siasn_error'               => 'text null',
            'siasn_lu'                  => 'datetime null',
            'status'                    => 'tinyint',
            'reason_note'               => 'text null',
            'approved_by'               => 'int null',
            'show_notif'                => 'tinyint',
            'notif_date'                => 'datetime null',
            'show_ua_upt'               => 'tinyint null',
            'show_ua_deputi'            => 'tinyint null',
            'show_ua_biro'              => 'tinyint null',
            'created_at'                => 'datetime',
            'updated_at'                => 'datetime null',
            'updated_by'                => 'int null',
        ],
        'pegawai_plt' => [
            'id_pegawai_plt'           => 'int',
            'status'                   => 'tinyint',
            'jenis_jabatan'            => 'tinyint(1)',
            'jenis_jabatan_koord'      => 'tinyint(1) null',
            'tgl_mulai'                => 'date',
            'tgl_akhir'                => 'date',
            'id_group_jabatan'         => 'int null',
            'id_sub_group_jabatan'     => 'int null',
            'id_unit'                  => 'int null',
            'id_satker'                => 'int null',
            'id_jabatan'               => 'int null',
            'id_jabatan_koord'         => 'int null',
            'id_group_jabatan_plt'     => 'int null',
            'id_sub_group_jabatan_plt' => 'int null',
            'id_unit_plt'              => 'int null',
            'id_satker_plt'            => 'int null',
            'nip_plt'                  => 'varchar(30)',
            'lampiran'                 => 'varchar(255) null',
            'created_at'               => 'datetime',
            'updated_at'               => 'datetime null',
            'updated_by'               => 'int null',
        ],
        'pegawai_plh' => [
            'id_pegawai_plh'           => 'int',
            'status'                   => 'tinyint',
            'jenis_jabatan'            => 'tinyint(1)',
            'jenis_jabatan_koord'      => 'tinyint(1) null',
            'tgl_mulai'                => 'date',
            'tgl_akhir'                => 'date',
            'id_group_jabatan'         => 'int null',
            'id_sub_group_jabatan'     => 'int null',
            'id_unit'                  => 'int null',
            'id_satker'                => 'int null',
            'id_jabatan'               => 'int null',
            'id_jabatan_koord'         => 'int null',
            'id_group_jabatan_plh'     => 'int null',
            'id_sub_group_jabatan_plh' => 'int null',
            'id_unit_plh'              => 'int null',
            'id_satker_plh'            => 'int null',
            'nip_plh'                  => 'varchar(30)',
            'lampiran'                 => 'varchar(255) null',
            'created_at'               => 'datetime',
            'updated_at'               => 'datetime null',
            'updated_by'               => 'int null',
        ],
        'riwayat_kp' => [
            'id_riwayat_kp'          => 'int',
            'nip'                    => 'varchar(30)',
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
            'status'                 => 'tinyint',
            'reason_note'            => 'text null',
            'approved_by'            => 'int null',
            'show_notif'             => 'tinyint',
            'notif_date'             => 'datetime null',
            'show_ua_upt'            => 'tinyint null',
            'show_ua_deputi'         => 'tinyint null',
            'show_ua_biro'           => 'tinyint null',
            'created_at'             => 'datetime',
            'updated_at'             => 'datetime null',
            'updated_by'             => 'int null',
        ],
        'riwayat_kgb' => [
            'id_riwayat_kgb' => 'int',
            'nip'            => 'varchar(30)',
            'id_gol_pppk'    => 'tinyint null',
            'tmtsk'          => 'date',
            'no_sk'          => 'varchar(100) null',
            'tgl_sk'         => 'date null',
            'mker_gol_th'    => 'tinyint null',
            'gaji_pokok'     => 'int null',
            'jym'            => 'varchar(255) null',
            'keterangan'     => 'tinytext null',
            'status'         => 'tinyint',
            'reason_note'    => 'text null',
            'approved_by'    => 'int null',
            'show_notif'     => 'tinyint',
            'notif_date'     => 'datetime null',
            'show_ua_upt'    => 'tinyint null',
            'show_ua_deputi' => 'tinyint null',
            'show_ua_biro'   => 'tinyint null',
            'created_at'     => 'datetime',
            'updated_at'     => 'datetime null',
            'updated_by'     => 'int null',
        ],
        'riwayat_pendidikan' => [
            'id_riwayat_pendidikan'      => 'int',
            'nip'                        => 'varchar(30)',
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
            'glr_awal'                   => 'varchar(50) null',
            'glr_akhir'                  => 'varchar(50) null',
            'keterangan'                 => 'tinytext null',
            'id_rw_siasn'                => 'varchar(100) null',
            'status'                     => 'tinyint',
            'reason_note'                => 'text null',
            'approved_by'                => 'int null',
            'show_notif'                 => 'tinyint',
            'notif_date'                 => 'datetime null',
            'show_ua_upt'                => 'tinyint null',
            'show_ua_deputi'             => 'tinyint null',
            'show_ua_biro'               => 'tinyint null',
            'created_at'                 => 'datetime',
            'updated_at'                 => 'datetime null',
            'updated_by'                 => 'int null',
        ],
        'riwayat_diklat' => [
            'id_riwayat_diklat'      => 'int',
            'nip'                    => 'varchar(30)',
            'jenis_diklat'           => 'tinyint(1)',
            'id_diklat'              => 'tinyint null',
            'nama_diklat'            => 'varchar(255) null',
            'nama_diklat_lain'       => 'varchar(255) null',
            'id_sub_group_jabatan'   => 'int null',
            'sub_group_jabatan'      => 'varchar(100) null',
            'id_rumpun_sertifikasi'  => 'int null',
            'rumpun_sertifikasi'     => 'varchar(255) null',
            'id_lembaga_sertifikasi' => 'int null',
            'instansi_penyelenggara' => 'varchar(255) null',
            'deskripsi'              => 'text null',
            'tgl_sertifikat'         => 'date',
            'no_sertifikat'          => 'varchar(100) null',
            'jumlah_jp'              => 'smallint null',
            'mb_sertifikat_awal'     => 'date null',
            'mb_sertifikat_akhir'    => 'date null',
            'keterangan'             => 'text null',
            'id_rw_siasn'            => 'varchar(100) null',
            'siasn_flag'             => 'tinyint',
            'siasn_error'            => 'text null',
            'status'                 => 'tinyint',
            'reason_note'            => 'text null',
            'approved_by'            => 'int null',
            'show_notif'             => 'tinyint',
            'notif_date'             => 'datetime null',
            'show_ua_upt'            => 'tinyint null',
            'show_ua_deputi'         => 'tinyint null',
            'show_ua_biro'           => 'tinyint null',
            'created_at'             => 'datetime',
            'updated_at'             => 'datetime null',
            'updated_by'             => 'int null',
        ],
        'riwayat_seminar' => [
            'id_riwayat_seminar'     => 'int',
            'nip'                    => 'varchar(30)',
            'jenis_seminar'          => 'tinyint',
            'bidang_seminar'         => 'varchar(255) null',
            'nama_seminar'           => 'varchar(255) null',
            'tgl_sertifikat'         => 'date',
            'no_sertifikat'          => 'varchar(100) null',
            'jumlah_jp'              => 'smallint null',
            'instansi_penyelenggara' => 'varchar(255) null',
            'keterangan'             => 'text null',
            'id_rw_siasn'            => 'varchar(100) null',
            'siasn_flag'             => 'tinyint',
            'siasn_error'            => 'text null',
            'status'                 => 'tinyint',
            'reason_note'            => 'text null',
            'approved_by'            => 'int null',
            'show_notif'             => 'tinyint',
            'notif_date'             => 'datetime null',
            'show_ua_upt'            => 'tinyint null',
            'show_ua_deputi'         => 'tinyint null',
            'show_ua_biro'           => 'tinyint null',
            'created_at'             => 'datetime',
            'updated_at'             => 'datetime null',
            'updated_by'             => 'int null',
        ],
    ];

    private const PRIMARY_KEYS = [
        'riwayat_mutasi_jabatan' => ['id_riwayat_mutasi_jabatan'],
        'pegawai_plt'            => ['id_pegawai_plt'],
        'pegawai_plh'            => ['id_pegawai_plh'],
        'riwayat_kp'             => ['id_riwayat_kp'],
        'riwayat_kgb'            => ['id_riwayat_kgb'],
        'riwayat_pendidikan'     => ['id_riwayat_pendidikan'],
        'riwayat_diklat'         => ['id_riwayat_diklat'],
        'riwayat_seminar'        => ['id_riwayat_seminar'],
    ];

    private const INDEXES = [
        'riwayat_mutasi_jabatan' => [
            'fk_id_atasan_es_1_rwymj_to_jabatan'     => ['id_atasan_es_1'],
            'fk_id_atasan_es_2_rwymj_to_jabatan'     => ['id_atasan_es_2'],
            'fk_id_atasan_es_3_koord_rmj_to_jabkoor' => ['id_atasan_es_3_koord'],
            'fk_id_atasan_es_3_rwymj_to_jabatan'     => ['id_atasan_es_3'],
            'fk_id_atasan_es_4_koord_rmj_to_jabkoor' => ['id_atasan_es_4_koord'],
            'fk_id_atasan_es_4_rwymj_to_jabatan'     => ['id_atasan_es_4'],
            'fk_id_group_jabatan_rwymj_to_gj'        => ['id_group_jabatan'],
            'fk_id_jabatan_koord_rmj_to_jabkoor'     => ['id_jabatan_koord'],
            'fk_id_jabatan_rwymj_to_jabatan'         => ['id_jabatan'],
            'fk_id_satker_rwymj_to_satker'           => ['id_satker'],
            'fk_id_sub_group_jabatan_rwymj_to_sgj'   => ['id_sub_group_jabatan'],
            'fk_id_unit_rwymj_to_unit'               => ['id_unit'],
            'fk_nip_rwymutasijabatan_to_pegawai'     => ['nip'],
            'riwayat_mutasi_jabatan_ibfk_01'         => ['id_gol_pppk'],
            'riwayat_mutasi_jabatan_ibfk_02'         => ['id_rumpun_jabatan'],
        ],
        'pegawai_plt' => [
            'fk_id_group_jabatan_plt_plt_to_gj'      => ['id_group_jabatan_plt'],
            'fk_id_group_jabatan_plt_to_gj'          => ['id_group_jabatan'],
            'fk_id_jabatan_koord_plt_to_jabkoord'    => ['id_jabatan_koord'],
            'fk_id_jabatan_plt_to_jabatan'           => ['id_jabatan'],
            'fk_id_satker_plt_plt_to_satker'         => ['id_satker_plt'],
            'fk_id_satker_plt_to_satker'             => ['id_satker'],
            'fk_id_sub_group_jabatan_plt_plt_to_sgj' => ['id_sub_group_jabatan_plt'],
            'fk_id_sub_group_jabatan_plt_to_sgj'     => ['id_sub_group_jabatan'],
            'fk_id_unit_plt_plt_to_unit'             => ['id_unit_plt'],
            'fk_id_unit_plt_to_unit'                 => ['id_unit'],
            'fk_nip_plt_plt_to_pegawai'              => ['nip_plt'],
        ],
        'pegawai_plh' => [
            'fk_pegawai_plh_ibfk_01' => ['nip_plh'],
            'fk_pegawai_plh_ibfk_02' => ['id_jabatan'],
            'fk_pegawai_plh_ibfk_03' => ['id_jabatan_koord'],
            'fk_pegawai_plh_ibfk_04' => ['id_group_jabatan'],
            'fk_pegawai_plh_ibfk_05' => ['id_sub_group_jabatan'],
            'fk_pegawai_plh_ibfk_06' => ['id_unit'],
            'fk_pegawai_plh_ibfk_07' => ['id_satker'],
            'fk_pegawai_plh_ibfk_08' => ['id_group_jabatan_plh'],
            'fk_pegawai_plh_ibfk_09' => ['id_sub_group_jabatan_plh'],
            'fk_pegawai_plh_ibfk_10' => ['id_unit_plh'],
            'fk_pegawai_plh_ibfk_11' => ['id_satker_plh'],
        ],
        'riwayat_kp' => [
            'fk_id_jenis_kp_rwy_kp_to_jenis_kp' => ['id_jenis_kp'],
            'fk_id_pangkat_rwy_kp_to_pangkat'   => ['id_pangkat'],
            'fk_nip_rwykp_to_pegawai'           => ['nip'],
        ],
        'riwayat_kgb' => [
            'fk_nip_rwykgb_to_pegawai' => ['nip'],
            'riwayat_kgb_ibfk_01'      => ['id_gol_pppk'],
        ],
        'riwayat_pendidikan' => [
            'fk_id_bidang_pendidikan_rpend_to_bpend'  => ['id_bidang_pendidikan'],
            'fk_id_jenjang_pendidikan_rpend_to_jpend' => ['id_jenjang_pendidikan'],
            'fk_id_jurusan_pendidikan_rpend_jupend'   => ['id_jurusan_pendidikan'],
            'fk_nip_rwypendidikan_to_pegawai'         => ['nip'],
        ],
        'riwayat_diklat' => [
            'fk_id_diklat_rdiklat_to_diklat' => ['id_diklat'],
            'fk_nip_rwydiklat_to_pegawai'    => ['nip'],
        ],
        'riwayat_seminar' => [
            'fk_nip_rwyseminar_to_pegawai' => ['nip'],
        ],
    ];

    private const UNIQUES = [];

    private const FOREIGN_KEYS = [
        'fk_id_bidang_pendidikan_rpend_to_bpend'  => ['riwayat_pendidikan', 'id_bidang_pendidikan', 'bidang_pendidikan', 'id_bidang_pendidikan'],
        'fk_id_diklat_rdiklat_to_diklat'          => ['riwayat_diklat', 'id_diklat', 'diklat', 'id_diklat'],
        'fk_id_jenis_kp_rwy_kp_to_jenis_kp'       => ['riwayat_kp', 'id_jenis_kp', 'jenis_kp', 'id_jenis_kp'],
        'fk_id_jenjang_pendidikan_rpend_to_jpend' => ['riwayat_pendidikan', 'id_jenjang_pendidikan', 'jenjang_pendidikan', 'id_jenjang_pendidikan'],
        'fk_id_jurusan_pendidikan_rpend_jupend'   => ['riwayat_pendidikan', 'id_jurusan_pendidikan', 'jurusan_pendidikan', 'id_jurusan_pendidikan'],
        'fk_id_pangkat_rwy_kp_to_pangkat'         => ['riwayat_kp', 'id_pangkat', 'pangkat', 'id_pangkat'],
        'fk_nip_plt_plt_to_pegawai'               => ['pegawai_plt', 'nip_plt', 'pegawai', 'nip'],
        'fk_nip_rwydiklat_to_pegawai'             => ['riwayat_diklat', 'nip', 'pegawai', 'nip'],
        'fk_nip_rwykgb_to_pegawai'                => ['riwayat_kgb', 'nip', 'pegawai', 'nip'],
        'fk_nip_rwykp_to_pegawai'                 => ['riwayat_kp', 'nip', 'pegawai', 'nip'],
        'fk_nip_rwymutasijabatan_to_pegawai'      => ['riwayat_mutasi_jabatan', 'nip', 'pegawai', 'nip'],
        'fk_nip_rwypendidikan_to_pegawai'         => ['riwayat_pendidikan', 'nip', 'pegawai', 'nip'],
        'fk_nip_rwyseminar_to_pegawai'            => ['riwayat_seminar', 'nip', 'pegawai', 'nip'],
        'fk_pegawai_plh_ibfk_01'                  => ['pegawai_plh', 'nip_plh', 'pegawai', 'nip'],
        'riwayat_kgb_ibfk_01'                     => ['riwayat_kgb', 'id_gol_pppk', 'gol_pppk', 'id_gol_pppk'],
        'riwayat_mutasi_jabatan_ibfk_01'          => ['riwayat_mutasi_jabatan', 'id_gol_pppk', 'gol_pppk', 'id_gol_pppk'],
    ];

    private const CHECKS = [
        'chk_pegawai_plh_status'            => ['pegawai_plh', 'statusin1,2,10'],
        'chk_pegawai_plt_status'            => ['pegawai_plt', 'statusin1,2,10'],
        'chk_riwayat_diklat_jenis_diklat'   => ['riwayat_diklat', 'jenis_diklatbetween1and5'],
        'chk_riwayat_diklat_status'         => ['riwayat_diklat', 'statusin0,1,2,3,10'],
        'chk_riwayat_kgb_status'            => ['riwayat_kgb', 'statusin0,1,2,3,10'],
        'chk_riwayat_kp_status'             => ['riwayat_kp', 'statusin0,1,2,3,10'],
        'chk_riwayat_mutasi_jabatan_status' => ['riwayat_mutasi_jabatan', 'statusin0,1,2,3,10'],
        'chk_riwayat_pendidikan_status'     => ['riwayat_pendidikan', 'statusin0,1,2,3,10'],
        'chk_riwayat_seminar_status'        => ['riwayat_seminar', 'statusin0,1,2,3,10'],
    ];

    private const DEFAULTS = [
        'riwayat_mutasi_jabatan' => [
            'id_riwayat_mutasi_jabatan' => 'auto_increment',
            'jenis_jabatan_koord'       => '3',
            'jenis_mutasi'              => '1',
            'siasn_flag'                => '0',
            'status'                    => '0',
            'show_notif'                => '0',
            'created_at'                => 'CURRENT_TIMESTAMP',
            'updated_at'                => 'CURRENT_TIMESTAMP on update',
        ],
        'pegawai_plt' => [
            'id_pegawai_plt' => 'auto_increment',
            'status'         => '1',
            'jenis_jabatan'  => '1',
            'created_at'     => 'CURRENT_TIMESTAMP',
            'updated_at'     => 'CURRENT_TIMESTAMP on update',
        ],
        'pegawai_plh' => [
            'id_pegawai_plh' => 'auto_increment',
            'status'         => '1',
            'jenis_jabatan'  => '1',
            'created_at'     => 'CURRENT_TIMESTAMP',
            'updated_at'     => 'CURRENT_TIMESTAMP on update',
        ],
        'riwayat_kp' => [
            'id_riwayat_kp' => 'auto_increment',
            'status'        => '0',
            'show_notif'    => '0',
            'created_at'    => 'CURRENT_TIMESTAMP',
            'updated_at'    => 'CURRENT_TIMESTAMP on update',
        ],
        'riwayat_kgb' => [
            'id_riwayat_kgb' => 'auto_increment',
            'status'         => '0',
            'show_notif'     => '0',
            'created_at'     => 'CURRENT_TIMESTAMP',
            'updated_at'     => 'CURRENT_TIMESTAMP on update',
        ],
        'riwayat_pendidikan' => [
            'id_riwayat_pendidikan' => 'auto_increment',
            'status'                => '0',
            'show_notif'            => '0',
            'created_at'            => 'CURRENT_TIMESTAMP',
            'updated_at'            => 'CURRENT_TIMESTAMP on update',
        ],
        'riwayat_diklat' => [
            'id_riwayat_diklat' => 'auto_increment',
            'siasn_flag'        => '0',
            'status'            => '0',
            'show_notif'        => '0',
            'created_at'        => 'CURRENT_TIMESTAMP',
            'updated_at'        => 'CURRENT_TIMESTAMP on update',
        ],
        'riwayat_seminar' => [
            'id_riwayat_seminar' => 'auto_increment',
            'jenis_seminar'      => '1',
            'siasn_flag'         => '0',
            'status'             => '0',
            'show_notif'         => '0',
            'created_at'         => 'CURRENT_TIMESTAMP',
            'updated_at'         => 'CURRENT_TIMESTAMP on update',
        ],
    ];

    public function testMigrationCreatesNoRows(): void
    {
        foreach (self::TABLES as $table) {
            $this->assertSame(0, $this->db->table($table)->countAllResults(), "{$table} harus kosong setelah migration");
        }
    }

    /**
     * Lapis DB (strictOn=true): status riwayat 0/1/2/3/10 (3 = Diproses, opsi form admin legacy), status Plt/Plh
     * 1/2/10, jenis diklat 1-5, FK nip & master (1452), RESTRICT pegawai yang dirujuk (1451).
     */
    public function testConstraintsAreEnforced(): void
    {
        $this->insertPegawai();

        $kp = [
            'nip'   => self::NIP, 'tmtsk' => '2022-04-01', 'tgl_sk' => '2022-03-15', 'jenis_kp' => 'Reguler', 'gol' => 'III',
            'ruang' => 'c', 'gol_ruang' => 'III/c', 'pangkat' => 'Penata',
        ];
        $this->db->table('riwayat_kp')->insert($kp);
        $this->seeInDatabase('riwayat_kp', ['nip' => self::NIP, 'status' => 0, 'show_notif' => 0, 'approved_by' => null]);
        $this->db->table('riwayat_kp')->insert([...$kp, 'status' => 3]);
        $this->assertDbError(self::ERR_CHECK, fn () => $this->db->table('riwayat_kp')->insert([...$kp, 'status' => 4]));
        $this->assertDbError([1452], fn () => $this->db->table('riwayat_kp')->insert([...$kp, 'nip' => self::NIP_ASING]));
        $this->assertDbError([1452], fn () => $this->db->table('riwayat_kp')->insert([...$kp, 'id_pangkat' => 33]));
        $this->assertDbError([1452], fn () => $this->db->table('riwayat_kgb')->insert(['nip' => self::NIP, 'tmtsk' => '2024-04-01', 'id_gol_pppk' => 1]));

        $this->db->table('riwayat_mutasi_jabatan')->insert(['nip' => self::NIP, 'tmtsk' => '2020-01-01', 'status' => 3]);
        $this->seeInDatabase('riwayat_mutasi_jabatan', [
            'nip' => self::NIP, 'jenis_jabatan' => null, 'jenis_jabatan_koord' => 3, 'jenis_mutasi' => 1, 'siasn_flag' => 0,
        ]);
        $this->assertDbError(self::ERR_CHECK, fn () => $this->db->table('riwayat_mutasi_jabatan')->insert(['nip' => self::NIP, 'tmtsk' => '2021-01-01', 'status' => 5]));

        $diklat = ['nip' => self::NIP, 'jenis_diklat' => 1, 'tgl_sertifikat' => '2023-05-01'];
        $this->db->table('riwayat_diklat')->insert($diklat);
        $this->assertDbError(self::ERR_CHECK, fn () => $this->db->table('riwayat_diklat')->insert([...$diklat, 'jenis_diklat' => 6]));
        $this->assertDbError([1452], fn () => $this->db->table('riwayat_diklat')->insert([...$diklat, 'id_diklat' => 99]));

        $this->db->table('riwayat_seminar')->insert(['nip' => self::NIP, 'tgl_sertifikat' => '2023-06-01', 'status' => 3]);
        $this->seeInDatabase('riwayat_seminar', ['nip' => self::NIP, 'jenis_seminar' => 1]);
        $this->assertDbError([1452], fn () => $this->db->table('riwayat_pendidikan')->insert(['nip' => self::NIP, 'id_jenjang_pendidikan' => 99]));

        $plt = ['nip_plt' => self::NIP, 'tgl_mulai' => '2024-01-01', 'tgl_akhir' => '2024-03-31'];
        $this->db->table('pegawai_plt')->insert($plt);
        $this->seeInDatabase('pegawai_plt', ['nip_plt' => self::NIP, 'status' => 1, 'jenis_jabatan' => 1]);
        $this->assertDbError(self::ERR_CHECK, fn () => $this->db->table('pegawai_plt')->insert([...$plt, 'status' => 0]));
        $this->assertDbError([1452], fn () => $this->db->table('pegawai_plh')->insert([
            'nip_plh' => self::NIP_ASING, 'tgl_mulai' => '2024-01-01', 'tgl_akhir' => '2024-01-31',
        ]));

        // RESTRICT (K1): pegawai yang masih dirujuk riwayat tidak bisa dihapus keras maupun diganti NIP-nya.
        $this->assertDbError([1451], fn () => $this->db->table('pegawai')->where('nip', self::NIP)->delete());
        $this->assertDbError([1451], fn () => $this->db->table('pegawai')->update(['nip' => '198501012010011999'], ['nip' => self::NIP]));
    }

    /**
     * Test rollback/kegagalan melepas migration di tengah jalan; bila assertion gagal sebelum dipasang ulang, tabel
     * penghalang dibuang dan migration dipasang ulang di sini agar regress/migrate test berikutnya konsisten.
     */
    protected function tearDown(): void
    {
        if ($this->adaYangDilepas()) {
            if ($this->skemaTableExists(self::BLOCKER) && array_key_exists('penghalang', $this->skemaColumns(self::BLOCKER))) {
                $this->db->query('DROP TABLE ' . $this->skemaQuoted(self::BLOCKER));
            }

            $this->pasangUlang();
        }

        parent::tearDown();
    }

    public function testSchemaMatchesProposal(): void
    {
        $this->assertSkemaKelompok();
    }

    /**
     * down() semua migration sejak migration pertama kelompok ini (termasuk 131000 FK snapshot → riwayat) menghapus
     * tabel kelompok ini tanpa menyentuh tabel B-01/master; up() membuatnya kembali persis.
     */
    public function testMigrationRollsBackAndUpAgain(): void
    {
        $this->lepasSejak(self::FIRST_VERSION);

        foreach (self::TABLES as $table) {
            $this->assertFalse($this->skemaTableExists($table), "{$table} harus terhapus oleh down()");
        }

        foreach (['pegawai', 'pegawai_kp', 'provinsi', 'pangkat'] as $table) {
            $this->assertTrue($this->skemaTableExists($table), "{$table} (migration sebelumnya) tidak tersentuh");
        }

        $this->pasangUlang();
        $this->assertSkemaKelompok();
    }

    /**
     * Bila salah satu CREATE gagal di tengah up(), tabel yang sempat dibuat PADA RUN ITU di-drop lalu error dilempar
     * ulang; tabel yang sudah ada sebelum run tidak disentuh (simulasi: tabel penghalang self::BLOCKER, error 1050).
     */
    public function testFailedUpDropsOnlyTablesCreatedInThatRun(): void
    {
        $this->lepasSejak(self::BLOCKER_VERSION);
        $this->db->query('CREATE TABLE ' . $this->skemaQuoted(self::BLOCKER) . ' (`penghalang` INT NOT NULL) ENGINE=InnoDB');

        $error = null;

        try {
            $this->skemaMigration(self::BLOCKER_VERSION)->up();
        } catch (Throwable $e) {
            $error = $e;
        }

        $this->assertNotNull($error, 'up() harus gagal karena tabel penghalang sudah ada.');
        $this->assertStringContainsString(self::BLOCKER, $error->getMessage());

        foreach (self::BLOCKER_SIBLINGS as $table) {
            $this->assertFalse($this->skemaTableExists($table), "{$table} tidak boleh tertinggal setelah up() gagal");
        }

        $this->assertSame(['penghalang' => 'int'], $this->skemaColumns(self::BLOCKER), 'tabel yang ada sebelum run tidak di-drop');
        $this->assertTrue($this->skemaTableExists('pegawai'), 'pegawai (migration sebelumnya) tidak tersentuh');

        $this->db->query('DROP TABLE ' . $this->skemaQuoted(self::BLOCKER));
        $this->pasangUlang();
        $this->assertSkemaKelompok();
    }

    private function assertSkemaKelompok(): void
    {
        $this->assertSkema(
            self::TABLES,
            self::COLUMNS,
            self::PRIMARY_KEYS,
            self::INDEXES,
            self::UNIQUES,
            self::FOREIGN_KEYS,
            self::LATER_FOREIGN_KEYS,
            self::CHECKS,
            self::DEFAULTS,
        );
    }

    private function insertPegawai(): void
    {
        $this->db->table('pegawai')->insert(['nip' => self::NIP, 'nama' => 'Ahmad Wijaya', 'tgl_lahir' => '1985-01-01']);
    }
}
