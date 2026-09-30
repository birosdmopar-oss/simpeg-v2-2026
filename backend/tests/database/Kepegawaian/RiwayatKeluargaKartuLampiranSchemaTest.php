<?php

declare(strict_types=1);

namespace Tests\Database\Kepegawaian;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\SkemaKepegawaianTestTrait;
use Throwable;

/**
 * DBV-013 — skema B-02 kelompok 4 hasil migration 2026-09-30-130000_CreateJenisRwy, 130700_CreateRiwayatKeluargaAlamat,
 * 130800_CreateRiwayatKartuTjOrganisasi, 130900_CreateDocumentAttachment harus sama dengan yang diajukan ke DB Validator
 * (backend/docs/db-review/B-01-B-02-pegawai-riwayat-schema.md Bagian 2.4): `detail_anak`/`document_attachment` [K]
 * persis (FK CASCADE → RESTRICT), lookup `jenis_rwy` [V2] + seed 21 baris = konstanta legacy, tabel lain [I]; kolom,
 * default, collation, PRIMARY/UNIQUE/index, FK, CHECK, perilaku constraint, down()/up() bersih, dan pembersihan saat
 * up() gagal di tengah.
 *
 * @internal
 */
final class RiwayatKeluargaKartuLampiranSchemaTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use SkemaKepegawaianTestTrait;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = null;

    private const NIP = '198501012010011001';

    private const NIP_ASING = '199912312099121001';

    private const ERR_CHECK = [3819, 4025];

    private const FIRST_VERSION = '2026-09-30-130000';

    private const BLOCKER_VERSION = '2026-09-30-130700';

    /**
     * Tabel penghalang (CREATE ke-2 migration 130700) dan tabel lain migration itu yang tidak boleh tertinggal.
     */
    private const BLOCKER = 'detail_anak';

    private const BLOCKER_SIBLINGS = ['riwayat_keluarga', 'riwayat_alamat'];

    private const TABLES = [
        'jenis_rwy', 'riwayat_keluarga', 'detail_anak', 'riwayat_alamat', 'riwayat_karpeg', 'riwayat_kariskarsu',
        'riwayat_tanda_jasa', 'riwayat_organisasi', 'document_attachment',
    ];

    private const LATER_FOREIGN_KEYS = [];

    /**
     * Konstanta legacy `config/constants.php:193-238`, ditulis literal: ARSIP_RWY (kunci => kode) dan JENIS_RWY_ARSIP
     * (kode => label, urutan elemen = urutan tampil). Baris `33 => 'Data Umum'` dan `38 => 'Arsip Data Umum'` di legacy
     * dikomentari.
     */
    private const LEGACY_ARSIP_RWY = [
        'alamat'           => 1, 'anak' => 3, 'diklat' => 5, 'hukdis' => 7, 'jabatan' => 9, 'kgb' => 10, 'kp' => 11,
        'organisasi'       => 13, 'pendidikan' => 14, 'keluarga' => 20, 'seminar' => 22, 'tj' => 23, 'skp' => 32,
        'jabatan_pjft'     => 36, 'status_du' => 37, 'arsip_du' => 38, 'pencantuman_gelar' => 39, 'transkrip_nilai' => 40,
        'perjanjian_kerja' => 41, 'pmk' => 42,
    ];

    private const LEGACY_JENIS_RWY_ARSIP = [
        1  => 'Alamat', 3 => 'Anak', 38 => 'Data Umum', 5 => 'Pelatihan', 7 => 'Hukuman Disiplin', 9 => 'Jabatan',
        20 => 'Keluarga', 11 => 'Kenaikan Pangkat', 10 => 'KGB', 22 => 'Kursus / Seminar', 13 => 'Organisasi',
        36 => 'Pemberhentian Sementara JFT', 14 => 'Pendidikan', 32 => 'Sasaran Kerja', 37 => 'Status Data Umum',
        23 => 'Tanda Jasa', 39 => 'Arsip Pencantuman Gelar', 40 => 'Transkrip Nilai', 41 => 'Perjanjian Kerja',
        42 => 'Penyesuaian Masa Kerja',
    ];

    /**
     * Tabel tujuan `document_attachment.id_entri` per kode [I previewRwy()/titik tulis lampiran]; NULL = tanpa entri.
     */
    private const TABEL_ENTRI = [
        0  => null, 1 => 'riwayat_alamat', 3 => 'detail_anak', 5 => 'riwayat_diklat', 7 => 'riwayat_hukdis',
        9  => 'riwayat_mutasi_jabatan', 10 => 'riwayat_kgb', 11 => 'riwayat_kp', 13 => 'riwayat_organisasi',
        14 => 'riwayat_pendidikan', 20 => 'riwayat_keluarga', 22 => 'riwayat_seminar', 23 => 'riwayat_tanda_jasa',
        32 => 'riwayat_skp', 36 => 'riwayat_mutasi_jabatan', 37 => null, 38 => null, 39 => 'riwayat_pendidikan',
        40 => 'riwayat_pendidikan', 41 => 'riwayat_mutasi_jabatan', 42 => 'riwayat_pmk',
    ];

    private const COLUMNS = [
        'jenis_rwy' => [
            'id_jenis_rwy' => 'int',
            'kode'         => 'varchar(30)',
            'jenis_rwy'    => 'varchar(100)',
            'tabel_entri'  => 'varchar(64) null',
            'order'        => 'tinyint',
            'status'       => 'tinyint',
            'updated_at'   => 'datetime',
            'updated_by'   => 'int null',
        ],
        'riwayat_keluarga' => [
            'id_riwayat_keluarga' => 'int',
            'nip'                 => 'varchar(30)',
            'urutan_perkawinan'   => 'tinyint null',
            'tgl_perkawinan'      => 'date null',
            'kota_perkawinan'     => 'varchar(255) null',
            'nama_pasangan'       => 'varchar(255) null',
            'jumlah_anak'         => 'tinyint',
            'keterangan'          => 'tinytext null',
            'status'              => 'tinyint',
            'reason_note'         => 'text null',
            'approved_by'         => 'int null',
            'show_notif'          => 'tinyint',
            'notif_date'          => 'datetime null',
            'show_ua_upt'         => 'tinyint null',
            'show_ua_deputi'      => 'tinyint null',
            'show_ua_biro'        => 'tinyint null',
            'created_at'          => 'datetime',
            'updated_at'          => 'datetime null',
            'updated_by'          => 'int null',
        ],
        'detail_anak' => [
            'id_detail_anak'      => 'int',
            'id_riwayat_keluarga' => 'int',
            'urutan'              => 'tinyint',
            'tgl_lahir'           => 'date',
            'nama'                => 'varchar(255)',
            'tempat_lahir'        => 'varchar(255) null',
            'keterangan'          => 'tinytext null',
            'created_at'          => 'datetime',
            'updated_at'          => 'datetime null',
        ],
        'riwayat_alamat' => [
            'id_riwayat_alamat'   => 'int',
            'nip'                 => 'varchar(30)',
            'jenis_alamat'        => 'tinyint(1)',
            'alamat_utama'        => 'tinyint(1) null',
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
            'KdPos'               => 'varchar(10) null',
            'alamat'              => 'text null',
            'nama_kantor'         => 'varchar(255) null',
            'lantai'              => 'varchar(50) null',
            'telp'                => 'varchar(50) null',
            'faks'                => 'varchar(50) null',
            'keterangan'          => 'tinytext null',
            'status'              => 'tinyint',
            'reason_note'         => 'text null',
            'approved_by'         => 'int null',
            'show_notif'          => 'tinyint',
            'notif_date'          => 'datetime null',
            'show_ua_upt'         => 'tinyint null',
            'show_ua_deputi'      => 'tinyint null',
            'show_ua_biro'        => 'tinyint null',
            'created_at'          => 'datetime',
            'updated_at'          => 'datetime null',
            'updated_by'          => 'int null',
        ],
        'riwayat_karpeg' => [
            'id_riwayat_karpeg' => 'int',
            'nip'               => 'varchar(30)',
            'jenis_permohonan'  => 'varchar(100) null',
            'file_1'            => 'varchar(255) null',
            'file_2'            => 'varchar(255) null',
            'file_3'            => 'varchar(255) null',
            'file_4'            => 'varchar(255) null',
            'file_5'            => 'varchar(255) null',
            'file_surat_hilang' => 'varchar(255) null',
            'file_tanda_terima' => 'varchar(255) null',
            'status'            => 'tinyint',
            'reason_note'       => 'text null',
            'approved_by'       => 'int null',
            'show_ua_biro'      => 'tinyint null',
            'show_notif'        => 'tinyint',
            'notif_date'        => 'datetime null',
            'rated'             => 'tinyint',
            'created_at'        => 'datetime',
            'updated_at'        => 'datetime null',
            'updated_by'        => 'int null',
        ],
        'riwayat_kariskarsu' => [
            'id_riwayat_kariskarsu' => 'int',
            'nip'                   => 'varchar(30)',
            'file_1'                => 'varchar(255) null',
            'file_2'                => 'varchar(255) null',
            'file_3'                => 'varchar(255) null',
            'file_4'                => 'varchar(255) null',
            'file_5'                => 'varchar(255) null',
            'file_surat_hilang'     => 'varchar(255) null',
            'file_alasan'           => 'varchar(255) null',
            'file_tanda_terima'     => 'varchar(255) null',
            'status'                => 'tinyint',
            'reason_note'           => 'text null',
            'approved_by'           => 'int null',
            'show_ua_biro'          => 'tinyint null',
            'show_notif'            => 'tinyint',
            'notif_date'            => 'datetime null',
            'rated'                 => 'tinyint',
            'created_at'            => 'datetime',
            'updated_at'            => 'datetime null',
            'updated_by'            => 'int null',
        ],
        'riwayat_tanda_jasa' => [
            'id_riwayat_tanda_jasa' => 'int',
            'nip'                   => 'varchar(30)',
            'id_tanda_jasa'         => 'int null',
            'tanda_jasa'            => 'varchar(255) null',
            'tanda_jasa_lain'       => 'varchar(255) null',
            'tgl_sertifikat'        => 'date',
            'no_sertifikat'         => 'varchar(100) null',
            'negara'                => 'varchar(100) null',
            'keterangan'            => 'text null',
            'id_rw_siasn'           => 'varchar(100) null',
            'siasn_flag'            => 'tinyint',
            'siasn_error'           => 'text null',
            'status'                => 'tinyint',
            'reason_note'           => 'text null',
            'approved_by'           => 'int null',
            'show_notif'            => 'tinyint',
            'notif_date'            => 'datetime null',
            'show_ua_upt'           => 'tinyint null',
            'show_ua_deputi'        => 'tinyint null',
            'show_ua_biro'          => 'tinyint null',
            'created_at'            => 'datetime',
            'updated_at'            => 'datetime null',
            'updated_by'            => 'int null',
        ],
        'riwayat_organisasi' => [
            'id_riwayat_organisasi' => 'int',
            'nip'                   => 'varchar(30)',
            'nama_organisasi'       => 'varchar(255) null',
            'kedudukan'             => 'varchar(255) null',
            'tgl_mulai'             => 'date',
            'tgl_akhir'             => 'date null',
            'keterangan'            => 'text null',
            'status'                => 'tinyint',
            'reason_note'           => 'text null',
            'approved_by'           => 'int null',
            'show_notif'            => 'tinyint',
            'notif_date'            => 'datetime null',
            'show_ua_upt'           => 'tinyint null',
            'show_ua_deputi'        => 'tinyint null',
            'show_ua_biro'          => 'tinyint null',
            'created_at'            => 'datetime',
            'updated_at'            => 'datetime null',
            'updated_by'            => 'int null',
        ],
        'document_attachment' => [
            'id_attachment' => 'int',
            'NIP'           => 'varchar(30)',
            'document_id'   => 'int null',
            'filename'      => 'varchar(200) null',
            'id_riwayat'    => 'int null',
            'nama_riwayat'  => 'varchar(255) null',
            'id_entri'      => 'varchar(100) null',
            'tag'           => 'varchar(255) null',
            'path'          => 'varchar(200) null',
            'url'           => 'varchar(200) null',
            'basename'      => 'varchar(200) null',
            'display_name'  => 'varchar(200) null',
            'file_size'     => 'int null',
            'file_ext'      => 'varchar(100) null',
            'file_type'     => 'varchar(100) null',
            'created_at'    => 'datetime null',
            'updated_at'    => 'datetime null',
        ],
    ];

    private const PRIMARY_KEYS = [
        'jenis_rwy'           => ['id_jenis_rwy'],
        'riwayat_keluarga'    => ['id_riwayat_keluarga'],
        'detail_anak'         => ['id_detail_anak'],
        'riwayat_alamat'      => ['id_riwayat_alamat'],
        'riwayat_karpeg'      => ['id_riwayat_karpeg'],
        'riwayat_kariskarsu'  => ['id_riwayat_kariskarsu'],
        'riwayat_tanda_jasa'  => ['id_riwayat_tanda_jasa'],
        'riwayat_organisasi'  => ['id_riwayat_organisasi'],
        'document_attachment' => ['id_attachment'],
    ];

    private const INDEXES = [
        'jenis_rwy' => [
            'uq_jenis_rwy_kode' => ['kode'],
            'uq_jenis_rwy_nama' => ['jenis_rwy'],
        ],
        'riwayat_keluarga' => [
            'fk_nip_rwykeluarga_to_pegawai' => ['nip'],
        ],
        'detail_anak' => [
            'fk_id_riwayat_keluarga_detanak_to_rwykeluarga' => ['id_riwayat_keluarga'],
        ],
        'riwayat_alamat' => [
            'fk_id_kabupaten_kota_riwalamat_kabkota' => ['id_kabupaten_kota'],
            'fk_id_kecamatan_riwalamat_kecamatan'    => ['id_kecamatan'],
            'fk_id_kelurahan_riwalamat_kelurahan'    => ['id_kelurahan'],
            'fk_id_provinsi_riwalamat_provinsi'      => ['id_provinsi'],
            'fk_nip_rwyalamat_to_pegawai'            => ['nip'],
        ],
        'riwayat_karpeg' => [
            'fk_nip_rkarpeg_to_pegawai' => ['nip'],
        ],
        'riwayat_kariskarsu' => [
            'fk_nip_rkariskarsu_to_pegawai' => ['nip'],
        ],
        'riwayat_tanda_jasa' => [
            'fk_id_tanda_jasa_rtj_to_tj' => ['id_tanda_jasa'],
            'fk_nip_rwytj_to_pegawai'    => ['nip'],
        ],
        'riwayat_organisasi' => [
            'fk_nip_rwyorganisasi_to_pegawai' => ['nip'],
        ],
        'document_attachment' => [
            'fk_NIP_da_to_pegawai' => ['NIP'],
            'id_riwayat_id_entri'  => ['id_riwayat', 'id_entri'],
        ],
    ];

    private const UNIQUES = [
        'jenis_rwy' => ['uq_jenis_rwy_kode', 'uq_jenis_rwy_nama'],
    ];

    private const FOREIGN_KEYS = [
        'fk_id_kabupaten_kota_riwalamat_kabkota'        => ['riwayat_alamat', 'id_kabupaten_kota', 'kabupaten_kota', 'id_kabupaten_kota'],
        'fk_id_kecamatan_riwalamat_kecamatan'           => ['riwayat_alamat', 'id_kecamatan', 'kecamatan', 'id_kecamatan'],
        'fk_id_kelurahan_riwalamat_kelurahan'           => ['riwayat_alamat', 'id_kelurahan', 'kelurahan', 'id_kelurahan'],
        'fk_id_provinsi_riwalamat_provinsi'             => ['riwayat_alamat', 'id_provinsi', 'provinsi', 'id_provinsi'],
        'fk_id_riwayat_da_to_jenis_rwy'                 => ['document_attachment', 'id_riwayat', 'jenis_rwy', 'id_jenis_rwy'],
        'fk_id_riwayat_keluarga_detanak_to_rwykeluarga' => ['detail_anak', 'id_riwayat_keluarga', 'riwayat_keluarga', 'id_riwayat_keluarga'],
        'fk_id_tanda_jasa_rtj_to_tj'                    => ['riwayat_tanda_jasa', 'id_tanda_jasa', 'tanda_jasa', 'id_tanda_jasa'],
        'fk_NIP_da_to_pegawai'                          => ['document_attachment', 'NIP', 'pegawai', 'nip'],
        'fk_nip_rkariskarsu_to_pegawai'                 => ['riwayat_kariskarsu', 'nip', 'pegawai', 'nip'],
        'fk_nip_rkarpeg_to_pegawai'                     => ['riwayat_karpeg', 'nip', 'pegawai', 'nip'],
        'fk_nip_rwyalamat_to_pegawai'                   => ['riwayat_alamat', 'nip', 'pegawai', 'nip'],
        'fk_nip_rwykeluarga_to_pegawai'                 => ['riwayat_keluarga', 'nip', 'pegawai', 'nip'],
        'fk_nip_rwyorganisasi_to_pegawai'               => ['riwayat_organisasi', 'nip', 'pegawai', 'nip'],
        'fk_nip_rwytj_to_pegawai'                       => ['riwayat_tanda_jasa', 'nip', 'pegawai', 'nip'],
    ];

    private const CHECKS = [
        'chk_jenis_rwy_status'          => ['jenis_rwy', 'statusin1,2,10'],
        'chk_riwayat_alamat_status'     => ['riwayat_alamat', 'statusin0,1,2,10'],
        'chk_riwayat_kariskarsu_status' => ['riwayat_kariskarsu', 'statusin0,1,2,10'],
        'chk_riwayat_karpeg_status'     => ['riwayat_karpeg', 'statusin0,1,2,10'],
        'chk_riwayat_keluarga_status'   => ['riwayat_keluarga', 'statusin0,1,2,3,10'],
        'chk_riwayat_organisasi_status' => ['riwayat_organisasi', 'statusin0,1,2,3,10'],
        'chk_riwayat_tanda_jasa_status' => ['riwayat_tanda_jasa', 'statusin0,1,2,3,10'],
    ];

    private const DEFAULTS = [
        'jenis_rwy' => [
            'order'      => '1',
            'status'     => '1',
            'updated_at' => 'CURRENT_TIMESTAMP on update',
        ],
        'riwayat_keluarga' => [
            'id_riwayat_keluarga' => 'auto_increment',
            'jumlah_anak'         => '0',
            'status'              => '0',
            'show_notif'          => '0',
            'created_at'          => 'CURRENT_TIMESTAMP',
            'updated_at'          => 'CURRENT_TIMESTAMP on update',
        ],
        'detail_anak' => [
            'id_detail_anak' => 'auto_increment',
            'urutan'         => '1',
            'created_at'     => 'CURRENT_TIMESTAMP',
            'updated_at'     => 'NULL on update',
        ],
        'riwayat_alamat' => [
            'id_riwayat_alamat' => 'auto_increment',
            'status'            => '0',
            'show_notif'        => '0',
            'created_at'        => 'CURRENT_TIMESTAMP',
            'updated_at'        => 'CURRENT_TIMESTAMP on update',
        ],
        'riwayat_karpeg' => [
            'id_riwayat_karpeg' => 'auto_increment',
            'status'            => '0',
            'show_notif'        => '0',
            'rated'             => '2',
            'created_at'        => 'CURRENT_TIMESTAMP',
            'updated_at'        => 'CURRENT_TIMESTAMP on update',
        ],
        'riwayat_kariskarsu' => [
            'id_riwayat_kariskarsu' => 'auto_increment',
            'status'                => '0',
            'show_notif'            => '0',
            'rated'                 => '2',
            'created_at'            => 'CURRENT_TIMESTAMP',
            'updated_at'            => 'CURRENT_TIMESTAMP on update',
        ],
        'riwayat_tanda_jasa' => [
            'id_riwayat_tanda_jasa' => 'auto_increment',
            'siasn_flag'            => '0',
            'status'                => '0',
            'show_notif'            => '0',
            'created_at'            => 'CURRENT_TIMESTAMP',
            'updated_at'            => 'CURRENT_TIMESTAMP on update',
        ],
        'riwayat_organisasi' => [
            'id_riwayat_organisasi' => 'auto_increment',
            'status'                => '0',
            'show_notif'            => '0',
            'created_at'            => 'CURRENT_TIMESTAMP',
            'updated_at'            => 'CURRENT_TIMESTAMP on update',
        ],
        'document_attachment' => [
            'id_attachment' => 'auto_increment',
            'created_at'    => 'CURRENT_TIMESTAMP',
            'updated_at'    => 'NULL on update',
        ],
    ];

    public function testMigrationCreatesNoRowsExceptJenisRwySeed(): void
    {
        foreach (self::TABLES as $table) {
            $expected = $table === 'jenis_rwy' ? 21 : 0;
            $this->assertSame($expected, $this->db->table($table)->countAllResults(), "jumlah baris {$table} setelah migration");
        }
    }

    /**
     * Seed `jenis_rwy` = konstanta legacy: kode & kunci (ARSIP_RWY), label & urutan (JENIS_RWY_ARSIP), ditambah baris 0
     * "Belum Terhubung" [V2] untuk `document_attachment.id_riwayat = 0` legacy. Semua aktif. Kode `skl.jenis_rwy`
     * (1-5, survei layanan) bukan bagian lookup ini.
     */
    public function testJenisRwySeedMatchesLegacyConstants(): void
    {
        $rows = $this->db->query('SELECT `id_jenis_rwy`, `kode`, `jenis_rwy`, `tabel_entri`, `order`, `status`, `updated_by` FROM '
            . $this->skemaQuoted('jenis_rwy') . ' ORDER BY `order`, `id_jenis_rwy`')->getResultArray();

        $expected = [[0, 'belum_terhubung', 'Belum Terhubung', null, 0]];
        $kodeById = array_flip(self::LEGACY_ARSIP_RWY);
        $order    = 0;

        foreach (self::LEGACY_JENIS_RWY_ARSIP as $id => $label) {
            $expected[] = [$id, $kodeById[$id], $label, self::TABEL_ENTRI[$id], ++$order];
        }

        $this->assertCount(21, $rows);
        $this->assertSame(
            $expected,
            array_map(static fn (array $r): array => [
                (int) $r['id_jenis_rwy'], (string) $r['kode'], (string) $r['jenis_rwy'], $r['tabel_entri'], (int) $r['order'],
            ], $rows),
        );
        $this->assertSame(['1'], array_values(array_unique(array_map(static fn (array $r): string => (string) $r['status'], $rows))));
        $this->assertSame([null], array_values(array_unique(array_column($rows, 'updated_by'))));
    }

    /**
     * Lapis DB (strictOn=true): FK polimorfik `document_attachment.id_riwayat` → `jenis_rwy` (kode 0 & NULL diterima,
     * kode di luar seed ditolak), UNIQUE `jenis_rwy`, `detail_anak` RESTRICT (legacy CASCADE), status per tabel
     * (keluarga/tanda jasa/organisasi 0/1/2/3/10; alamat & karpeg/kariskarsu 0/1/2/10), `rated` default 2, FK wilayah.
     */
    public function testConstraintsAreEnforced(): void
    {
        $this->insertPegawai();

        // document_attachment [K] + FK [V2] ke jenis_rwy.
        $da = ['NIP' => self::NIP, 'filename' => 'sk.pdf', 'id_entri' => '1'];
        $this->db->table('document_attachment')->insert([...$da, 'id_riwayat' => 0]);
        $this->db->table('document_attachment')->insert([...$da, 'id_riwayat' => null]);
        $this->db->table('document_attachment')->insert([...$da, 'id_riwayat' => 42]);
        $this->assertDbError([1452], fn () => $this->db->table('document_attachment')->insert([...$da, 'id_riwayat' => 33]));
        $this->assertDbError([1452], fn () => $this->db->table('document_attachment')->insert([...$da, 'NIP' => self::NIP_ASING, 'id_riwayat' => 1]));
        $this->assertDbError([1451], fn () => $this->db->table('jenis_rwy')->where('id_jenis_rwy', 0)->delete());

        // jenis_rwy: UNIQUE kode & label, CHECK status 1/2/10.
        $this->assertDbError([1062], fn () => $this->db->table('jenis_rwy')->insert(['id_jenis_rwy' => 99, 'kode' => 'alamat', 'jenis_rwy' => 'Lain']));
        $this->assertDbError([1062], fn () => $this->db->table('jenis_rwy')->insert(['id_jenis_rwy' => 99, 'kode' => 'lain', 'jenis_rwy' => 'Alamat']));
        $this->assertDbError(self::ERR_CHECK, fn () => $this->db->table('jenis_rwy')->insert(['id_jenis_rwy' => 99, 'kode' => 'lain', 'jenis_rwy' => 'Lain', 'status' => 3]));

        // riwayat_keluarga + detail_anak: status 0/1/2/3/10; anak wajib punya induk; induk yang punya anak tidak bisa
        // dihapus keras (RESTRICT; service menghapus anak & lampiran lebih dulu).
        $this->db->table('riwayat_keluarga')->insert(['nip' => self::NIP, 'status' => 3]);
        $idKeluarga = $this->db->insertID();
        $this->seeInDatabase('riwayat_keluarga', ['id_riwayat_keluarga' => $idKeluarga, 'jumlah_anak' => 0]);
        $this->assertDbError(self::ERR_CHECK, fn () => $this->db->table('riwayat_keluarga')->insert(['nip' => self::NIP, 'status' => 4]));
        $this->db->table('detail_anak')->insert(['id_riwayat_keluarga' => $idKeluarga, 'tgl_lahir' => '2015-06-01', 'nama' => 'Anak Uji']);
        $this->seeInDatabase('detail_anak', ['id_riwayat_keluarga' => $idKeluarga, 'urutan' => 1]);
        $this->assertDbError([1452], fn () => $this->db->table('detail_anak')->insert(['id_riwayat_keluarga' => 424242, 'tgl_lahir' => '2015-06-01', 'nama' => 'Yatim']));
        $this->assertDbError([1451], fn () => $this->db->table('riwayat_keluarga')->where('id_riwayat_keluarga', $idKeluarga)->delete());

        // riwayat_alamat: status 0/1/2/10 (tanpa 3); FK wilayah (kode sentinel 99 valid, 31 tidak ada di DB test).
        $alamat = ['nip' => self::NIP, 'jenis_alamat' => 1];
        $this->db->table('riwayat_alamat')->insert([...$alamat, 'id_provinsi' => '99']);
        $this->assertDbError(self::ERR_CHECK, fn () => $this->db->table('riwayat_alamat')->insert([...$alamat, 'status' => 3]));
        $this->assertDbError([1452], fn () => $this->db->table('riwayat_alamat')->insert([...$alamat, 'id_provinsi' => '31']));

        // karpeg/kariskarsu: rated default 2 (Belum), status 0/1/2/10.
        $this->db->table('riwayat_karpeg')->insert(['nip' => self::NIP]);
        $this->seeInDatabase('riwayat_karpeg', ['nip' => self::NIP, 'rated' => 2, 'status' => 0]);
        $this->assertDbError(self::ERR_CHECK, fn () => $this->db->table('riwayat_kariskarsu')->insert(['nip' => self::NIP, 'status' => 3]));

        // tanda jasa & organisasi: status 0/1/2/3/10; master tanda_jasa (target kosong) ditolak.
        $this->db->table('riwayat_tanda_jasa')->insert(['nip' => self::NIP, 'tgl_sertifikat' => '2020-08-17', 'status' => 3]);
        $this->assertDbError([1452], fn () => $this->db->table('riwayat_tanda_jasa')->insert(['nip' => self::NIP, 'tgl_sertifikat' => '2020-08-17', 'id_tanda_jasa' => 99]));
        $this->db->table('riwayat_organisasi')->insert(['nip' => self::NIP, 'tgl_mulai' => '2019-01-01', 'status' => 3]);
        $this->assertDbError(self::ERR_CHECK, fn () => $this->db->table('riwayat_organisasi')->insert(['nip' => self::NIP, 'tgl_mulai' => '2019-01-01', 'status' => 5]));

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
