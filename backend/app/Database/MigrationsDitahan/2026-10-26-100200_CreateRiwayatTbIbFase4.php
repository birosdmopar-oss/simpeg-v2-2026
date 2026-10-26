<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Migration;
use RuntimeException;
use Throwable;

/**
 * DBV-022 (3/9) — DRAF, DITAHAN (lihat 2026-10-26-100000_CreateMasterLayananFase4.php untuk aturan pemindahan).
 * Isi = usulan coder; belum direview DB Validator.
 *
 * Kelompok B — Tugas Belajar / Izin Belajar, dari dump struktur produksi D1 [K]:
 *   `riwayat_tb` D1:6544-6604, `riwayat_tb_perpan` D1:6662-6673, `riwayat_ib` D1:4898-4955.
 * Prasyarat: `pegawai` (DBV-012) dan `jenjang_pendidikan` (G-05, PK INT) sudah ada.
 *
 * Kolom = D1 apa adanya kecuali deviasi berikut (dokumen Bagian 2.5–2.7):
 *   - Aksi FK CASCADE/SET NULL → RESTRICT/RESTRICT, nama FK legacy dipertahankan (V1). Termasuk
 *     `riwayat_tb_perpan` → `riwayat_tb`: hapus perpanjangan dikerjakan service dalam transaksi yang sama (PRD §6.7.4).
 *   - `id_jenjang_pendidikan` TINYINT → INT = PK `jenjang_pendidikan` v2 (V5).
 *   - `file_sp`, `file_1`, `file_2`, `file_3` NOT NULL → NULL-able (DQ-20): berkas v2 di `document_attachment`, nilai
 *     impor dipertahankan. `riwayat_tb_perpan.file_sperpan` tetap NOT NULL [K].
 *   - CHECK domain (V2): `status` 0..3; `status_admin_upt`/`status_admin_deputi` (0,1,2) NULL lolos; `rated` 0..2.
 *   - `riwayat_ib.updated_at` DEFAULT CURRENT_TIMESTAMP tanpa ON UPDATE dipertahankan [K] (diisi aplikasi, G2).
 *   - Trigger `riwayat_tb_beUpd`/`riwayat_ib_beUpd` (show_notif) dan `riwayat_tb_perpan_af*` (ringkasan MAX tanggal
 *     perpanjangan ke induk) tidak dibawa (V3) → NotifikasiService / PerpanjanganTbService.
 *   - `ROW_FORMAT=DYNAMIC` (`riwayat_ib`) tidak ditulis (G2). Tanpa kolom `status_penuh` (D15).
 *
 * DDL ter-commit per statement: bila CREATE berikutnya gagal, up() men-drop tabel yang sempat dibuat PADA RUN ITU lalu
 * melempar ulang error. down() men-drop ketiga tabel urutan terbalik.
 */
class CreateRiwayatTbIbFase4 extends Migration
{
    public const TABLES = ['riwayat_tb', 'riwayat_tb_perpan', 'riwayat_ib'];

    private const RESTRICT = 'ON DELETE RESTRICT ON UPDATE RESTRICT';

    private const TABLE_OPTIONS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    private const STATUS_COMMENT = '0: Pengajuan, 1: Disetujui, 2: Ditolak, 3: Diproses';

    private const STATUS_ADMIN_COMMENT = '0: Waiting, 1: Approve, 2: Rejected';

    public function up(): void
    {
        $created = [];

        try {
            foreach (self::TABLES as $table) {
                $this->exec($this->createStatement($table), "CREATE TABLE {$table}");
                $created[] = $table;
            }
        } catch (Throwable $e) {
            foreach (array_reverse($created) as $table) {
                try {
                    $this->db->query("DROP TABLE IF EXISTS {$this->t($table)}");
                } catch (Throwable) {
                    // Error asli tetap dilempar; sisa tabel dibersihkan manual (dokumen DBV-022 Bagian 9).
                }
            }

            throw $e;
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::TABLES) as $table) {
            $this->exec("DROP TABLE IF EXISTS {$this->t($table)}", "DROP TABLE {$table}");
        }
    }

    private function createStatement(string $table): string
    {
        return match ($table) {
            'riwayat_tb' => "CREATE TABLE {$this->t('riwayat_tb')} (
                `id_riwayat_tb` INT NOT NULL AUTO_INCREMENT,
                `nip` VARCHAR(30) NOT NULL,
                `id_jenjang_pendidikan` INT NULL DEFAULT NULL,
                `nama` VARCHAR(100) NULL DEFAULT NULL,
                `gol_ruang` VARCHAR(45) NULL DEFAULT NULL,
                `pangkat` VARCHAR(100) NULL DEFAULT NULL,
                `jabatan` VARCHAR(100) NULL DEFAULT NULL,
                `unit` VARCHAR(150) NULL DEFAULT NULL,
                `satker` VARCHAR(150) NULL DEFAULT NULL,
                `pendidikan_dasar_singkat` VARCHAR(50) NULL DEFAULT NULL,
                `tgl_mulai` DATE NOT NULL,
                `tgl_akhir` DATE NOT NULL,
                `tgl_mulai_perpan` DATE NULL DEFAULT NULL,
                `tgl_akhir_perpan` DATE NULL DEFAULT NULL,
                `lama_tahun` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                `jenjang_pendidikan_singkat` VARCHAR(45) NOT NULL,
                `institusi_pendidikan` VARCHAR(255) NOT NULL,
                `jurusan_pendidikan` VARCHAR(255) NOT NULL,
                `dalam_negeri` INT NOT NULL,
                `lokasi_pendidikan` VARCHAR(100) NOT NULL,
                `lembaga_yang_mendanai` VARCHAR(255) NOT NULL,
                `up` VARCHAR(255) NULL DEFAULT NULL,
                `tembusan` VARCHAR(255) NULL DEFAULT NULL,
                `file_sp` VARCHAR(255) NULL DEFAULT NULL,
                `file_1` VARCHAR(225) NULL DEFAULT NULL COMMENT 'Surat Keterangan Kampus',
                `file_2` VARCHAR(225) NULL DEFAULT NULL COMMENT 'Surat Keterangan BAN PT',
                `file_3` VARCHAR(225) NULL DEFAULT NULL COMMENT 'Surat Pernyataan Bermaterai',
                `keterangan` MEDIUMTEXT NULL,
                `status_admin_upt` TINYINT NULL DEFAULT NULL COMMENT '" . self::STATUS_ADMIN_COMMENT . "',
                `rs_admin_upt` TINYTEXT NULL,
                `status_admin_deputi` TINYINT NULL DEFAULT NULL COMMENT '" . self::STATUS_ADMIN_COMMENT . "',
                `sp_admin_deputi` VARCHAR(255) NULL DEFAULT NULL,
                `rs_admin_deputi` TINYTEXT NULL,
                `ds_tb` VARCHAR(255) NULL DEFAULT NULL,
                `no_ds_tb` VARCHAR(100) NULL DEFAULT NULL,
                `tgl_ds_tb` DATE NULL DEFAULT NULL,
                `perihal_ds_tb` VARCHAR(255) NULL DEFAULT NULL,
                `no_sk` VARCHAR(50) NULL DEFAULT NULL,
                `tgl_sk` DATE NULL DEFAULT NULL,
                `file_surat_tb` VARCHAR(225) NULL DEFAULT NULL,
                `status` INT NOT NULL DEFAULT 0 COMMENT '" . self::STATUS_COMMENT . "',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                `updated_by` INT NULL DEFAULT 0,
                `approved_by` INT NULL DEFAULT NULL,
                `reason_note` MEDIUMTEXT NULL,
                `rated` TINYINT NOT NULL DEFAULT 2 COMMENT '0: Ignore, 1: Sudah, 2: Belum',
                `show_notif` INT NULL DEFAULT 0,
                `notif_date` DATETIME NULL DEFAULT NULL,
                `show_ua_upt` INT NULL DEFAULT 0,
                `show_ua_deputi` INT NULL DEFAULT 0,
                `show_ua_biro` INT NULL DEFAULT 0,
                `show_pl` INT NULL DEFAULT 0,
                PRIMARY KEY (`id_riwayat_tb`),
                KEY `fk_nip_rwytb_to_pegawai` (`nip`),
                KEY `fk_id_jenjang_pendidikan_twytb_to_jenjp` (`id_jenjang_pendidikan`),
                KEY `nip_status_rated_notif_date` (`nip`, `status`, `rated`, `notif_date`),
                CONSTRAINT `fk_id_jenjang_pendidikan_twytb_to_jenjp` FOREIGN KEY (`id_jenjang_pendidikan`) REFERENCES {$this->t('jenjang_pendidikan')} (`id_jenjang_pendidikan`) " . self::RESTRICT . ",
                CONSTRAINT `fk_nip_rwytb_to_pegawai` FOREIGN KEY (`nip`) REFERENCES {$this->t('pegawai')} (`nip`) " . self::RESTRICT . ',
                CONSTRAINT `chk_rwytb_status` CHECK (`status` IN (0, 1, 2, 3)),
                CONSTRAINT `chk_rwytb_status_admin_upt` CHECK (`status_admin_upt` IN (0, 1, 2)),
                CONSTRAINT `chk_rwytb_status_admin_deputi` CHECK (`status_admin_deputi` IN (0, 1, 2)),
                CONSTRAINT `chk_rwytb_rated` CHECK (`rated` IN (0, 1, 2))
            ) ' . self::TABLE_OPTIONS,
            'riwayat_tb_perpan' => "CREATE TABLE {$this->t('riwayat_tb_perpan')} (
                `id_riwayat_tb_perpan` INT NOT NULL AUTO_INCREMENT,
                `id_riwayat_tb` INT NOT NULL,
                `tgl_mulai_perpan` DATE NOT NULL,
                `tgl_akhir_perpan` DATE NOT NULL,
                `file_sperpan` VARCHAR(255) NOT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `created_by` INT NULL DEFAULT NULL,
                PRIMARY KEY (`id_riwayat_tb_perpan`),
                KEY `fk_id_riwayat_tb_tbperpan_to_tb` (`id_riwayat_tb`),
                CONSTRAINT `fk_id_riwayat_tb_tbperpan_to_tb` FOREIGN KEY (`id_riwayat_tb`) REFERENCES {$this->t('riwayat_tb')} (`id_riwayat_tb`) " . self::RESTRICT . '
            ) ' . self::TABLE_OPTIONS,
            'riwayat_ib' => "CREATE TABLE {$this->t('riwayat_ib')} (
                `id_riwayat_ib` INT NOT NULL AUTO_INCREMENT,
                `nip` VARCHAR(30) NOT NULL,
                `id_jenjang_pendidikan` INT NULL DEFAULT NULL,
                `nama` VARCHAR(100) NOT NULL,
                `gol_ruang` VARCHAR(45) NULL DEFAULT NULL,
                `pangkat` VARCHAR(100) NULL DEFAULT NULL,
                `jabatan` VARCHAR(100) NULL DEFAULT NULL,
                `unit` VARCHAR(150) NULL DEFAULT NULL,
                `satker` VARCHAR(150) NULL DEFAULT NULL,
                `pendidikan_dasar_singkat` VARCHAR(50) NULL DEFAULT NULL,
                `tgl_mulai` DATE NOT NULL,
                `tgl_akhir` DATE NOT NULL,
                `lama_tahun` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                `jenjang_pendidikan_singkat` VARCHAR(45) NOT NULL,
                `institusi_pendidikan` VARCHAR(255) NOT NULL,
                `jurusan_pendidikan` VARCHAR(255) NOT NULL,
                `dalam_negeri` INT NOT NULL,
                `lokasi_pendidikan` VARCHAR(100) NOT NULL,
                `lembaga_yang_mendanai` VARCHAR(255) NOT NULL,
                `up` VARCHAR(255) NULL DEFAULT NULL,
                `tembusan` VARCHAR(255) NULL DEFAULT NULL,
                `file_sp` VARCHAR(255) NULL DEFAULT NULL,
                `file_1` VARCHAR(225) NULL DEFAULT NULL COMMENT 'Surat Keterangan Kampus',
                `file_2` VARCHAR(225) NULL DEFAULT NULL COMMENT 'Surat Keterangan BAN PT',
                `keterangan` MEDIUMTEXT NULL,
                `status_admin_upt` TINYINT NULL DEFAULT NULL COMMENT '" . self::STATUS_ADMIN_COMMENT . "',
                `rs_admin_upt` TINYTEXT NULL,
                `status_admin_deputi` TINYINT NULL DEFAULT NULL COMMENT '" . self::STATUS_ADMIN_COMMENT . "',
                `sp_admin_deputi` VARCHAR(255) NULL DEFAULT NULL,
                `rs_admin_deputi` TINYTEXT NULL,
                `ds_ib` VARCHAR(255) NULL DEFAULT NULL,
                `no_ds_ib` VARCHAR(100) NULL DEFAULT NULL,
                `tgl_ds_ib` DATE NULL DEFAULT NULL,
                `perihal_ds_ib` VARCHAR(255) NULL DEFAULT NULL,
                `no_sk` VARCHAR(50) NULL DEFAULT NULL,
                `tgl_sk` DATE NULL DEFAULT NULL,
                `file_surat_ib` VARCHAR(225) NULL DEFAULT NULL,
                `status` INT NOT NULL DEFAULT 0 COMMENT '" . self::STATUS_COMMENT . "',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_by` INT NULL DEFAULT 0,
                `approved_by` INT NULL DEFAULT NULL,
                `reason_note` MEDIUMTEXT NULL,
                `rated` TINYINT NOT NULL DEFAULT 2 COMMENT '0: Ignore, 1: Sudah, 2: Belum',
                `show_notif` INT NULL DEFAULT 0,
                `notif_date` DATETIME NULL DEFAULT NULL,
                `show_ua_upt` INT NULL DEFAULT 0,
                `show_ua_deputi` INT NULL DEFAULT 0,
                `show_ua_biro` INT NULL DEFAULT 0,
                `show_pl` INT NULL DEFAULT 0,
                PRIMARY KEY (`id_riwayat_ib`),
                KEY `fk_nip_rwyib_to_pegawai` (`nip`),
                KEY `fk_id_jenjang_pendidikan_rwyib_to_jp` (`id_jenjang_pendidikan`),
                KEY `nip_status_rated_notif_date` (`nip`, `status`, `rated`, `notif_date`),
                CONSTRAINT `fk_id_jenjang_pendidikan_rwyib_to_jp` FOREIGN KEY (`id_jenjang_pendidikan`) REFERENCES {$this->t('jenjang_pendidikan')} (`id_jenjang_pendidikan`) " . self::RESTRICT . ",
                CONSTRAINT `fk_nip_rwyib_to_pegawai` FOREIGN KEY (`nip`) REFERENCES {$this->t('pegawai')} (`nip`) " . self::RESTRICT . ',
                CONSTRAINT `chk_rwyib_status` CHECK (`status` IN (0, 1, 2, 3)),
                CONSTRAINT `chk_rwyib_status_admin_upt` CHECK (`status_admin_upt` IN (0, 1, 2)),
                CONSTRAINT `chk_rwyib_status_admin_deputi` CHECK (`status_admin_deputi` IN (0, 1, 2)),
                CONSTRAINT `chk_rwyib_rated` CHECK (`rated` IN (0, 1, 2))
            ) ' . self::TABLE_OPTIONS,
            default => throw new RuntimeException("DBV-022: tabel {$table} tidak dikenal."),
        };
    }

    /**
     * Nama tabel ber-prefix (DBPrefix, mis. `t_` di database test) yang sudah di-escape untuk SQL mentah.
     */
    private function t(string $table): string
    {
        if (! $this->db instanceof BaseConnection) {
            throw new RuntimeException('DBV-022: koneksi database tidak mendukung prefix tabel.');
        }

        return $this->db->escapeIdentifiers($this->db->prefixTable($table));
    }

    private function exec(string $sql, string $what): void
    {
        // Dengan DBDebug = false query() mengembalikan false tanpa exception: jangan biarkan DDL gagal diam-diam.
        if ($this->db->query($sql) === false) {
            $error = $this->db->error();

            throw new RuntimeException("DBV-022: {$what} gagal (" . $error['code'] . '): ' . $error['message']);
        }
    }
}
