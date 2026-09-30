<?php

declare(strict_types=1);

namespace Tests\Database\Kepegawaian;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\SkemaKepegawaianTestTrait;
use Throwable;

/**
 * DBV-013 — skema B-02 kelompok 3 hasil migration 2026-09-30-130400_CreateRiwayatSkpLkh, 130500_CreateAbsenIjin,
 * 130600_CreateRiwayatHukdisAk harus sama dengan yang diajukan ke DB Validator
 * (backend/docs/db-review/B-01-B-02-pegawai-riwayat-schema.md Bagian 2.3): `bkn_periode_ekinper`/`absen_ijin`/
 * `ignore_konv_ak` [K] persis, `riwayat_lckh` [K-m] + PK, tabel lain [I]; kolom, default, collation, PRIMARY/index,
 * FK (nama [K-erd], RESTRICT/RESTRICT), CHECK, perilaku constraint, down()/up() bersih, dan pembersihan saat up()
 * gagal di tengah. FK ke tabel G-02 (ditahan sampai DBV-008) hanya KEY-nya (self::LATER_FOREIGN_KEYS).
 *
 * @internal
 */
final class RiwayatSkpLkhKonketHukdisAkSchemaTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use SkemaKepegawaianTestTrait;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = null;

    private const NIP = '198501012010011001';

    private const NIP_ASING = '199912312099121001';

    private const ERR_CHECK = [3819, 4025];

    private const FIRST_VERSION = '2026-09-30-130400';

    private const BLOCKER_VERSION = '2026-09-30-130600';

    /**
     * Tabel penghalang (CREATE ke-2 migration 130600) dan tabel lain migration itu yang tidak boleh tertinggal.
     */
    private const BLOCKER = 'riwayat_ak';

    private const BLOCKER_SIBLINGS = ['riwayat_hukdis', 'riwayat_ak_siasn', 'konv_ak', 'ignore_konv_ak'];

    private const TABLES = [
        'bkn_periode_ekinper', 'riwayat_skp', 'riwayat_skp_periodik', 'riwayat_lckh', 'absen_ijin', 'riwayat_hukdis',
        'riwayat_ak', 'riwayat_ak_siasn', 'konv_ak', 'ignore_konv_ak',
    ];

    /**
     * FK G-02 (migration AddFkG02Riwayat, ditahan sampai DBV-008): KEY-nya sudah ada, constraint-nya boleh muncul kelak.
     */
    private const LATER_FOREIGN_KEYS = [
        'fk_riwayat_lckh_ibfk_03', 'fk_riwayat_lckh_ibfk_04', 'fk_id_jabatan_rak_to_jab', 'fk_id_jabatan_rw_ak_siasn_02',
        'fk_id_jabatan_konvak_to_jabatan', 'fk_id_unit_konvak_to_unit', 'fk_id_satker_konvak_to_satker',
    ];

    private const COLUMNS = [
        'bkn_periode_ekinper' => [
            'id'              => 'varchar(150)',
            'nama'            => 'varchar(150)',
            'tahun'           => 'year',
            'bulan'           => 'varchar(5) null',
            'periode_awal'    => 'varchar(10) null',
            'periode_akhir'   => 'varchar(10) null',
            'batas_pengisian' => 'varchar(10) null',
            'jenis_periode'   => 'varchar(150) null',
            'status'          => 'tinyint',
            'created_at'      => 'datetime',
            'updated_at'      => 'datetime null',
        ],
        'riwayat_skp' => [
            'id_riwayat_skp'           => 'int',
            'nip'                      => 'varchar(30)',
            'tahun'                    => 'smallint',
            'tgl_mulai'                => 'date',
            'tgl_akhir'                => 'date',
            'nip_penilai'              => 'varchar(30) null',
            'nama_penilai'             => 'varchar(255) null',
            'jabatan_penilai'          => 'varchar(255) null',
            'nip_atasan_penilai'       => 'varchar(30) null',
            'nama_atasan_penilai'      => 'varchar(255) null',
            'jabatan_atasan_penilai'   => 'varchar(255) null',
            'rating_skp'               => 'tinyint null',
            'rating_perilaku'          => 'tinyint null',
            'nilai_skp'                => 'decimal(6,2) null',
            'nilai_skp_60_persen'      => 'decimal(6,2) null',
            'nilai_perilaku'           => 'decimal(6,2) null',
            'nilai_perilaku_40_persen' => 'decimal(6,2) null',
            'nilai_prestasi_kerja'     => 'decimal(6,2) null',
            'kategori_nilai_prestasi'  => 'varchar(50) null',
            'keterangan'               => 'text null',
            'id_rw_siasn'              => 'varchar(100) null',
            'siasn_flag'               => 'tinyint',
            'siasn_error'              => 'text null',
            'status'                   => 'tinyint',
            'reason_note'              => 'text null',
            'approved_by'              => 'int null',
            'show_notif'               => 'tinyint',
            'notif_date'               => 'datetime null',
            'show_ua_upt'              => 'tinyint null',
            'show_ua_deputi'           => 'tinyint null',
            'show_ua_biro'             => 'tinyint null',
            'created_at'               => 'datetime',
            'updated_at'               => 'datetime null',
            'updated_by'               => 'int null',
        ],
        'riwayat_skp_periodik' => [
            'id_riwayat_skp_periodik' => 'int',
            'id_pns'                  => 'varchar(100) null',
            'periode_id'              => 'varchar(150) null',
            'skp_id'                  => 'varchar(150) null',
            'skp_penilaian_id'        => 'varchar(150) null',
            'jenis'                   => 'varchar(100) null',
            'tahun_skp'               => 'varchar(10) null',
            'nip'                     => 'varchar(30)',
            'nama'                    => 'varchar(255) null',
            'periode_awal_skp'        => 'varchar(30) null',
            'periode_akhir_skp'       => 'varchar(30) null',
            'skp_unor_id'             => 'varchar(150) null',
            'skp_unor'                => 'varchar(255) null',
            'skp_unor_induk'          => 'varchar(255) null',
            'skp_jabatan'             => 'varchar(255) null',
            'skp_jenis_jabatan'       => 'varchar(100) null',
            'is_skp_plt_plh_pjb'      => 'varchar(10) null',
            'hasil_kerja'             => 'varchar(100) null',
            'perilaku_kerja'          => 'varchar(100) null',
            'hasil_akhir'             => 'varchar(100) null',
            'pegawai_atasan_id'       => 'varchar(150) null',
            'pegawai_atasan_nip'      => 'varchar(30) null',
            'pegawai_atasan_nama'     => 'varchar(255) null',
            'pegawai_atasan_unor_id'  => 'varchar(150) null',
            'pegawai_atasan_unor'     => 'varchar(255) null',
            'pegawai_atasan_jabatan'  => 'varchar(255) null',
            'pegawai_atasan_golru'    => 'varchar(20) null',
            'waktu_dinilai'           => 'varchar(30) null',
            'pegawai_penilai_id'      => 'varchar(150) null',
            'golru'                   => 'varchar(20) null',
            'f_arsip_1'               => 'varchar(255) null',
            'keterangan'              => 'text null',
            'status'                  => 'tinyint',
            'created_at'              => 'datetime',
            'updated_at'              => 'datetime null',
            'updated_by'              => 'int null',
        ],
        'riwayat_lckh' => [
            'id_riwayat_lckh'     => 'int',
            'id_unit'             => 'int null',
            'id_satker'           => 'int null',
            'nip'                 => 'varchar(30)',
            'tgl_laporan'         => 'date',
            'nama'                => 'varchar(150)',
            'unit'                => 'varchar(150) null',
            'satker'              => 'varchar(150) null',
            'nip_atasan'          => 'varchar(30) null',
            'nama_atasan'         => 'varchar(150)',
            'kegiatan'            => 'mediumtext',
            'output'              => 'mediumtext',
            'jumlah_diselesaikan' => 'mediumtext null',
            'jam_mulai'           => 'mediumtext null',
            'jam_selesai'         => 'mediumtext null',
            'catatan'             => 'text null',
            'file_lckh'           => 'varchar(250) null',
            'status'              => 'int',
            'auto_approval'       => 'tinyint(1) null',
            'status_regen'        => 'tinyint(1) null',
            'created_at'          => 'datetime',
            'updated_at'          => 'datetime null',
            'updated_by'          => 'int null',
            'approved_by'         => 'int null',
            'show_notif'          => 'int',
            'show_atasan'         => 'int',
            'show_history_atasan' => 'int',
        ],
        'absen_ijin' => [
            'id'             => 'int',
            'nip'            => 'varchar(30)',
            'date_start'     => 'datetime',
            'date_end'       => 'datetime',
            'kategori'       => 'int',
            'jenis_konket'   => 'varchar(255) null',
            'jenis_dinas'    => 'tinyint(1) null',
            'affect_tukin'   => 'tinyint(1)',
            'id_parent'      => 'int null',
            'alasan'         => 'mediumtext',
            'file_bukti'     => 'varchar(255) null',
            'file_bukti_2'   => 'varchar(255) null',
            'file_bukti_3'   => 'varchar(255) null',
            'file_bukti_4'   => 'varchar(255) null',
            'file_bukti_5'   => 'varchar(255) null',
            'date_created'   => 'datetime',
            'last_updated'   => 'datetime null',
            'status'         => 'char(2)',
            'created_at'     => 'datetime null',
            'updated_at'     => 'datetime null',
            'updated_by'     => 'int null',
            'approved_by'    => 'int null',
            'reason_note'    => 'mediumtext null',
            'show_notif'     => 'int null',
            'notif_date'     => 'datetime null',
            'show_ua_upt'    => 'int null',
            'show_ua_deputi' => 'int null',
            'show_ua_biro'   => 'int null',
        ],
        'riwayat_hukdis' => [
            'id_riwayat_hukdis' => 'int',
            'nip'               => 'varchar(30)',
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
            'tmtsk'             => 'date',
            'masa_hukuman'      => 'text null',
            'akhir_hukdis'      => 'date null',
            'aturan_dilanggar'  => 'text null',
            'alasan_hukuman'    => 'text null',
            'keterangan'        => 'text null',
            'status'            => 'tinyint',
            'approved_by'       => 'int null',
            'created_at'        => 'datetime',
            'updated_at'        => 'datetime null',
            'updated_by'        => 'int null',
        ],
        'riwayat_ak' => [
            'id_riwayat_ak'    => 'int',
            'id_jabatan'       => 'int null',
            'id_pangkat'       => 'tinyint null',
            'nip'              => 'varchar(30)',
            'jenis_ak'         => 'tinyint',
            'nama'             => 'varchar(255) null',
            'no_induk_jf'      => 'varchar(50) null',
            'no_hpak'          => 'varchar(100) null',
            'tgl_pak'          => 'date null',
            'periode_awal'     => 'date null',
            'periode_akhir'    => 'date null',
            'ak_kum_kp'        => 'decimal(8,3) null',
            'ak_kum_next_jen'  => 'decimal(8,3) null',
            'ak_terakhir'      => 'decimal(8,3) null',
            'pengajuan_ak'     => 'decimal(8,3) null',
            'nilai_ak'         => 'decimal(8,3) null',
            'flag_instansi_ym' => 'tinyint null',
            'instansi_ym'      => 'varchar(255) null',
            'nama_pym'         => 'varchar(255) null',
            'jab_pym'          => 'varchar(255) null',
            'jabatan'          => 'varchar(255) null',
            'tmt_jab'          => 'date null',
            'mker_th_jab'      => 'tinyint null',
            'mker_bl_jab'      => 'tinyint null',
            'pangkat'          => 'varchar(50) null',
            'gol_ruang'        => 'varchar(10) null',
            'tmt_pang'         => 'date null',
            'mker_th_pang'     => 'tinyint null',
            'mker_bl_pang'     => 'tinyint null',
            'tgl_ak_terakhir'  => 'date null',
            'tgl_pengajuan_ak' => 'date null',
            'keterangan'       => 'text null',
            'file_pak'         => 'varchar(255) null',
            'file_dupak'       => 'varchar(255) null',
            'file_pengantar'   => 'varchar(255) null',
            'status'           => 'tinyint',
            'reason_note'      => 'text null',
            'appr_at'          => 'datetime null',
            'appr_by'          => 'int null',
            'show_notif'       => 'tinyint',
            'notif_date'       => 'datetime null',
            'show_ua_upt'      => 'tinyint null',
            'show_ua_deputi'   => 'tinyint null',
            'show_ua_biro'     => 'tinyint null',
            'created_by'       => 'int null',
            'created_at'       => 'datetime',
            'updated_at'       => 'datetime null',
            'updated_by'       => 'int null',
        ],
        'riwayat_ak_siasn' => [
            'id_riwayat_ak_siasn'     => 'int',
            'id_rw_siasn'             => 'varchar(100) null',
            'id_pns'                  => 'varchar(100) null',
            'id_jabatan'              => 'int null',
            'id_pangkat'              => 'tinyint null',
            'nip'                     => 'varchar(30)',
            'no_sk'                   => 'varchar(100) null',
            'tgl_sk'                  => 'date null',
            'bulan_mulai_penilaian'   => 'tinyint null',
            'tahun_mulai_penilaian'   => 'smallint null',
            'bulan_selesai_penilaian' => 'tinyint null',
            'tahun_selesai_penilaian' => 'smallint null',
            'kredit_utama_baru'       => 'decimal(8,3) null',
            'kredit_penunjang_baru'   => 'decimal(8,3) null',
            'kredit_baru_total'       => 'decimal(8,3) null',
            'id_rw_jabatan_siasn'     => 'varchar(100) null',
            'nama_jabatan'            => 'varchar(255) null',
            'is_angka_kredit_pertama' => 'tinyint(1) null',
            'is_integrasi'            => 'tinyint(1) null',
            'is_konversi'             => 'tinyint(1) null',
            'f_pak_siasn'             => 'varchar(255) null',
            'sumber'                  => 'varchar(100) null',
            'is_pemenuhan_kp'         => 'tinyint(1) null',
            'keterangan'              => 'text null',
            'status'                  => 'tinyint',
            'created_at'              => 'datetime',
            'updated_at'              => 'datetime null',
            'updated_by'              => 'int null',
        ],
        'konv_ak' => [
            'id'          => 'int',
            'nip'         => 'varchar(30)',
            'id_pangkat'  => 'tinyint null',
            'id_jabatan'  => 'int null',
            'id_unit'     => 'int null',
            'id_satker'   => 'int null',
            'pangkat'     => 'varchar(50) null',
            'gol_ruang'   => 'varchar(10) null',
            'jabatan'     => 'varchar(255) null',
            'unit'        => 'varchar(255) null',
            'satker'      => 'varchar(255) null',
            'no_pak'      => 'varchar(100) null',
            'tgl_pak'     => 'date null',
            'ak_terakhir' => 'decimal(8,3) null',
            'file_dupak'  => 'varchar(255) null',
            'status'      => 'tinyint',
            'reason_note' => 'text null',
            'created_by'  => 'int null',
            'created_at'  => 'datetime',
            'updated_at'  => 'datetime null',
            'updated_by'  => 'int null',
        ],
        'ignore_konv_ak' => [
            'nip'         => 'varchar(30)',
            'ignore_date' => 'date',
        ],
    ];

    private const PRIMARY_KEYS = [
        'bkn_periode_ekinper'  => ['id'],
        'riwayat_skp'          => ['id_riwayat_skp'],
        'riwayat_skp_periodik' => ['id_riwayat_skp_periodik'],
        'riwayat_lckh'         => ['id_riwayat_lckh'],
        'absen_ijin'           => ['id'],
        'riwayat_hukdis'       => ['id_riwayat_hukdis'],
        'riwayat_ak'           => ['id_riwayat_ak'],
        'riwayat_ak_siasn'     => ['id_riwayat_ak_siasn'],
        'konv_ak'              => ['id'],
        'ignore_konv_ak'       => ['nip', 'ignore_date'],
    ];

    private const INDEXES = [
        'bkn_periode_ekinper' => [
            'tahun_bulan_jenis_periode_status' => ['tahun', 'bulan', 'jenis_periode', 'status'],
        ],
        'riwayat_skp' => [
            'fk_nip_rwyskp_to_pegawai' => ['nip'],
        ],
        'riwayat_skp_periodik' => [
            'idx_riwayat_skp_periodik_nip'         => ['nip'],
            'idx_riwayat_skp_periodik_periode_nip' => ['periode_id', 'nip'],
        ],
        'riwayat_lckh' => [
            'fk_riwayat_lckh_ibfk_01'        => ['nip'],
            'fk_riwayat_lckh_ibfk_02'        => ['nip_atasan'],
            'fk_riwayat_lckh_ibfk_03'        => ['id_unit'],
            'fk_riwayat_lckh_ibfk_04'        => ['id_satker'],
            'idx_riwayat_lckh_atasan_status' => ['nip_atasan', 'status'],
            'idx_riwayat_lckh_nip_tgl'       => ['nip', 'tgl_laporan'],
        ],
        'absen_ijin' => [
            'affect_tukin' => ['affect_tukin'],
            'date_start'   => ['date_start', 'date_end'],
            'nip'          => ['nip'],
            'show_notif'   => ['show_notif', 'notif_date'],
            'status'       => ['status'],
        ],
        'riwayat_hukdis' => [
            'fk_id_jenis_hukdis_rwyhukdis_to_jenhukdis'  => ['id_jenis_hukdis'],
            'fk_id_pangkat_rwyhukdis_to_pangkat'         => ['id_pangkat'],
            'fk_id_tingkat_hukdis_rwyhukdis_to_tkhukdis' => ['id_tingkat_hukdis'],
            'fk_nip_rwyhukdis_to_pegawai'                => ['nip'],
        ],
        'riwayat_ak' => [
            'fk_id_jabatan_rak_to_jab'     => ['id_jabatan'],
            'fk_id_pangkat_rak_to_pangkat' => ['id_pangkat'],
            'fk_nip_rak_to_peg'            => ['nip'],
        ],
        'riwayat_ak_siasn' => [
            'fk_id_jabatan_rw_ak_siasn_02' => ['id_jabatan'],
            'fk_id_pangkat_rw_ak_siasn_03' => ['id_pangkat'],
            'fk_nip_rw_ak_siasn_01'        => ['nip'],
        ],
        'konv_ak' => [
            'fk_id_jabatan_konvak_to_jabatan' => ['id_jabatan'],
            'fk_id_pangkat_konvak_to_pangkat' => ['id_pangkat'],
            'fk_id_satker_konvak_to_satker'   => ['id_satker'],
            'fk_id_unit_konvak_to_unit'       => ['id_unit'],
            'fk_nip_konvak_to_pegawai'        => ['nip'],
        ],
        'ignore_konv_ak' => [],
    ];

    private const UNIQUES = [];

    private const FOREIGN_KEYS = [
        'fk_id_jenis_hukdis_rwyhukdis_to_jenhukdis'  => ['riwayat_hukdis', 'id_jenis_hukdis', 'jenis_hukdis', 'id_jenis_hukdis'],
        'fk_id_pangkat_konvak_to_pangkat'            => ['konv_ak', 'id_pangkat', 'pangkat', 'id_pangkat'],
        'fk_id_pangkat_rak_to_pangkat'               => ['riwayat_ak', 'id_pangkat', 'pangkat', 'id_pangkat'],
        'fk_id_pangkat_rw_ak_siasn_03'               => ['riwayat_ak_siasn', 'id_pangkat', 'pangkat', 'id_pangkat'],
        'fk_id_pangkat_rwyhukdis_to_pangkat'         => ['riwayat_hukdis', 'id_pangkat', 'pangkat', 'id_pangkat'],
        'fk_id_tingkat_hukdis_rwyhukdis_to_tkhukdis' => ['riwayat_hukdis', 'id_tingkat_hukdis', 'tingkat_hukdis', 'id_tingkat_hukdis'],
        'fk_nip_abijin_to_pegawai'                   => ['absen_ijin', 'nip', 'pegawai', 'nip'],
        'fk_nip_ignorekonvak_to_pegawai'             => ['ignore_konv_ak', 'nip', 'pegawai', 'nip'],
        'fk_nip_konvak_to_pegawai'                   => ['konv_ak', 'nip', 'pegawai', 'nip'],
        'fk_nip_rak_to_peg'                          => ['riwayat_ak', 'nip', 'pegawai', 'nip'],
        'fk_nip_rw_ak_siasn_01'                      => ['riwayat_ak_siasn', 'nip', 'pegawai', 'nip'],
        'fk_nip_rwyhukdis_to_pegawai'                => ['riwayat_hukdis', 'nip', 'pegawai', 'nip'],
        'fk_nip_rwyskp_to_pegawai'                   => ['riwayat_skp', 'nip', 'pegawai', 'nip'],
        'fk_riwayat_lckh_ibfk_01'                    => ['riwayat_lckh', 'nip', 'pegawai', 'nip'],
        'fk_riwayat_lckh_ibfk_02'                    => ['riwayat_lckh', 'nip_atasan', 'pegawai', 'nip'],
    ];

    private const CHECKS = [
        'chk_absen_ijin_affect_tukin'     => ['absen_ijin', 'affect_tukinin1,2'],
        'chk_absen_ijin_jenis_dinas'      => ['absen_ijin', 'jenis_dinasisnullorjenis_dinasin0,1'],
        'chk_absen_ijin_status'           => ['absen_ijin', 'statusin\'w\',\'v\',\'x\',\'10\''],
        'chk_konv_ak_status'              => ['konv_ak', 'statusin0,1,2,10'],
        'chk_riwayat_ak_siasn_status'     => ['riwayat_ak_siasn', 'statusin0,1,2,10'],
        'chk_riwayat_ak_status'           => ['riwayat_ak', 'statusin0,1,2,10'],
        'chk_riwayat_hukdis_status'       => ['riwayat_hukdis', 'statusin0,1,2,3,10'],
        'chk_riwayat_lckh_status'         => ['riwayat_lckh', 'statusin0,1,2,3,10'],
        'chk_riwayat_skp_periodik_status' => ['riwayat_skp_periodik', 'statusin0,1,2,10'],
        'chk_riwayat_skp_status'          => ['riwayat_skp', 'statusin0,1,2,3,10'],
    ];

    private const DEFAULTS = [
        'bkn_periode_ekinper' => [
            'status'     => '1',
            'created_at' => 'CURRENT_TIMESTAMP',
            'updated_at' => 'NULL on update',
        ],
        'riwayat_skp' => [
            'id_riwayat_skp' => 'auto_increment',
            'siasn_flag'     => '0',
            'status'         => '0',
            'show_notif'     => '0',
            'created_at'     => 'CURRENT_TIMESTAMP',
            'updated_at'     => 'CURRENT_TIMESTAMP on update',
        ],
        'riwayat_skp_periodik' => [
            'id_riwayat_skp_periodik' => 'auto_increment',
            'status'                  => '1',
            'created_at'              => 'CURRENT_TIMESTAMP',
            'updated_at'              => 'CURRENT_TIMESTAMP on update',
        ],
        'riwayat_lckh' => [
            'id_riwayat_lckh'     => 'auto_increment',
            'status'              => '0',
            'auto_approval'       => '2',
            'status_regen'        => '0',
            'created_at'          => 'CURRENT_TIMESTAMP',
            'updated_at'          => 'CURRENT_TIMESTAMP on update',
            'show_notif'          => '0',
            'show_atasan'         => '0',
            'show_history_atasan' => '1',
        ],
        'absen_ijin' => [
            'id'             => 'auto_increment',
            'affect_tukin'   => '1',
            'date_created'   => 'CURRENT_TIMESTAMP',
            'last_updated'   => 'NULL on update',
            'status'         => 'W',
            'created_at'     => 'CURRENT_TIMESTAMP',
            'updated_at'     => 'CURRENT_TIMESTAMP on update',
            'show_notif'     => '0',
            'show_ua_upt'    => '0',
            'show_ua_deputi' => '0',
            'show_ua_biro'   => '0',
        ],
        'riwayat_hukdis' => [
            'id_riwayat_hukdis' => 'auto_increment',
            'status'            => '0',
            'created_at'        => 'CURRENT_TIMESTAMP',
            'updated_at'        => 'CURRENT_TIMESTAMP on update',
        ],
        'riwayat_ak' => [
            'id_riwayat_ak' => 'auto_increment',
            'jenis_ak'      => '2',
            'status'        => '0',
            'show_notif'    => '0',
            'created_at'    => 'CURRENT_TIMESTAMP',
            'updated_at'    => 'CURRENT_TIMESTAMP on update',
        ],
        'riwayat_ak_siasn' => [
            'id_riwayat_ak_siasn' => 'auto_increment',
            'status'              => '1',
            'created_at'          => 'CURRENT_TIMESTAMP',
            'updated_at'          => 'CURRENT_TIMESTAMP on update',
        ],
        'konv_ak' => [
            'id'         => 'auto_increment',
            'status'     => '0',
            'created_at' => 'CURRENT_TIMESTAMP',
            'updated_at' => 'CURRENT_TIMESTAMP on update',
        ],
    ];

    public function testMigrationCreatesNoRows(): void
    {
        foreach (self::TABLES as $table) {
            $this->assertSame(0, $this->db->table($table)->countAllResults(), "{$table} harus kosong setelah migration");
        }
    }

    /**
     * Lapis DB (strictOn=true): status per tabel (SKP/hukdis 0/1/2/3/10, LKH 0/1/2/3/10, AK 0/1/2/10, konket W/V/X/10),
     * CHECK konket `affect_tukin`/`jenis_dinas`, FK nip (1452) kecuali kolom NIP non-FK, RESTRICT (1451), PK ganda
     * `ignore_konv_ak` (1062).
     */
    public function testConstraintsAreEnforced(): void
    {
        $this->insertPegawai();

        // absen_ijin [K]: status default 'W', domain W/V/X/10; affect_tukin 1/2; jenis_dinas NULL/0/1.
        $ijin = [
            'nip'    => self::NIP, 'date_start' => '2024-05-02 07:30:00', 'date_end' => '2024-05-02 16:00:00', 'kategori' => 1,
            'alasan' => 'Keperluan keluarga',
        ];
        $this->db->table('absen_ijin')->insert($ijin);
        $this->seeInDatabase('absen_ijin', ['nip' => self::NIP, 'status' => 'W', 'affect_tukin' => 1, 'show_notif' => 0]);
        $this->db->table('absen_ijin')->insert([...$ijin, 'status' => '10', 'jenis_dinas' => 1]);
        $this->assertDbError(self::ERR_CHECK, fn () => $this->db->table('absen_ijin')->insert([...$ijin, 'status' => 'Z']));
        $this->assertDbError(self::ERR_CHECK, fn () => $this->db->table('absen_ijin')->insert([...$ijin, 'affect_tukin' => 3]));
        $this->assertDbError(self::ERR_CHECK, fn () => $this->db->table('absen_ijin')->insert([...$ijin, 'jenis_dinas' => 2]));
        $this->assertDbError([1452], fn () => $this->db->table('absen_ijin')->insert([...$ijin, 'nip' => self::NIP_ASING]));

        // riwayat_lckh [K-m]: status 0/1/2/3/10 (3 = Revisi), nip_atasan boleh NULL tetapi bila diisi wajib ada.
        $lkh = [
            'nip'      => self::NIP, 'tgl_laporan' => '2024-05-02', 'nama' => 'Ahmad Wijaya', 'nama_atasan' => 'Budi Santoso',
            'kegiatan' => '["Rapat koordinasi"]', 'output' => '["Notulen"]',
        ];
        $this->db->table('riwayat_lckh')->insert([...$lkh, 'status' => 3]);
        $this->seeInDatabase('riwayat_lckh', ['nip' => self::NIP, 'nip_atasan' => null, 'auto_approval' => 2, 'show_history_atasan' => 1]);
        $this->assertDbError(self::ERR_CHECK, fn () => $this->db->table('riwayat_lckh')->insert([...$lkh, 'status' => 4]));
        $this->assertDbError([1452], fn () => $this->db->table('riwayat_lckh')->insert([...$lkh, 'nip_atasan' => self::NIP_ASING]));

        // riwayat_skp: status 0/1/2/3/10; nip penilai tanpa FK. riwayat_skp_periodik: nip dari API BKN tanpa FK.
        $skp = ['nip' => self::NIP, 'tahun' => 2023, 'tgl_mulai' => '2023-01-01', 'tgl_akhir' => '2023-12-31'];
        $this->db->table('riwayat_skp')->insert([...$skp, 'status' => 3, 'nip_penilai' => self::NIP_ASING]);
        $this->assertDbError(self::ERR_CHECK, fn () => $this->db->table('riwayat_skp')->insert([...$skp, 'status' => 4]));
        $this->db->table('riwayat_skp_periodik')->insert(['nip' => self::NIP_ASING, 'pegawai_atasan_nip' => self::NIP_ASING]);
        $this->seeInDatabase('riwayat_skp_periodik', ['nip' => self::NIP_ASING, 'status' => 1]);
        $this->db->table('bkn_periode_ekinper')->insert(['id' => 'periode-uji', 'nama' => 'Periode Uji', 'tahun' => 2024]);
        $this->seeInDatabase('bkn_periode_ekinper', ['id' => 'periode-uji', 'status' => 1]);

        // riwayat_hukdis: status 0/1/2/3/10; master tingkat/jenis hukdis (target kosong) ditolak.
        $hukdis = ['nip' => self::NIP, 'tmtsk' => '2022-01-01'];
        $this->db->table('riwayat_hukdis')->insert([...$hukdis, 'status' => 3]);
        $this->assertDbError(self::ERR_CHECK, fn () => $this->db->table('riwayat_hukdis')->insert([...$hukdis, 'status' => 4]));
        $this->assertDbError([1452], fn () => $this->db->table('riwayat_hukdis')->insert([...$hukdis, 'id_jenis_hukdis' => 99]));

        // riwayat_ak: jenis_ak default 2, status 0/1/2/10 (tanpa 3); riwayat_ak_siasn default status 1; konv_ak.
        $this->db->table('riwayat_ak')->insert(['nip' => self::NIP]);
        $this->seeInDatabase('riwayat_ak', ['nip' => self::NIP, 'jenis_ak' => 2, 'status' => 0]);
        $this->assertDbError(self::ERR_CHECK, fn () => $this->db->table('riwayat_ak')->insert(['nip' => self::NIP, 'status' => 3]));
        $this->db->table('riwayat_ak_siasn')->insert(['nip' => self::NIP]);
        $this->seeInDatabase('riwayat_ak_siasn', ['nip' => self::NIP, 'status' => 1]);
        $this->db->table('konv_ak')->insert(['nip' => self::NIP]);
        $this->assertDbError([1452], fn () => $this->db->table('konv_ak')->insert(['nip' => self::NIP, 'id_pangkat' => 33]));

        // ignore_konv_ak [K]: PK (nip, ignore_date).
        $this->db->table('ignore_konv_ak')->insert(['nip' => self::NIP, 'ignore_date' => '2024-05-02']);
        $this->assertDbError([1062], fn () => $this->db->table('ignore_konv_ak')->insert(['nip' => self::NIP, 'ignore_date' => '2024-05-02']));
        $this->assertDbError([1452], fn () => $this->db->table('ignore_konv_ak')->insert(['nip' => self::NIP_ASING, 'ignore_date' => '2024-05-02']));

        // RESTRICT (K1).
        $this->assertDbError([1451], fn () => $this->db->table('pegawai')->where('nip', self::NIP)->delete());
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
