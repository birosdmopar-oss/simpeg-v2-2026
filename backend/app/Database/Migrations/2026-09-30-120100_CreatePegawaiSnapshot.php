<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Migration;
use RuntimeException;
use Throwable;

/**
 * DBV-012 (2/2) — B-01: 13 tabel snapshot `pegawai_*` (data terkini per pegawai, satu baris per `nip`; ADR-006
 * `BaseSnapshotModel::syncToActiveSnapshot()` meng-upsert baris ini hanya di approval final). Review DB Validator:
 * backend/docs/db-review/B-01-B-02-pegawai-riwayat-schema.md (Bagian 2.1) — JANGAN dijalankan di Dev/Production sebelum
 * disetujui DBV-012.
 *
 * Sumber per tabel:
 *   - `pegawai_kp`, `pegawai_mutasi_jabatan`: SHOW CREATE TABLE salinan lokal `simpeg_prod_duplikat` [L] (kolom,
 *     tipe, NULL, default, urutan persis) + nama FK [K-erd].
 *   - `pegawai_cpns`, `pegawai_pns`: nama FK [K-erd]; kolom [I] = `pegawai_kp` (snapshot KP jenis pengangkatan
 *     CPNS/PNS: simapi A_employee.php:1439-1468, :1524-1530 membaca `id_riwayat_kp`, `tmtsk`, `tgl_sk`, `no_sk`,
 *     `id_jenis_kp`, `id_pangkat`, `mker_*`, `gaji_pokok`).
 *   - `pegawai_kgb`, `pegawai_pendidikan`, `pegawai_diklat`, `pegawai_hukdis`, `pegawai_ak`, `pegawai_keluarga`,
 *     `pegawai_alamat`, `pegawai_alamat_kantor`, `pegawai_tanda_jasa`: nama FK [K-erd]; kolom [I] = kolom bisnis
 *     tabel riwayat asalnya yang dibaca kode legacy (L_employee.php, L_ak.php:48/:359, Tester.php:3716-3747,
 *     simapi A_employee.php). DDL produksi belum ada → tipe menunggu SHOW CREATE TABLE.
 *
 * Tidak ada titik tulis PHP legacy ke tabel snapshot (kemungkinan diisi trigger DB pada `riwayat_*`, di luar rentang
 * dump). Aturan pemilihan baris riwayat yang menjadi snapshot ditetapkan di B-07..B-17, bukan di skema.
 *
 * Aturan umum: PK `nip` (FK ke `pegawai`), `id_riwayat_*` INT NULL, kolom bisnis salinan riwayat, hanya `updated_at`
 * NOT NULL ON UPDATE [L pegawai_kp] — tanpa `status`, `*_by`, maupun kolom workflow. KEY untuk kolom FK yang baru
 * dipasang belakangan sudah dibuat di sini dengan nama FK-nya:
 *   - FK snapshot → riwayat (13) dipasang migration 2026-09-30-131000_AddFkSnapshotKeRiwayat (DBV-013);
 *   - FK ke tabel G-02 (`pegawai_mutasi_jabatan` 13, `pegawai_ak` 1) ditahan di migration AddFkG02Pegawai sampai
 *     DBV-008 merge (tidak ikut PR B-01).
 *
 * Deviasi dari legacy (dicatat untuk DBV):
 *   - Semua FK ON DELETE RESTRICT ON UPDATE RESTRICT (legacy [L]: nip CASCADE/CASCADE, master SET NULL/CASCADE).
 *   - `pegawai_mutasi_jabatan` [L] hanya punya KEY `fk_id_unit_pmj_to_unit`/`fk_id_satker_pmj_to_satker`; KEY lain
 *     untuk kolom FK [K-erd] ditambahkan (nama = nama FK).
 *   - Tabel [I] di atas: tipe dan NULL mengikuti riwayat asal / pola [L] `pegawai_kp` (mis. `keterangan` TINYTEXT);
 *     wajib dicocokkan dengan DDL produksi.
 *
 * DDL MySQL/MariaDB ter-commit per statement dan migration yang gagal tidak tercatat di tabel `migrations`: bila salah
 * satu CREATE gagal, up() men-drop tabel yang sempat dibuat PADA RUN ITU (urutan terbalik) lalu melempar ulang error,
 * sehingga `php spark migrate` bisa langsung diulang. Tabel yang sudah ada sebelum run tidak disentuh.
 */
class CreatePegawaiSnapshot extends Migration
{
    private const TABLE_OPTIONS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    private const RESTRICT = 'ON DELETE RESTRICT ON UPDATE RESTRICT';

    /**
     * Kolom audit snapshot [L pegawai_kp]: satu-satunya kolom audit.
     */
    private const UPDATED_AT = '`updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP';

    /**
     * Urutan pembuatan (urutan down() = kebalikannya). Tidak ada FK di antara tabel snapshot.
     */
    public const TABLES = [
        'pegawai_kp', 'pegawai_cpns', 'pegawai_pns', 'pegawai_kgb', 'pegawai_pendidikan', 'pegawai_diklat',
        'pegawai_hukdis', 'pegawai_ak', 'pegawai_keluarga', 'pegawai_alamat', 'pegawai_alamat_kantor',
        'pegawai_tanda_jasa', 'pegawai_mutasi_jabatan',
    ];

    public function up(): void
    {
        $created = [];

        try {
            foreach ($this->createStatements() as $table => $sql) {
                $this->exec($sql);
                $created[] = $table;
            }
        } catch (Throwable $e) {
            $this->dropCreated($created);

            throw $e;
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::TABLES) as $table) {
            $this->exec("DROP TABLE IF EXISTS {$this->t($table)}");
        }
    }

    /**
     * CREATE TABLE per tabel, urut self::TABLES.
     *
     * @return array<string, string> tabel => SQL
     */
    private function createStatements(): array
    {
        $updatedAt = self::UPDATED_AT;
        $sql       = [];

        // [L] simpeg_prod_duplikat.pegawai_kp persis + FK [K-erd].
        $sql['pegawai_kp'] = $this->kpSnapshotSql('pegawai_kp', [
            'nip'      => 'fk_nip_pegkp_to_pegawai',
            'rwy'      => 'fk_id_riwayat_kp_peg_kp_to_rwy_kp',
            'jenis_kp' => 'fk_id_jenis_kp_peg_kp_to_jenis_kp',
            'pangkat'  => 'fk_id_pangkat_peg_kp_to_pangkat',
        ]);

        // [K-erd] + kolom [I] = pegawai_kp (snapshot KP pengangkatan CPNS, id_jenis_kp 1).
        $sql['pegawai_cpns'] = $this->kpSnapshotSql('pegawai_cpns', [
            'nip'      => 'fk_nip_pcpns_to_pegawai',
            'rwy'      => 'fk_id_riwayat_kp_pcpns_to_rwy_kp',
            'jenis_kp' => 'fk_id_jenis_kp_pcpns_to_jenis_kp',
            'pangkat'  => 'fk_id_pangkat_pcpns_to_pangkat',
        ]);

        // [K-erd] + kolom [I] = pegawai_kp (snapshot KP pengangkatan PNS, id_jenis_kp 2).
        $sql['pegawai_pns'] = $this->kpSnapshotSql('pegawai_pns', [
            'nip'      => 'fk_nip_ppns_to_pegawai',
            'rwy'      => 'fk_id_riwayat_kp_ppns_to_rwy_kp',
            'jenis_kp' => 'fk_id_jenis_kp_ppns_to_jenis_kp',
            'pangkat'  => 'fk_id_pangkat_ppns_to_pangkat',
        ]);

        // [I] kolom bisnis riwayat_kgb (L_kgb.php:508-527) + id_gol_pppk (FK ERD pegawai_kgb_ibfk_01).
        $sql['pegawai_kgb'] = "CREATE TABLE {$this->t('pegawai_kgb')} (
            `nip` VARCHAR(30) NOT NULL,
            `id_riwayat_kgb` INT NULL DEFAULT NULL,
            `id_gol_pppk` TINYINT NULL DEFAULT NULL,
            `tmtsk` DATE NOT NULL,
            `no_sk` VARCHAR(100) NULL DEFAULT NULL,
            `tgl_sk` DATE NULL DEFAULT NULL,
            `mker_gol_th` TINYINT NULL DEFAULT NULL,
            `gaji_pokok` INT NULL DEFAULT NULL,
            `jym` VARCHAR(255) NULL DEFAULT NULL COMMENT 'jabatan yang menandatangani SK',
            `keterangan` TINYTEXT NULL,
            {$updatedAt},
            PRIMARY KEY (`nip`),
            KEY `fk_id_riwayat_kgb_pkgb_to_rkgb` (`id_riwayat_kgb`),
            KEY `pegawai_kgb_ibfk_01` (`id_gol_pppk`),
            {$this->nipFk('fk_nip_pegkgb_to_pegawai')},
            {$this->fk('pegawai_kgb_ibfk_01', 'id_gol_pppk', 'gol_pppk', 'id_gol_pppk')}
        ) " . self::TABLE_OPTIONS;

        // [I] seed rekonstruksi + kolom yang dibaca kode (Tester.php:3724, L_employee.php:2473, A_employee.php:280-281).
        $sql['pegawai_pendidikan'] = "CREATE TABLE {$this->t('pegawai_pendidikan')} (
            `nip` VARCHAR(30) NOT NULL,
            `id_riwayat_pendidikan` INT NULL DEFAULT NULL,
            `id_jenjang_pendidikan` INT NULL DEFAULT NULL,
            `id_bidang_pendidikan` TINYINT NULL DEFAULT NULL,
            `id_jurusan_pendidikan` INT NULL DEFAULT NULL,
            `tgl_lulus` DATE NULL DEFAULT NULL,
            `no_ijazah` VARCHAR(100) NULL DEFAULT NULL,
            `no_sk_penc_glr` VARCHAR(100) NULL DEFAULT NULL,
            `institusi_pendidikan` VARCHAR(255) NULL DEFAULT NULL,
            `jenjang_pendidikan_singkat` VARCHAR(50) NULL DEFAULT NULL,
            `bidang_pendidikan` VARCHAR(100) NULL DEFAULT NULL,
            `bidang_pendidikan_lain` VARCHAR(255) NULL DEFAULT NULL,
            `jurusan_pendidikan` VARCHAR(255) NULL DEFAULT NULL,
            `jurusan_pendidikan_lain` VARCHAR(255) NULL DEFAULT NULL,
            `nem` DOUBLE NULL DEFAULT NULL,
            `ipk` DOUBLE NULL DEFAULT NULL,
            `keterangan` TINYTEXT NULL,
            `id_rw_siasn` VARCHAR(100) NULL DEFAULT NULL,
            {$updatedAt},
            PRIMARY KEY (`nip`),
            KEY `fk_id_riwayat_pendidikan_pegpend_to_rpend` (`id_riwayat_pendidikan`),
            KEY `fk_id_jenjang_pendidikan_pegpend_to_jpend` (`id_jenjang_pendidikan`),
            KEY `fk_id_bidang_pendidikan_pegpend_to_bpend` (`id_bidang_pendidikan`),
            KEY `fk_id_jurusan_pendidikan_pegpend_to_jupend` (`id_jurusan_pendidikan`),
            {$this->nipFk('fk_nip_pegpend_to_pegawai')},
            {$this->fk('fk_id_jenjang_pendidikan_pegpend_to_jpend', 'id_jenjang_pendidikan', 'jenjang_pendidikan', 'id_jenjang_pendidikan')},
            {$this->fk('fk_id_bidang_pendidikan_pegpend_to_bpend', 'id_bidang_pendidikan', 'bidang_pendidikan', 'id_bidang_pendidikan')},
            {$this->fk('fk_id_jurusan_pendidikan_pegpend_to_jupend', 'id_jurusan_pendidikan', 'jurusan_pendidikan', 'id_jurusan_pendidikan')}
        ) " . self::TABLE_OPTIONS;

        // [I] kolom bisnis riwayat_diklat (L_diklat.php:626-680) yang dibaca dari snapshot: Tester.php:3723 (nama_diklat),
        // simapi A_employee.php:939-970 (pdik.*: jenis_diklat, nama_diklat, sub_group_jabatan, deskripsi, no/tgl sertifikat,
        // jumlah_jp, instansi_penyelenggara, keterangan).
        $sql['pegawai_diklat'] = "CREATE TABLE {$this->t('pegawai_diklat')} (
            `nip` VARCHAR(30) NOT NULL,
            `id_riwayat_diklat` INT NULL DEFAULT NULL,
            `id_diklat` TINYINT NULL DEFAULT NULL,
            `jenis_diklat` TINYINT(1) NULL DEFAULT NULL COMMENT '1: Struktural, 2: Teknis, 3: Fungsional, 4: Prajabatan, 5: Sertifikasi',
            `nama_diklat` VARCHAR(255) NULL DEFAULT NULL,
            `sub_group_jabatan` VARCHAR(100) NULL DEFAULT NULL,
            `tgl_sertifikat` DATE NULL DEFAULT NULL,
            `no_sertifikat` VARCHAR(100) NULL DEFAULT NULL,
            `instansi_penyelenggara` VARCHAR(255) NULL DEFAULT NULL,
            `deskripsi` TEXT NULL,
            `jumlah_jp` SMALLINT NULL DEFAULT NULL,
            `keterangan` TEXT NULL,
            {$updatedAt},
            PRIMARY KEY (`nip`),
            KEY `fk_id_riwayat_diklat_pegdiklat_to_rwydiklat` (`id_riwayat_diklat`),
            KEY `fk_id_diklat_pegdiklat_to_diklat` (`id_diklat`),
            {$this->nipFk('fk_nip_pegdiklat_to_pegawai')},
            {$this->fk('fk_id_diklat_pegdiklat_to_diklat', 'id_diklat', 'diklat', 'id_diklat')}
        ) " . self::TABLE_OPTIONS;

        // [I] kolom bisnis riwayat_hukdis (L_hukdis.php:463-500); dibaca tmtsk, akhir_hukdis, alasan_hukuman (Tester.php:3729).
        $sql['pegawai_hukdis'] = "CREATE TABLE {$this->t('pegawai_hukdis')} (
            `nip` VARCHAR(30) NOT NULL,
            `id_riwayat_hukdis` INT NULL DEFAULT NULL,
            `id_tingkat_hukdis` INT NULL DEFAULT NULL,
            `tingkat_hukdis` VARCHAR(100) NULL DEFAULT NULL,
            `id_jenis_hukdis` INT NULL DEFAULT NULL,
            `jenis_hukdis` VARCHAR(255) NULL DEFAULT NULL,
            `id_pangkat` TINYINT NULL DEFAULT NULL,
            `gol` VARCHAR(10) NULL DEFAULT NULL,
            `ruang` VARCHAR(10) NULL DEFAULT NULL,
            `gol_ruang` VARCHAR(10) NULL DEFAULT NULL,
            `pangkat` VARCHAR(50) NULL DEFAULT NULL,
            `no_sk` VARCHAR(100) NULL DEFAULT NULL,
            `tgl_sk` DATE NULL DEFAULT NULL,
            `tmtsk` DATE NULL DEFAULT NULL,
            `masa_hukuman` TEXT NULL,
            `akhir_hukdis` DATE NULL DEFAULT NULL,
            `aturan_dilanggar` TEXT NULL,
            `alasan_hukuman` TEXT NULL,
            `keterangan` TEXT NULL,
            {$updatedAt},
            PRIMARY KEY (`nip`),
            KEY `fk_id_riwayat_hukdis_peg_hukdis_to_rwy_hukdis` (`id_riwayat_hukdis`),
            KEY `fk_id_tingkat_hukdis_peg_hukdis_to_tingkat_hukdis` (`id_tingkat_hukdis`),
            KEY `fk_id_jenis_hukdis_peg_hukdis_to_jenis_hukdis` (`id_jenis_hukdis`),
            KEY `fk_id_pangkat_peg_hukdis_to_pangkat` (`id_pangkat`),
            {$this->nipFk('fk_nip_peghukdis_to_pegawai')},
            {$this->fk('fk_id_tingkat_hukdis_peg_hukdis_to_tingkat_hukdis', 'id_tingkat_hukdis', 'tingkat_hukdis', 'id_tingkat_hukdis')},
            {$this->fk('fk_id_jenis_hukdis_peg_hukdis_to_jenis_hukdis', 'id_jenis_hukdis', 'jenis_hukdis', 'id_jenis_hukdis')},
            {$this->fk('fk_id_pangkat_peg_hukdis_to_pangkat', 'id_pangkat', 'pangkat', 'id_pangkat')}
        ) " . self::TABLE_OPTIONS;

        // [I] L_ak.php:48 (id_riwayat_ak, nilai_ak, periode_akhir, file_pak, jenis_ak) & :359 (id_jabatan, id_pangkat).
        // FK id_jabatan (G-02) ditahan; KEY-nya sudah dibuat.
        $sql['pegawai_ak'] = "CREATE TABLE {$this->t('pegawai_ak')} (
            `nip` VARCHAR(30) NOT NULL,
            `id_riwayat_ak` INT NULL DEFAULT NULL,
            `id_jabatan` INT NULL DEFAULT NULL,
            `id_pangkat` TINYINT NULL DEFAULT NULL,
            `jenis_ak` TINYINT NULL DEFAULT NULL,
            `no_hpak` VARCHAR(100) NULL DEFAULT NULL,
            `tgl_pak` DATE NULL DEFAULT NULL,
            `periode_awal` DATE NULL DEFAULT NULL,
            `periode_akhir` DATE NULL DEFAULT NULL,
            `nilai_ak` DECIMAL(8,3) NULL DEFAULT NULL,
            `file_pak` VARCHAR(255) NULL DEFAULT NULL,
            {$updatedAt},
            PRIMARY KEY (`nip`),
            KEY `fk_id_riwayat_ak_cak_to_rak` (`id_riwayat_ak`),
            KEY `fk_id_jabatan_cak_to_jab` (`id_jabatan`),
            KEY `fk_id_pangkat_cak_to_pangkat` (`id_pangkat`),
            {$this->nipFk('fk_nip_cak_to_peg')},
            {$this->fk('fk_id_pangkat_cak_to_pangkat', 'id_pangkat', 'pangkat', 'id_pangkat')}
        ) " . self::TABLE_OPTIONS;

        // [I] kolom bisnis riwayat_keluarga (L_keluarga.php:1035-1050).
        $sql['pegawai_keluarga'] = "CREATE TABLE {$this->t('pegawai_keluarga')} (
            `nip` VARCHAR(30) NOT NULL,
            `id_riwayat_keluarga` INT NULL DEFAULT NULL,
            `urutan_perkawinan` TINYINT NULL DEFAULT NULL,
            `tgl_perkawinan` DATE NULL DEFAULT NULL,
            `kota_perkawinan` VARCHAR(255) NULL DEFAULT NULL,
            `nama_pasangan` VARCHAR(255) NULL DEFAULT NULL,
            `jumlah_anak` TINYINT NOT NULL DEFAULT 0,
            `keterangan` TINYTEXT NULL,
            {$updatedAt},
            PRIMARY KEY (`nip`),
            KEY `fk_id_riwayat_keluarga_pegkeluarga_rwykeluarga` (`id_riwayat_keluarga`),
            {$this->nipFk('fk_nip_pegkeluarga_to_pegawai')}
        ) " . self::TABLE_OPTIONS;

        // [I] kolom yang dibaca (Tester.php:3730, L_employee.php:5899-5903, :6974).
        $alamat                = $this->alamatColumnsSql();
        $sql['pegawai_alamat'] = "CREATE TABLE {$this->t('pegawai_alamat')} (
            `nip` VARCHAR(30) NOT NULL,
            `id_riwayat_alamat` INT NULL DEFAULT NULL,
            {$alamat},
            {$updatedAt},
            PRIMARY KEY (`nip`),
            KEY `fk_id_riwayat_alamat_pegalamat_riwalamat` (`id_riwayat_alamat`),
            KEY `fk_id_provinsi_pegalamat_provinsi` (`id_provinsi`),
            KEY `fk_id_kabupaten_kota_pegalamat_kabkota` (`id_kabupaten_kota`),
            KEY `fk_id_kecamatan_pegalamat_kecamatan` (`id_kecamatan`),
            KEY `fk_id_kelurahan_pegalamat_kelurahan` (`id_kelurahan`),
            {$this->nipFk('fk_nip_pegalamat_to_pegawai')},
            {$this->fk('fk_id_provinsi_pegalamat_provinsi', 'id_provinsi', 'provinsi', 'id_provinsi')},
            {$this->fk('fk_id_kabupaten_kota_pegalamat_kabkota', 'id_kabupaten_kota', 'kabupaten_kota', 'id_kabupaten_kota')},
            {$this->fk('fk_id_kecamatan_pegalamat_kecamatan', 'id_kecamatan', 'kecamatan', 'id_kecamatan')},
            {$this->fk('fk_id_kelurahan_pegalamat_kelurahan', 'id_kelurahan', 'kelurahan', 'id_kelurahan')}
        ) " . self::TABLE_OPTIONS;

        // [I] L_employee.php:5903-5916 (alias pak), simapi A_employee.php:510. Nama FK [K-erd] ibfk_1..6.
        $sql['pegawai_alamat_kantor'] = "CREATE TABLE {$this->t('pegawai_alamat_kantor')} (
            `nip` VARCHAR(30) NOT NULL,
            `id_riwayat_alamat` INT NULL DEFAULT NULL,
            {$alamat},
            `nama_kantor` VARCHAR(255) NULL DEFAULT NULL,
            `lantai` VARCHAR(50) NULL DEFAULT NULL,
            `telp` VARCHAR(50) NULL DEFAULT NULL,
            `faks` VARCHAR(50) NULL DEFAULT NULL,
            {$updatedAt},
            PRIMARY KEY (`nip`),
            KEY `pegawai_alamat_kantor_ibfk_1` (`id_kabupaten_kota`),
            KEY `pegawai_alamat_kantor_ibfk_2` (`id_kecamatan`),
            KEY `pegawai_alamat_kantor_ibfk_3` (`id_kelurahan`),
            KEY `pegawai_alamat_kantor_ibfk_4` (`id_provinsi`),
            KEY `pegawai_alamat_kantor_ibfk_5` (`id_riwayat_alamat`),
            {$this->fk('pegawai_alamat_kantor_ibfk_1', 'id_kabupaten_kota', 'kabupaten_kota', 'id_kabupaten_kota')},
            {$this->fk('pegawai_alamat_kantor_ibfk_2', 'id_kecamatan', 'kecamatan', 'id_kecamatan')},
            {$this->fk('pegawai_alamat_kantor_ibfk_3', 'id_kelurahan', 'kelurahan', 'id_kelurahan')},
            {$this->fk('pegawai_alamat_kantor_ibfk_4', 'id_provinsi', 'provinsi', 'id_provinsi')},
            {$this->nipFk('pegawai_alamat_kantor_ibfk_6')}
        ) " . self::TABLE_OPTIONS;

        // [I] kolom bisnis riwayat_tanda_jasa (L_tj.php:490-505) yang dibaca dari snapshot: Tester.php:3727 (tanda_jasa,
        // tgl_sertifikat), simapi A_employee.php:2112-2131 (ptj.*: no_sertifikat, negara, keterangan).
        $sql['pegawai_tanda_jasa'] = "CREATE TABLE {$this->t('pegawai_tanda_jasa')} (
            `nip` VARCHAR(30) NOT NULL,
            `id_riwayat_tanda_jasa` INT NULL DEFAULT NULL,
            `id_tanda_jasa` INT NULL DEFAULT NULL,
            `tanda_jasa` VARCHAR(255) NULL DEFAULT NULL,
            `tanda_jasa_lain` VARCHAR(255) NULL DEFAULT NULL,
            `tgl_sertifikat` DATE NULL DEFAULT NULL,
            `no_sertifikat` VARCHAR(100) NULL DEFAULT NULL,
            `negara` VARCHAR(100) NULL DEFAULT NULL,
            `keterangan` TEXT NULL,
            {$updatedAt},
            PRIMARY KEY (`nip`),
            KEY `fk_id_riwayat_tanda_jasa_ptj_to_rtj` (`id_riwayat_tanda_jasa`),
            KEY `fk_id_tanda_jasa_pegtj_to_tj` (`id_tanda_jasa`),
            {$this->nipFk('fk_nip_pegtj_to_pegawai')},
            {$this->fk('fk_id_tanda_jasa_pegtj_to_tj', 'id_tanda_jasa', 'tanda_jasa', 'id_tanda_jasa')}
        ) " . self::TABLE_OPTIONS;

        $sql['pegawai_mutasi_jabatan'] = $this->mutasiJabatanSql();

        return $sql;
    }

    /**
     * Snapshot KP [L pegawai_kp] — dipakai `pegawai_kp`, `pegawai_cpns`, `pegawai_pns` (kolom identik).
     *
     * @param array{nip: string, rwy: string, jenis_kp: string, pangkat: string} $fk nama FK/KEY
     */
    private function kpSnapshotSql(string $table, array $fk): string
    {
        return "CREATE TABLE {$this->t($table)} (
            `nip` VARCHAR(30) NOT NULL,
            `id_riwayat_kp` INT NULL DEFAULT NULL,
            `id_jenis_kp` TINYINT NULL DEFAULT NULL,
            `id_pangkat` TINYINT NULL DEFAULT NULL,
            `tmtsk` DATE NOT NULL,
            `tgl_sk` DATE NOT NULL,
            `no_sk` VARCHAR(100) NULL DEFAULT NULL,
            `tgl_pertek_bkn` DATE NULL DEFAULT NULL,
            `no_pertek_bkn` VARCHAR(100) NULL DEFAULT NULL,
            `jumlah_kredit_utama` DOUBLE NULL DEFAULT NULL,
            `jumlah_kredit_tambahan` DOUBLE NULL DEFAULT NULL,
            `jenis_kp` VARCHAR(100) NOT NULL,
            `gol` VARCHAR(10) NOT NULL,
            `ruang` VARCHAR(10) NOT NULL,
            `gol_ruang` VARCHAR(10) NOT NULL,
            `pangkat` VARCHAR(50) NOT NULL,
            `mker_th` TINYINT NULL DEFAULT NULL,
            `mker_bl` TINYINT NULL DEFAULT NULL,
            `gaji_pokok` INT NULL DEFAULT NULL,
            `keterangan` TINYTEXT NULL,
            `id_rw_siasn` VARCHAR(100) NULL DEFAULT NULL,
            " . self::UPDATED_AT . ",
            PRIMARY KEY (`nip`),
            KEY `{$fk['rwy']}` (`id_riwayat_kp`),
            KEY `{$fk['jenis_kp']}` (`id_jenis_kp`),
            KEY `{$fk['pangkat']}` (`id_pangkat`),
            {$this->nipFk($fk['nip'])},
            {$this->fk($fk['jenis_kp'], 'id_jenis_kp', 'jenis_kp', 'id_jenis_kp')},
            {$this->fk($fk['pangkat'], 'id_pangkat', 'pangkat', 'id_pangkat')}
        ) " . self::TABLE_OPTIONS;
    }

    /**
     * Kolom alamat [I] (`jenis_alamat` s.d. `alamat`), sama untuk `pegawai_alamat` dan `pegawai_alamat_kantor`; tipe
     * kode wilayah = PK master di `main` (CHAR 2/4/7/10).
     */
    private function alamatColumnsSql(): string
    {
        return "`jenis_alamat` TINYINT(1) NULL DEFAULT NULL COMMENT '1: KTP, 2: Domisili, 3: Kantor',
            `id_provinsi` CHAR(2) NULL DEFAULT NULL,
            `id_kabupaten_kota` CHAR(4) NULL DEFAULT NULL,
            `id_kecamatan` CHAR(7) NULL DEFAULT NULL,
            `id_kelurahan` CHAR(10) NULL DEFAULT NULL,
            `provinsi` VARCHAR(255) NULL DEFAULT NULL,
            `provinsi_lain` VARCHAR(255) NULL DEFAULT NULL,
            `kabupaten_kota` VARCHAR(255) NULL DEFAULT NULL,
            `kabupaten_kota_lain` VARCHAR(255) NULL DEFAULT NULL,
            `kecamatan` VARCHAR(255) NULL DEFAULT NULL,
            `kecamatan_lain` VARCHAR(255) NULL DEFAULT NULL,
            `kelurahan` VARCHAR(255) NULL DEFAULT NULL,
            `kelurahan_lain` VARCHAR(255) NULL DEFAULT NULL,
            `kd_pos` VARCHAR(10) NULL DEFAULT NULL,
            `alamat` TEXT NULL";
    }

    /**
     * [L] simpeg_prod_duplikat.pegawai_mutasi_jabatan persis (kolom, tipe, default, urutan) + KEY bernama FK [K-erd].
     * FK yang dipasang di sini: nip → pegawai, id_gol_pppk → gol_pppk. FK riwayat (131000) dan G-02 (ditahan) hanya
     * KEY-nya. `kelas_jabatan` tanpa FK (ERD tidak mencatat FK).
     */
    private function mutasiJabatanSql(): string
    {
        return "CREATE TABLE {$this->t('pegawai_mutasi_jabatan')} (
            `nip` VARCHAR(30) NOT NULL,
            `id_riwayat_mutasi_jabatan` INT NULL DEFAULT NULL,
            `id_group_jabatan` INT NULL DEFAULT NULL,
            `id_sub_group_jabatan` INT NULL DEFAULT NULL,
            `id_unit` INT NULL DEFAULT NULL,
            `id_satker` INT NULL DEFAULT NULL,
            `id_atasan_es_1` INT NULL DEFAULT NULL,
            `id_atasan_es_2` INT NULL DEFAULT NULL,
            `id_atasan_es_3` INT NULL DEFAULT NULL,
            `id_atasan_es_3_koord` INT NULL DEFAULT NULL,
            `id_atasan_es_4` INT NULL DEFAULT NULL,
            `id_atasan_es_4_koord` INT NULL DEFAULT NULL,
            `id_jabatan` INT NULL DEFAULT NULL,
            `id_jabatan_koord` INT NULL DEFAULT NULL,
            `id_gol_pppk` TINYINT NULL DEFAULT NULL,
            `id_rumpun_jabatan` TINYINT NULL DEFAULT NULL,
            `jenis_jabatan` TINYINT(1) NOT NULL DEFAULT 1,
            `jenis_jabatan_koord` TINYINT(1) NOT NULL DEFAULT 3,
            `jenis_mutasi` TINYINT(1) NOT NULL DEFAULT 1,
            `tmtsk` DATE NOT NULL,
            `no_sk` VARCHAR(100) NULL DEFAULT NULL,
            `tgl_sk` DATE NULL DEFAULT NULL,
            `pmk` YEAR NULL DEFAULT NULL,
            `mhpk_mulai` DATE NULL DEFAULT NULL,
            `mhpk_akhir` DATE NULL DEFAULT NULL,
            `gol_pppk` VARCHAR(10) NULL DEFAULT NULL,
            `nama_instansi` VARCHAR(255) NULL DEFAULT NULL,
            `group_jabatan` VARCHAR(45) NULL DEFAULT NULL,
            `sub_group_jabatan` VARCHAR(100) NULL DEFAULT NULL,
            `unit` VARCHAR(255) NULL DEFAULT NULL,
            `satker` VARCHAR(255) NULL DEFAULT NULL,
            `atasan_es_1` VARCHAR(255) NULL DEFAULT NULL,
            `atasan_es_2` VARCHAR(255) NULL DEFAULT NULL,
            `atasan_es_3` VARCHAR(255) NULL DEFAULT NULL,
            `atasan_es_3_koord` VARCHAR(255) NULL DEFAULT NULL,
            `atasan_es_4` VARCHAR(255) NULL DEFAULT NULL,
            `atasan_es_4_koord` VARCHAR(255) NULL DEFAULT NULL,
            `jabatan` VARCHAR(255) NULL DEFAULT NULL,
            `jabatan_koord` VARCHAR(255) NULL DEFAULT NULL,
            `jabatan_lain` VARCHAR(255) NULL DEFAULT NULL,
            `rumpun_jabatan` VARCHAR(50) NULL DEFAULT NULL,
            `subrumpun_jabatan` TEXT NULL,
            `kelas_jabatan` TINYINT NULL DEFAULT NULL,
            `kredit_jft` INT NULL DEFAULT NULL,
            `no_induk_jft` VARCHAR(50) NULL DEFAULT NULL,
            `status_jft` TINYINT(1) NULL DEFAULT NULL,
            `ser_dosen` VARCHAR(255) NULL DEFAULT NULL,
            `f_spmt` VARCHAR(255) NULL DEFAULT NULL,
            `f_bapel` VARCHAR(255) NULL DEFAULT NULL,
            `keterangan` MEDIUMTEXT NULL,
            `id_rw_siasn` VARCHAR(100) NULL DEFAULT NULL,
            " . self::UPDATED_AT . ",
            PRIMARY KEY (`nip`),
            KEY `fk_id_unit_pmj_to_unit` (`id_unit`),
            KEY `fk_id_satker_pmj_to_satker` (`id_satker`),
            KEY `fk_id_riwayat_mutasi_jabatan_pmj_to_rmj` (`id_riwayat_mutasi_jabatan`),
            KEY `fk_id_group_jabatan_pmj_to_gj` (`id_group_jabatan`),
            KEY `fk_id_sub_group_jabatan_pmj_to_sgj` (`id_sub_group_jabatan`),
            KEY `fk_id_jabatan_pmj_to_jabatan` (`id_jabatan`),
            KEY `fk_id_atasan_es_1_pmj_to_jabatan` (`id_atasan_es_1`),
            KEY `fk_id_atasan_es_2_pmj_to_jabatan` (`id_atasan_es_2`),
            KEY `fk_id_atasan_es_3_pmj_to_jabatan` (`id_atasan_es_3`),
            KEY `fk_id_atasan_es_4_pmj_to_jabatan` (`id_atasan_es_4`),
            KEY `fk_id_jabatan_koord_pmj_to_jabkoor` (`id_jabatan_koord`),
            KEY `fk_id_atasan_es_3_koord_pmj_to_jabkoor` (`id_atasan_es_3_koord`),
            KEY `fk_id_atasan_es_4_koord_pmj_to_jabkoor` (`id_atasan_es_4_koord`),
            KEY `pegawai_mutasi_jabatan_ibfk_01` (`id_gol_pppk`),
            KEY `pegawai_mutasi_jabatan_ibfk_02` (`id_rumpun_jabatan`),
            {$this->nipFk('fk_nip_pmj_to_pegawai')},
            {$this->fk('pegawai_mutasi_jabatan_ibfk_01', 'id_gol_pppk', 'gol_pppk', 'id_gol_pppk')}
        ) " . self::TABLE_OPTIONS;
    }

    /**
     * FK `nip` snapshot → `pegawai.nip` (keranjang PEG).
     */
    private function nipFk(string $name): string
    {
        return $this->fk($name, 'nip', 'pegawai', 'nip');
    }

    private function fk(string $name, string $column, string $parent, string $parentColumn): string
    {
        return "CONSTRAINT `{$name}` FOREIGN KEY (`{$column}`) REFERENCES {$this->t($parent)} (`{$parentColumn}`) "
            . self::RESTRICT;
    }

    /**
     * Pembersihan setelah up() gagal: drop tabel yang dibuat pada run ini, urutan terbalik. Kegagalan drop tidak
     * menutupi error asli (yang dilempar ulang pemanggil) — sisa tabel lalu dibersihkan manual (dokumen Bagian 6.6).
     *
     * @param list<string> $created
     */
    private function dropCreated(array $created): void
    {
        foreach (array_reverse($created) as $table) {
            try {
                $this->db->query("DROP TABLE IF EXISTS {$this->t($table)}");
            } catch (Throwable) {
                // Lanjut ke tabel berikutnya; error asli tetap dilempar oleh up().
            }
        }
    }

    /**
     * Nama tabel ber-prefix (DBPrefix, mis. `t_` di database test) yang sudah di-escape untuk SQL mentah.
     */
    private function t(string $table): string
    {
        if (! $this->db instanceof BaseConnection) {
            throw new RuntimeException('DBV-012: koneksi database tidak mendukung prefix tabel.');
        }

        return $this->db->escapeIdentifiers($this->db->prefixTable($table));
    }

    private function exec(string $sql): void
    {
        // Dengan DBDebug = false query() mengembalikan false tanpa exception: jangan biarkan DDL gagal diam-diam.
        if ($this->db->query($sql) === false) {
            $error = $this->db->error();

            throw new RuntimeException('DBV-012: DDL snapshot pegawai gagal (' . $error['code'] . '): ' . $error['message']);
        }
    }
}
