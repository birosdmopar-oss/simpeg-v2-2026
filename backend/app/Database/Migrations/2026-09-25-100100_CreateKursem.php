<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Migration;
use RuntimeException;
use Throwable;

/**
 * DBV-003 — master kursus/seminar: `bidang_kursem` dan `instansi_kursem` dengan skema SIMPEG legacy (Mapping Migrasi
 * Prinsip #1). Sumber: DDL produksi `simpeg_prod.sql:245-252` (bidang_kursem) dan `:1435-1442` (instansi_kursem) [K];
 * ERD simpeg01 tidak mencatat relasi untuk kedua tabel. Review DB Validator:
 * backend/docs/db-review/G-07-G-08-kantor-hari-libur-kursem-schema.md — JANGAN dijalankan di Dev/Production sebelum
 * disetujui.
 *
 * Nilai legacy yang dipertahankan: nama tabel/kolom, PK TINYINT signed AUTO_INCREMENT (maksimal 127 ID; soft delete
 * tidak membebaskan ID), nama VARCHAR(255), `status` TINYINT DEFAULT 1, `created_at`/`updated_at`, tanpa kolom `*_by`
 * (aktor tetap tercatat di audit_logs), collation utf8mb4_unicode_ci.
 *
 * Deviasi dari legacy (dicatat untuk DBV):
 *   - `order` TINYINT NOT NULL DEFAULT 1 (kolom v2, G-01 Keputusan #5); tipe [I] mengikuti pola [K] tabel ber-PK
 *     TINYINT (`agama`, `diklat`). Legacy mengurutkan menurut nama; impor mengisi `order` urut nama.
 *   - `UNIQUE uq_bidang_kursem_nama` / `uq_instansi_kursem_nama`, berlaku juga untuk baris status 2/10.
 *   - Status v2 1 Aktif / 2 Tidak Aktif / 10 Dihapus (COMMENT disesuaikan).
 *
 * Nilai AUTO_INCREMENT awal tidak ditulis (impor memakai ID legacy apa adanya). Migration ini tidak men-seed data.
 * Bila salah satu CREATE gagal, up() men-drop tabel yang sempat dibuat PADA RUN ITU lalu melempar ulang error.
 */
class CreateKursem extends Migration
{
    private const STATUS_COMMENT = '1: Aktif, 2: Tidak Aktif, 10: Dihapus';

    private const TABLE_OPTIONS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    /**
     * Urutan pembuatan; down() memakai urutan terbalik. Kedua tabel tidak saling merujuk.
     */
    private const TABLES = ['bidang_kursem', 'instansi_kursem'];

    public function up(): void
    {
        $created = [];

        try {
            foreach (self::TABLES as $table) {
                $this->exec($this->createStatement($table));
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
     * Struktur kedua tabel identik: PK `id_{tabel}`, kolom nama bernama sama dengan tabel.
     */
    private function createStatement(string $table): string
    {
        return "CREATE TABLE {$this->t($table)} (
            `id_{$table}` TINYINT NOT NULL AUTO_INCREMENT,
            `{$table}` VARCHAR(255) NOT NULL,
            `order` TINYINT NOT NULL DEFAULT 1,
            `status` TINYINT NOT NULL DEFAULT 1 COMMENT '" . self::STATUS_COMMENT . "',
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id_{$table}`),
            UNIQUE KEY `uq_{$table}_nama` (`{$table}`)
        ) " . self::TABLE_OPTIONS;
    }

    /**
     * Pembersihan setelah up() gagal: drop tabel yang dibuat pada run ini, urutan terbalik. Kegagalan drop tidak
     * menutupi error asli (yang dilempar ulang pemanggil).
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
            throw new RuntimeException('DBV-003: koneksi database tidak mendukung prefix tabel.');
        }

        return $this->db->escapeIdentifiers($this->db->prefixTable($table));
    }

    private function exec(string $sql): void
    {
        // Dengan DBDebug = false query() mengembalikan false tanpa exception: jangan biarkan DDL gagal diam-diam.
        if ($this->db->query($sql) === false) {
            $error = $this->db->error();

            throw new RuntimeException('DBV-003: DDL kursem gagal (' . $error['code'] . '): ' . $error['message']);
        }
    }
}
