<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Migration;
use RuntimeException;
use Throwable;

/**
 * DBV-013 — B-02 (kelompok 3): riwayat konfirmasi ketidakhadiran / konket (`absen_ijin`, B-13; dipakai Presensi Fase 5)
 * dengan skema SIMPEG legacy (Mapping Migrasi Prinsip #1).
 * Sumber: DDL produksi `simpeg_prod.sql:33-69` [K] persis — kolom, tipe, default, COMMENT, nama KEY (`nip`,
 * `date_start`, `show_notif`, `status`, `affect_tukin`) dan nama FK `fk_nip_abijin_to_pegawai`. Tabel arsip hapus
 * `d_konket` (:639-668, trigger `absen_ijin_beDel` :1476-1482) identik kolom demi kolom. Penulis legacy:
 * `libraries/hr/rwy/L_konket.php` (insert/update :244-580, `set_param` :1734-1777, proses :1800-2027; hapus keras
 * :890-980). Review DB Validator:
 * backend/docs/db-review/B-01-B-02-pegawai-riwayat-schema.md (Bagian 2.3) — JANGAN dijalankan di Dev/Production sebelum disetujui
 * DBV-013.
 *
 * Nilai legacy yang dipertahankan: status CHAR(2) 'W' Waiting / 'V' Approved / 'X' Rejected / '10' Deleted (bukan pola
 * riwayat 0/1/2/10); kolom ganda `date_created`/`last_updated` di samping `created_at`/`updated_at`; kolom workflow
 * INT/MEDIUMTEXT persis DDL; berkas bukti di `file_bukti`..`file_bukti_5` (bukan document_attachment); `kategori` =
 * `jenis_konket.old_id` dan `id_parent` = induk dinas gabungan, keduanya tanpa FK (legacy juga tanpa FK).
 *
 * Deviasi dari legacy (dicatat untuk DBV):
 *   - FK `fk_nip_abijin_to_pegawai` ON DELETE RESTRICT ON UPDATE RESTRICT (legacy CASCADE/CASCADE; K1).
 *   - 3 CHECK [V2]: status IN ('W','V','X','10'), affect_tukin IN (1,2), jenis_dinas NULL/0/1.
 *   - Trigger `absen_ijin_beDel` → `d_konket` tidak ditiru (jejak hapus lewat `audit_logs`).
 *   - AUTO_INCREMENT awal (326331) dan collation per kolom tidak ditulis (kolom mewarisi utf8mb4_unicode_ci tabel).
 *
 * DDL MySQL/MariaDB ter-commit per statement dan migration yang gagal tidak tercatat di tabel `migrations`: bila CREATE
 * gagal, up() men-drop tabel yang sempat dibuat PADA RUN ITU lalu melempar ulang error.
 */
class CreateAbsenIjin extends Migration
{
    private const TABLE_OPTIONS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    private const TABLES = ['absen_ijin'];

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
     * @return array<string, string> tabel => SQL
     */
    private function createStatements(): array
    {
        $sql = [];

        // [K] simpeg_prod.sql:33-69 persis. FK memakai KEY legacy `nip` (tanpa KEY baru bernama FK, konvensi 2.0.6).
        $sql['absen_ijin'] = "CREATE TABLE {$this->t('absen_ijin')} (
            `id` INT NOT NULL AUTO_INCREMENT,
            `nip` VARCHAR(30) NOT NULL,
            `date_start` DATETIME NOT NULL,
            `date_end` DATETIME NOT NULL,
            `kategori` INT NOT NULL,
            `jenis_konket` VARCHAR(255) NULL DEFAULT NULL,
            `jenis_dinas` TINYINT(1) NULL DEFAULT NULL COMMENT '0: Dinas Pribadi, 1: Dinas Gabungan',
            `affect_tukin` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1: Yes, 2: No',
            `id_parent` INT NULL DEFAULT NULL,
            `alasan` MEDIUMTEXT NOT NULL,
            `file_bukti` VARCHAR(255) NULL DEFAULT NULL,
            `file_bukti_2` VARCHAR(255) NULL DEFAULT NULL,
            `file_bukti_3` VARCHAR(255) NULL DEFAULT NULL,
            `file_bukti_4` VARCHAR(255) NULL DEFAULT NULL,
            `file_bukti_5` VARCHAR(255) NULL DEFAULT NULL,
            `date_created` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `last_updated` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            `status` CHAR(2) NOT NULL DEFAULT 'W' COMMENT 'W: Waiting, V: Approved, X: Rejected, 10: Deleted',
            `created_at` DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `updated_by` INT NULL DEFAULT NULL,
            `approved_by` INT NULL DEFAULT NULL,
            `reason_note` MEDIUMTEXT NULL,
            `show_notif` INT NULL DEFAULT 0,
            `notif_date` DATETIME NULL DEFAULT NULL,
            `show_ua_upt` INT NULL DEFAULT 0,
            `show_ua_deputi` INT NULL DEFAULT 0,
            `show_ua_biro` INT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            KEY `nip` (`nip`),
            KEY `date_start` (`date_start`, `date_end`),
            KEY `show_notif` (`show_notif`, `notif_date`),
            KEY `status` (`status`),
            KEY `affect_tukin` (`affect_tukin`),
            CONSTRAINT `fk_nip_abijin_to_pegawai` FOREIGN KEY (`nip`)
                REFERENCES {$this->t('pegawai')} (`nip`) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT `chk_absen_ijin_status` CHECK (`status` IN ('W', 'V', 'X', '10')),
            CONSTRAINT `chk_absen_ijin_affect_tukin` CHECK (`affect_tukin` IN (1, 2)),
            CONSTRAINT `chk_absen_ijin_jenis_dinas` CHECK (`jenis_dinas` IS NULL OR `jenis_dinas` IN (0, 1))
        ) " . self::TABLE_OPTIONS;

        return $sql;
    }

    /**
     * Pembersihan setelah up() gagal: drop tabel yang dibuat pada run ini. Kegagalan drop tidak menutupi error asli.
     *
     * @param list<string> $created
     */
    private function dropCreated(array $created): void
    {
        foreach (array_reverse($created) as $table) {
            try {
                $this->db->query("DROP TABLE IF EXISTS {$this->t($table)}");
            } catch (Throwable) {
                // Error asli tetap dilempar oleh up().
            }
        }
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

            throw new RuntimeException('DBV-013: DDL B-02 absen_ijin gagal (' . $error['code'] . '): ' . $error['message']);
        }
    }
}
