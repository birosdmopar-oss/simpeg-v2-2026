<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Migration;
use RuntimeException;
use Throwable;

/**
 * DBV-013 — B-02 (kelompok 3): riwayat hukuman disiplin (B-14) dan angka kredit (B-15: riwayat AK, AK dari SIASN,
 * konversi AK PermenPAN-RB 1/2023 beserta daftar tunda) dengan skema SIMPEG legacy (Mapping Migrasi Prinsip #1).
 * Sumber per tabel:
 *   - `riwayat_hukdis` = kode CI3 [I]: `libraries/hr/rwy/L_hukdis.php:463-498` (`$param = $postData`), validasi
 *     :370-456, hapus keras :285-300; sinkron SIASN `controllers/hr/services/Siasn.php:3030-3048, 3055-3080`. Tanpa
 *     proses approval (hanya role 1; controller menulis status '1', `Hukdis.php:72-73, 129-130`).
 *   - `riwayat_ak` = kode CI3 [I]: `libraries/hr/rwy/L_ak.php:875-925` (`sp`), `:1178-1210` (`sp_process`),
 *     `:1231-1290` (`process_ak`), `:558` (`file_pak`); Current AK pop-up dasbor `libraries/hr/L_user.php:4878-4975`
 *     (`insert_pak`, jenis_ak 1: `tgl_ak_terakhir`, `tgl_pengajuan_ak`, `file_pengantar`, `file_dupak`).
 *   - `riwayat_ak_siasn` = kode CI3 [I]: `controllers/hr/services/siasn_sync/Rw_ak.php:60-200`, `L_ak.php:931-1045`
 *     (pairing jabatan). PK `id_riwayat_ak_siasn` dari kode (`simpegdev_local` sintetis memakai `id`).
 *   - `konv_ak` = kode CI3 [I]: `libraries/hr/L_user.php:4987-5150` (`insert_konvak`), :5151-5250 (daftar, hapus,
 *     ubah admin); stub [L] `simpeg_prod_duplikat` hanya `id`/`nip`/`status`.
 *   - `ignore_konv_ak` = DDL produksi `simpeg_prod.sql:1345-1350` [K] persis (kecuali panjang `nip`).
 *   Nama FK dari ERD simpeg01 [K-erd]. Review DB Validator: backend/docs/db-review/B-01-B-02-pegawai-riwayat-schema.md
 *   (Bagian 2.3) — JANGAN dijalankan di Dev/Production sebelum disetujui DBV-013.
 *
 * Deviasi dari legacy (dicatat untuk DBV):
 *   - Semua FK ON DELETE RESTRICT ON UPDATE RESTRICT (K1; legacy `nip` [K] CASCADE/CASCADE).
 *   - `nip` NOT NULL di tabel [I] (stub [L] DEFAULT NULL); `ignore_konv_ak.nip` VARCHAR(50) [K] → VARCHAR(30).
 *   - Status TINYINT NOT NULL + CHECK 0/1/2/10 (stub [L] VARCHAR(5) DEFAULT '1'; `riwayat_hukdis` + 3 "Diproses" =
 *     opsi form admin legacy `views/hr/employee/rwy/hukdis/form.php:45`); DEFAULT 0 untuk tabel pengajuan
 *     (`riwayat_hukdis`, `riwayat_ak`, `konv_ak`), DEFAULT 1 untuk `riwayat_ak_siasn` (satu-satunya penulis, sinkron
 *     SIASN, selalu menulis '1').
 *   - `created_at` [I] di semua tabel [I] (pola absen_ijin/d_lkh); `konv_ak.created_at` memang dibaca legacy
 *     (ORDER BY, L_user.php:5156).
 *   - FK G-02 (`fk_id_jabatan_rak_to_jab`, `fk_id_jabatan_rw_ak_siasn_02`, `fk_id_jabatan_konvak_to_jabatan`,
 *     `fk_id_unit_konvak_to_unit`, `fk_id_satker_konvak_to_satker`) BELUM dipasang: KEY bernama FK dibuat di sini,
 *     constraint ditambahkan migration `AddFkG02Riwayat` (ditahan sampai DBV-008 merge).
 *
 * Kolom audit diisi aplikasi (waktu UTC, id_pengguna aktor); default DB hanya cadangan. Nilai AUTO_INCREMENT awal tidak
 * ditulis. DDL MySQL/MariaDB ter-commit per statement dan migration yang gagal tidak tercatat di tabel `migrations`: bila
 * salah satu CREATE gagal, up() men-drop tabel yang sempat dibuat PADA RUN ITU (urutan terbalik) lalu melempar ulang
 * error. Tabel yang sudah ada sebelum run tidak disentuh.
 */
class CreateRiwayatHukdisAk extends Migration
{
    private const STATUS_COMMENT = '0: Menunggu, 1: Disetujui, 2: Ditolak, 10: Dihapus';

    /**
     * `riwayat_hukdis`: + 3 "Diproses" yang ditulis form admin legacy (views/hr/employee/rwy/hukdis/form.php:45).
     */
    private const STATUS_HUKDIS_COMMENT = '0: Menunggu, 1: Disetujui, 2: Ditolak, 3: Diproses, 10: Dihapus';

    private const TABLE_OPTIONS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    /**
     * Urutan untuk down() (anak → induk). Kelima tabel tidak saling merujuk; semuanya anak `pegawai`.
     */
    private const TABLES = ['ignore_konv_ak', 'konv_ak', 'riwayat_ak_siasn', 'riwayat_ak', 'riwayat_hukdis'];

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
        foreach (self::TABLES as $table) {
            $this->exec("DROP TABLE IF EXISTS {$this->t($table)}");
        }
    }

    /**
     * CREATE TABLE per tabel, urut induk → anak.
     *
     * @return array<string, string> tabel => SQL
     */
    private function createStatements(): array
    {
        $status  = "COMMENT '" . self::STATUS_COMMENT . "'";
        $audit   = $this->auditColumnsSql();
        $pegawai = $this->t('pegawai');
        $pangkat = $this->t('pangkat');
        $sql     = [];

        // [I] L_hukdis.php:463-498. tingkat/jenis/gol/pangkat = salinan teks master saat simpan (L_hukdis.php:477-492).
        // masa_hukuman teks bebas (textarea; SIASN menulis "X Tahun Y Bulan", Siasn.php:2940-2946). Tanpa blok workflow.
        $sql['riwayat_hukdis'] = "CREATE TABLE {$this->t('riwayat_hukdis')} (
            `id_riwayat_hukdis` INT NOT NULL AUTO_INCREMENT,
            `nip` VARCHAR(30) NOT NULL,
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
            `tmtsk` DATE NOT NULL,
            `masa_hukuman` TEXT NULL COMMENT 'teks bebas legacy, mis. 1 Tahun 6 Bulan',
            `akhir_hukdis` DATE NULL DEFAULT NULL,
            `aturan_dilanggar` TEXT NULL,
            `alasan_hukuman` TEXT NULL,
            `keterangan` TEXT NULL,
            `status` TINYINT NOT NULL DEFAULT 0 COMMENT '" . self::STATUS_HUKDIS_COMMENT . "',
            `approved_by` INT NULL DEFAULT NULL COMMENT 'id_pengguna (legacy: hanya diisi sinkron SIASN)',
            {$audit},
            PRIMARY KEY (`id_riwayat_hukdis`),
            KEY `fk_nip_rwyhukdis_to_pegawai` (`nip`),
            KEY `fk_id_tingkat_hukdis_rwyhukdis_to_tkhukdis` (`id_tingkat_hukdis`),
            KEY `fk_id_jenis_hukdis_rwyhukdis_to_jenhukdis` (`id_jenis_hukdis`),
            KEY `fk_id_pangkat_rwyhukdis_to_pangkat` (`id_pangkat`),
            CONSTRAINT `fk_nip_rwyhukdis_to_pegawai` FOREIGN KEY (`nip`)
                REFERENCES {$pegawai} (`nip`) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT `fk_id_tingkat_hukdis_rwyhukdis_to_tkhukdis` FOREIGN KEY (`id_tingkat_hukdis`)
                REFERENCES {$this->t('tingkat_hukdis')} (`id_tingkat_hukdis`) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT `fk_id_jenis_hukdis_rwyhukdis_to_jenhukdis` FOREIGN KEY (`id_jenis_hukdis`)
                REFERENCES {$this->t('jenis_hukdis')} (`id_jenis_hukdis`) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT `fk_id_pangkat_rwyhukdis_to_pangkat` FOREIGN KEY (`id_pangkat`)
                REFERENCES {$pangkat} (`id_pangkat`) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT `chk_riwayat_hukdis_status` CHECK (`status` IN (0, 1, 2, 3, 10))
        ) " . self::TABLE_OPTIONS;

        // [I] L_ak.php:875-925 (jenis_ak 2) + L_user.php:4900-4955 (jenis_ak 1, Current AK). Workflow legacy AK memakai
        // appr_at/appr_by (L_ak.php:1178-1210) dan created_by saat tambah (L_ak.php:917), bukan approved_by.
        $sql['riwayat_ak'] = "CREATE TABLE {$this->t('riwayat_ak')} (
            `id_riwayat_ak` INT NOT NULL AUTO_INCREMENT,
            `id_jabatan` INT NULL DEFAULT NULL,
            `id_pangkat` TINYINT NULL DEFAULT NULL,
            `nip` VARCHAR(30) NOT NULL,
            `jenis_ak` TINYINT NOT NULL DEFAULT 2 COMMENT '1: Current AK (pop-up dasbor), 2: Riwayat AK',
            `nama` VARCHAR(255) NULL DEFAULT NULL,
            `no_induk_jf` VARCHAR(50) NULL DEFAULT NULL,
            `no_hpak` VARCHAR(100) NULL DEFAULT NULL,
            `tgl_pak` DATE NULL DEFAULT NULL,
            `periode_awal` DATE NULL DEFAULT NULL,
            `periode_akhir` DATE NULL DEFAULT NULL,
            `ak_kum_kp` DECIMAL(8,3) NULL DEFAULT NULL,
            `ak_kum_next_jen` DECIMAL(8,3) NULL DEFAULT NULL,
            `ak_terakhir` DECIMAL(8,3) NULL DEFAULT NULL,
            `pengajuan_ak` DECIMAL(8,3) NULL DEFAULT NULL,
            `nilai_ak` DECIMAL(8,3) NULL DEFAULT NULL,
            `flag_instansi_ym` TINYINT NULL DEFAULT NULL COMMENT '0: Kemenpar, 1: Instansi Lain',
            `instansi_ym` VARCHAR(255) NULL DEFAULT NULL,
            `nama_pym` VARCHAR(255) NULL DEFAULT NULL,
            `jab_pym` VARCHAR(255) NULL DEFAULT NULL,
            `jabatan` VARCHAR(255) NULL DEFAULT NULL,
            `tmt_jab` DATE NULL DEFAULT NULL,
            `mker_th_jab` TINYINT NULL DEFAULT NULL,
            `mker_bl_jab` TINYINT NULL DEFAULT NULL,
            `pangkat` VARCHAR(50) NULL DEFAULT NULL,
            `gol_ruang` VARCHAR(10) NULL DEFAULT NULL,
            `tmt_pang` DATE NULL DEFAULT NULL,
            `mker_th_pang` TINYINT NULL DEFAULT NULL,
            `mker_bl_pang` TINYINT NULL DEFAULT NULL,
            `tgl_ak_terakhir` DATE NULL DEFAULT NULL,
            `tgl_pengajuan_ak` DATE NULL DEFAULT NULL,
            `keterangan` TEXT NULL,
            `file_pak` VARCHAR(255) NULL DEFAULT NULL,
            `file_dupak` VARCHAR(255) NULL DEFAULT NULL,
            `file_pengantar` VARCHAR(255) NULL DEFAULT NULL,
            `status` TINYINT NOT NULL DEFAULT 0 {$status},
            `reason_note` TEXT NULL,
            `appr_at` DATETIME NULL DEFAULT NULL,
            `appr_by` INT NULL DEFAULT NULL COMMENT 'id_pengguna yang menyetujui/menolak',
            `show_notif` TINYINT NOT NULL DEFAULT 0 COMMENT 'penanda notifikasi pegawai (nilai legacy), diisi aplikasi',
            `notif_date` DATETIME NULL DEFAULT NULL,
            `show_ua_upt` TINYINT NULL DEFAULT NULL COMMENT '1: diproses admin UPT (satker)',
            `show_ua_deputi` TINYINT NULL DEFAULT NULL COMMENT '1: diproses admin deputi (unit)',
            `show_ua_biro` TINYINT NULL DEFAULT NULL COMMENT '1: diproses admin biro',
            `created_by` INT NULL DEFAULT NULL COMMENT 'id_pengguna pembuat',
            {$audit},
            PRIMARY KEY (`id_riwayat_ak`),
            KEY `fk_nip_rak_to_peg` (`nip`),
            KEY `fk_id_pangkat_rak_to_pangkat` (`id_pangkat`),
            KEY `fk_id_jabatan_rak_to_jab` (`id_jabatan`),
            CONSTRAINT `fk_nip_rak_to_peg` FOREIGN KEY (`nip`)
                REFERENCES {$pegawai} (`nip`) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT `fk_id_pangkat_rak_to_pangkat` FOREIGN KEY (`id_pangkat`)
                REFERENCES {$pangkat} (`id_pangkat`) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT `chk_riwayat_ak_status` CHECK (`status` IN (0, 1, 2, 10))
        ) " . self::TABLE_OPTIONS;

        // [I] Rw_ak.php:138-160 (salinan riwayat AK SIASN). is_* hanya dibandingkan dengan '1' (list_siasn.php:60-63).
        $sql['riwayat_ak_siasn'] = "CREATE TABLE {$this->t('riwayat_ak_siasn')} (
            `id_riwayat_ak_siasn` INT NOT NULL AUTO_INCREMENT,
            `id_rw_siasn` VARCHAR(100) NULL DEFAULT NULL,
            `id_pns` VARCHAR(100) NULL DEFAULT NULL,
            `id_jabatan` INT NULL DEFAULT NULL COMMENT 'jabatan SIMPEG hasil pairing (L_ak.php:969-1045)',
            `id_pangkat` TINYINT NULL DEFAULT NULL,
            `nip` VARCHAR(30) NOT NULL,
            `no_sk` VARCHAR(100) NULL DEFAULT NULL,
            `tgl_sk` DATE NULL DEFAULT NULL,
            `bulan_mulai_penilaian` TINYINT NULL DEFAULT NULL,
            `tahun_mulai_penilaian` SMALLINT NULL DEFAULT NULL,
            `bulan_selesai_penilaian` TINYINT NULL DEFAULT NULL,
            `tahun_selesai_penilaian` SMALLINT NULL DEFAULT NULL,
            `kredit_utama_baru` DECIMAL(8,3) NULL DEFAULT NULL,
            `kredit_penunjang_baru` DECIMAL(8,3) NULL DEFAULT NULL,
            `kredit_baru_total` DECIMAL(8,3) NULL DEFAULT NULL,
            `id_rw_jabatan_siasn` VARCHAR(100) NULL DEFAULT NULL,
            `nama_jabatan` VARCHAR(255) NULL DEFAULT NULL,
            `is_angka_kredit_pertama` TINYINT(1) NULL DEFAULT NULL,
            `is_integrasi` TINYINT(1) NULL DEFAULT NULL,
            `is_konversi` TINYINT(1) NULL DEFAULT NULL,
            `f_pak_siasn` VARCHAR(255) NULL DEFAULT NULL,
            `sumber` VARCHAR(100) NULL DEFAULT NULL,
            `is_pemenuhan_kp` TINYINT(1) NULL DEFAULT NULL,
            `keterangan` TEXT NULL,
            `status` TINYINT NOT NULL DEFAULT 1 {$status},
            {$audit},
            PRIMARY KEY (`id_riwayat_ak_siasn`),
            KEY `fk_nip_rw_ak_siasn_01` (`nip`),
            KEY `fk_id_jabatan_rw_ak_siasn_02` (`id_jabatan`),
            KEY `fk_id_pangkat_rw_ak_siasn_03` (`id_pangkat`),
            CONSTRAINT `fk_nip_rw_ak_siasn_01` FOREIGN KEY (`nip`)
                REFERENCES {$pegawai} (`nip`) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT `fk_id_pangkat_rw_ak_siasn_03` FOREIGN KEY (`id_pangkat`)
                REFERENCES {$pangkat} (`id_pangkat`) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT `chk_riwayat_ak_siasn_status` CHECK (`status` IN (0, 1, 2, 10))
        ) " . self::TABLE_OPTIONS;

        // [I] L_user.php:4987-5150 (pop-up konversi AK role 2, batas unggah 31-03-2023; controllers/User.php:425-460).
        // Kode mengakses satu baris per nip (get/update/delete by nip); UNIQUE nip ditunda sampai audit data.
        $sql['konv_ak'] = "CREATE TABLE {$this->t('konv_ak')} (
            `id` INT NOT NULL AUTO_INCREMENT,
            `nip` VARCHAR(30) NOT NULL,
            `id_pangkat` TINYINT NULL DEFAULT NULL,
            `id_jabatan` INT NULL DEFAULT NULL,
            `id_unit` INT NULL DEFAULT NULL,
            `id_satker` INT NULL DEFAULT NULL,
            `pangkat` VARCHAR(50) NULL DEFAULT NULL,
            `gol_ruang` VARCHAR(10) NULL DEFAULT NULL,
            `jabatan` VARCHAR(255) NULL DEFAULT NULL,
            `unit` VARCHAR(255) NULL DEFAULT NULL,
            `satker` VARCHAR(255) NULL DEFAULT NULL,
            `no_pak` VARCHAR(100) NULL DEFAULT NULL,
            `tgl_pak` DATE NULL DEFAULT NULL,
            `ak_terakhir` DECIMAL(8,3) NULL DEFAULT NULL,
            `file_dupak` VARCHAR(255) NULL DEFAULT NULL,
            `status` TINYINT NOT NULL DEFAULT 0 {$status},
            `reason_note` TEXT NULL,
            `created_by` INT NULL DEFAULT NULL COMMENT 'id_pengguna pembuat',
            {$audit},
            PRIMARY KEY (`id`),
            KEY `fk_nip_konvak_to_pegawai` (`nip`),
            KEY `fk_id_pangkat_konvak_to_pangkat` (`id_pangkat`),
            KEY `fk_id_jabatan_konvak_to_jabatan` (`id_jabatan`),
            KEY `fk_id_unit_konvak_to_unit` (`id_unit`),
            KEY `fk_id_satker_konvak_to_satker` (`id_satker`),
            CONSTRAINT `fk_nip_konvak_to_pegawai` FOREIGN KEY (`nip`)
                REFERENCES {$pegawai} (`nip`) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT `fk_id_pangkat_konvak_to_pangkat` FOREIGN KEY (`id_pangkat`)
                REFERENCES {$pangkat} (`id_pangkat`) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT `chk_konv_ak_status` CHECK (`status` IN (0, 1, 2, 10))
        ) " . self::TABLE_OPTIONS;

        // [K] simpeg_prod.sql:1345-1350 persis kecuali nip VARCHAR(50) → VARCHAR(30). PK (nip, ignore_date) menjadi index
        // FK (tanpa KEY terpisah, seperti legacy). Cabang `cak_chk_ignore` legacy (L_user.php:5024-5029, 5114-5117) tidak
        // menyimpan baris → isi hanya data historis; dihapus bersama konv_ak (L_user.php:5176).
        $sql['ignore_konv_ak'] = "CREATE TABLE {$this->t('ignore_konv_ak')} (
            `nip` VARCHAR(30) NOT NULL,
            `ignore_date` DATE NOT NULL,
            PRIMARY KEY (`nip`, `ignore_date`),
            CONSTRAINT `fk_nip_ignorekonvak_to_pegawai` FOREIGN KEY (`nip`)
                REFERENCES {$pegawai} (`nip`) ON DELETE RESTRICT ON UPDATE RESTRICT
        ) " . self::TABLE_OPTIONS;

        return $sql;
    }

    /**
     * Pembersihan setelah up() gagal: drop tabel yang dibuat pada run ini, urutan terbalik. Kegagalan drop tidak
     * menutupi error asli (yang dilempar ulang pemanggil) — sisa tabel lalu dibersihkan manual (dokumen DBV Bagian 6.6).
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
     * Kolom audit tabel riwayat [I] (pola absen_ijin [K] / d_lkh [K-m]): created_at, updated_at ON UPDATE, updated_by =
     * id_pengguna aktor tanpa FK (preseden DBV-001/010).
     */
    private function auditColumnsSql(): string
    {
        return '`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, '
            . '`updated_at` DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, '
            . "`updated_by` INT NULL DEFAULT NULL COMMENT 'id_pengguna yang terakhir mengubah'";
    }

    /**
     * Nama tabel ber-prefix (DBPrefix, mis. `t_` di database test) yang sudah di-escape untuk SQL mentah.
     */
    private function t(string $table): string
    {
        if (! $this->db instanceof BaseConnection) {
            throw new RuntimeException('DBV-013: koneksi database tidak mendukung prefix tabel.');
        }

        return $this->db->escapeIdentifiers($this->db->prefixTable($table));
    }

    private function exec(string $sql): void
    {
        // Dengan DBDebug = false query() mengembalikan false tanpa exception: jangan biarkan DDL gagal diam-diam.
        if ($this->db->query($sql) === false) {
            $error = $this->db->error();

            throw new RuntimeException('DBV-013: DDL B-02 hukdis/AK gagal (' . $error['code'] . '): ' . $error['message']);
        }
    }
}
