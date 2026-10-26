<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Migration;
use RuntimeException;
use Throwable;

/**
 * DBV-022 (2/9) — DRAF, DITAHAN (lihat 2026-10-26-100000_CreateMasterLayananFase4.php untuk aturan pemindahan).
 * Isi = usulan coder; belum direview DB Validator.
 *
 * Kelompok A — Cuti, dari dump struktur produksi D1 [K]:
 *   `pegawai_sisa_cuti` D1:3576-3592, `riwayat_cuti` D1:4396-4463, `riwayat_cuti_notif_kt` D1:4699-4709.
 * Prasyarat: `pegawai` (DBV-012) dan `jenis_cuti` (migration 100000) sudah ada.
 *
 * Kolom = D1 apa adanya (nama, urutan, tipe, default, COMMENT) kecuali deviasi berikut (dokumen Bagian 2.1, 2.2, 2.4):
 *   - Aksi semua FK CASCADE/SET NULL → RESTRICT/RESTRICT, nama FK legacy dipertahankan termasuk `id_jenis_cuti`
 *     (nama constraint = nama kolom, DQ-22) (V1/K1).
 *   - `riwayat_cuti.nip_atasan_langsung`/`nip_yang_menyetujui` VARCHAR(50) → VARCHAR(30) (V4); FK ke `pegawai.nip`
 *     DITAHAN di AddFkNipLayananFase4.php sampai audit yatim Bagian 6.1 = 0 (DQ-3). Index tambahan
 *     `(nip_atasan_langsung, status)` dan `(nip_yang_menyetujui, status)` untuk antrean persetujuan (DQ-16); index
 *     kolom tunggal D1 tetap dibuat (DBV boleh menghapus yang redundan).
 *   - CHECK domain (V2): `status` 0..4 TANPA 10 (hapus cuti = fisik, D6), `status_al` (0,1,2,4), `status_pyb` 0..4,
 *     `status_regen` 0/1, `rated` 0..2, `skip_al`/`skip_pyb` 0/1; notif_kt `status` 0..2.
 *   - `pegawai_sisa_cuti`: KEY `nip_tahun` → UNIQUE (DQ-10; prasyarat audit duplikat Bagian 6.3 = 0, dinormalkan
 *     sebelum impor); CHECK `digunakan` 0/1 dan kelima kolom saldo >= 0 (NULL lolos CHECK); typo COMMENT diperbaiki.
 *     Batas atas D8 (12/6/6/12) TIDAK dijadikan CHECK (ditegakkan aplikasi, PRD §6.3.6).
 *   - Trigger `riwayat_cuti_beDel`/`beUpd` tidak dibawa (V3): arsip → `audit_logs`, `show_notif` → NotifikasiService.
 *   - Tabel `d_riwayat_cuti` dan cadangan bertanggal tidak dibuat.
 *
 * DDL ter-commit per statement: bila CREATE berikutnya gagal, up() men-drop tabel yang sempat dibuat PADA RUN ITU lalu
 * melempar ulang error. down() men-drop ketiga tabel urutan terbalik (anak dulu).
 */
class CreateRiwayatCutiFase4 extends Migration
{
    public const TABLES = ['pegawai_sisa_cuti', 'riwayat_cuti', 'riwayat_cuti_notif_kt'];

    private const RESTRICT = 'ON DELETE RESTRICT ON UPDATE RESTRICT';

    private const TABLE_OPTIONS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

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
            'pegawai_sisa_cuti' => "CREATE TABLE {$this->t('pegawai_sisa_cuti')} (
                `id_pegawai_sisa_cuti` INT NOT NULL AUTO_INCREMENT,
                `nip` VARCHAR(30) NOT NULL,
                `tahun` YEAR NOT NULL,
                `sisa_cuti` TINYINT NULL DEFAULT 0,
                `sisa_cuti_n1` TINYINT NULL DEFAULT 0,
                `sisa_cuti_n2` TINYINT NULL DEFAULT 0,
                `sisa_cuti_ditangguhkan` TINYINT NULL DEFAULT 0,
                `utang_cuti` TINYINT NULL DEFAULT 0,
                `digunakan` TINYINT NULL DEFAULT 0 COMMENT '0: Tidak Digunakan, 1: Digunakan',
                `created_at` DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                `updated_by` INT NULL DEFAULT 0,
                PRIMARY KEY (`id_pegawai_sisa_cuti`),
                UNIQUE KEY `nip_tahun` (`nip`, `tahun`),
                CONSTRAINT `fk_nip_psc_to_pegawai` FOREIGN KEY (`nip`) REFERENCES {$this->t('pegawai')} (`nip`) " . self::RESTRICT . ',
                CONSTRAINT `chk_psc_digunakan` CHECK (`digunakan` IN (0, 1)),
                CONSTRAINT `chk_psc_saldo_nonnegatif` CHECK (
                    `sisa_cuti` >= 0 AND `sisa_cuti_n1` >= 0 AND `sisa_cuti_n2` >= 0
                    AND `sisa_cuti_ditangguhkan` >= 0 AND `utang_cuti` >= 0)
            ) ' . self::TABLE_OPTIONS,
            'riwayat_cuti' => "CREATE TABLE {$this->t('riwayat_cuti')} (
                `id_riwayat_cuti` INT NOT NULL AUTO_INCREMENT,
                `nip` VARCHAR(30) NOT NULL,
                `nama` VARCHAR(70) NULL DEFAULT NULL,
                `id_sub_group_jabatan` INT NULL DEFAULT NULL,
                `pangkat` VARCHAR(70) NULL DEFAULT NULL,
                `gol_ruang` VARCHAR(45) NULL DEFAULT NULL,
                `jabatan` VARCHAR(200) NULL DEFAULT NULL,
                `satker` VARCHAR(100) NULL DEFAULT NULL,
                `mker_th` INT NULL DEFAULT 0,
                `mker_bl` INT NULL DEFAULT 0,
                `id_jenis_cuti` INT NOT NULL,
                `nama_jenis_cuti` VARCHAR(100) NOT NULL,
                `tgl_mulai` DATE NOT NULL,
                `tgl_akhir` DATE NOT NULL,
                `lama_cuti` INT NOT NULL,
                `satuan_lama_cuti` VARCHAR(50) NULL DEFAULT 'Hari',
                `no_telp_saat_cuti` VARCHAR(50) NULL DEFAULT NULL,
                `alamat_saat_cuti` TEXT NULL,
                `file_bukti` VARCHAR(250) NULL DEFAULT NULL,
                `sisa_cuti` INT NULL DEFAULT 0,
                `sisa_cuti_n1` INT NULL DEFAULT 0,
                `sisa_cuti_n2` INT NULL DEFAULT 0,
                `utang_cuti` INT NULL DEFAULT 0,
                `keterangan` TEXT NULL,
                `nip_atasan_langsung` VARCHAR(30) NULL DEFAULT NULL,
                `nama_atasan_langsung` VARCHAR(100) NULL DEFAULT NULL,
                `jabatan_atasan_langsung` VARCHAR(200) NULL DEFAULT NULL,
                `id_jabatan_al` INT NULL DEFAULT NULL,
                `status_al` TINYINT NULL DEFAULT 0 COMMENT '0: Pengajuan, 1: Disetujui, 2: Ditolak, 4: Ditangguhkan',
                `reason_note_al` MEDIUMTEXT NULL,
                `nip_yang_menyetujui` VARCHAR(30) NULL DEFAULT NULL,
                `nama_yang_menyetujui` VARCHAR(100) NULL DEFAULT NULL,
                `jabatan_yang_menyetujui` VARCHAR(200) NULL DEFAULT NULL,
                `id_jabatan_pyb` INT NULL DEFAULT NULL,
                `status_pyb` TINYINT NULL DEFAULT 0 COMMENT '0: Pengajuan, 1: Disetujui, 2: Ditolak, 3: Disetujui AL - Waiting PYB, 4: Ditangguhkan',
                `reason_note_pyb` MEDIUMTEXT NULL,
                `file_sc_unsign` VARCHAR(256) NULL DEFAULT NULL COMMENT 'Unsign PDF',
                `file_sc_signed_bef` VARCHAR(256) NULL DEFAULT NULL COMMENT 'To Be Signed PDF',
                `file_surat_cuti` VARCHAR(256) NULL DEFAULT NULL COMMENT 'Signed PDF',
                `keterangan_petugas_approval` TEXT NULL,
                `ketua_tim` TEXT NULL,
                `status` INT NOT NULL DEFAULT 0 COMMENT '0: Pengajuan, 1: Disetujui, 2: Ditolak, 3: Diproses / Disetujui AL, 4: Ditangguhkan',
                `status_regen` TINYINT NOT NULL DEFAULT 0 COMMENT '0: Normal State, 1: Process State',
                `created_at` DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                `updated_by` INT NULL DEFAULT 0,
                `approved_by` INT NULL DEFAULT NULL,
                `reason_note` TEXT NULL,
                `rated` TINYINT NOT NULL DEFAULT 2 COMMENT '0: Ignore, 1: Sudah, 2: Belum',
                `skip_al` TINYINT NOT NULL DEFAULT 0 COMMENT '0: No, 1: Yes',
                `skip_pyb` TINYINT NOT NULL DEFAULT 0 COMMENT '0: No, 1: Yes',
                `show_notif` INT NULL DEFAULT NULL,
                `notif_date` DATETIME NULL DEFAULT NULL,
                `show_atasan` INT NULL DEFAULT 0,
                `show_pejabat` INT NULL DEFAULT 0,
                `show_history_atasan` INT NULL DEFAULT 1,
                PRIMARY KEY (`id_riwayat_cuti`),
                KEY `id_jenis_cuti_idx` (`id_jenis_cuti`),
                KEY `nip_idx` (`nip`),
                KEY `jabatan_atasan_langsung` (`jabatan_atasan_langsung`),
                KEY `nip_atasan_langsung` (`nip_atasan_langsung`),
                KEY `nip_yang_menyetujui` (`nip_yang_menyetujui`),
                KEY `jabatan_yang_menyetujui` (`jabatan_yang_menyetujui`),
                KEY `nip_status_rated_notif_date` (`nip`, `status`, `rated`, `notif_date`),
                KEY `nip_atasan_langsung_status` (`nip_atasan_langsung`, `status`),
                KEY `nip_yang_menyetujui_status` (`nip_yang_menyetujui`, `status`),
                CONSTRAINT `fk_nip_rwycuti_to_pegawai` FOREIGN KEY (`nip`) REFERENCES {$this->t('pegawai')} (`nip`) " . self::RESTRICT . ",
                CONSTRAINT `id_jenis_cuti` FOREIGN KEY (`id_jenis_cuti`) REFERENCES {$this->t('jenis_cuti')} (`id_jenis_cuti`) " . self::RESTRICT . ',
                CONSTRAINT `chk_rwycuti_status` CHECK (`status` IN (0, 1, 2, 3, 4)),
                CONSTRAINT `chk_rwycuti_status_al` CHECK (`status_al` IN (0, 1, 2, 4)),
                CONSTRAINT `chk_rwycuti_status_pyb` CHECK (`status_pyb` IN (0, 1, 2, 3, 4)),
                CONSTRAINT `chk_rwycuti_status_regen` CHECK (`status_regen` IN (0, 1)),
                CONSTRAINT `chk_rwycuti_rated` CHECK (`rated` IN (0, 1, 2)),
                CONSTRAINT `chk_rwycuti_skip_al` CHECK (`skip_al` IN (0, 1)),
                CONSTRAINT `chk_rwycuti_skip_pyb` CHECK (`skip_pyb` IN (0, 1))
            ) ' . self::TABLE_OPTIONS,
            'riwayat_cuti_notif_kt' => "CREATE TABLE {$this->t('riwayat_cuti_notif_kt')} (
                `id_riwayat_cuti` INT NOT NULL,
                `nip_kt` VARCHAR(30) NOT NULL,
                `status` TINYINT NOT NULL DEFAULT 0 COMMENT '0: Belum Dibaca, 1: Sudah Dibaca, 2: Delete',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id_riwayat_cuti`, `nip_kt`),
                KEY `fk_nip_kt_rcnkt_2` (`nip_kt`),
                CONSTRAINT `fk_id_riwayat_cuti_rcnkt_1` FOREIGN KEY (`id_riwayat_cuti`) REFERENCES {$this->t('riwayat_cuti')} (`id_riwayat_cuti`) " . self::RESTRICT . ",
                CONSTRAINT `fk_nip_kt_rcnkt_2` FOREIGN KEY (`nip_kt`) REFERENCES {$this->t('pegawai')} (`nip`) " . self::RESTRICT . ',
                CONSTRAINT `chk_rcnkt_status` CHECK (`status` IN (0, 1, 2))
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
