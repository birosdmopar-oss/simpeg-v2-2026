<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Migration;
use RuntimeException;

/**
 * DBV-006 — G-09 `web_config` (key-value konfigurasi sistem) dengan skema SIMPEG legacy (Mapping Migrasi Prinsip #1).
 * Sumber: DDL struktur produksi `simpeg01_struktur_lengkap_20261001.sql:7622-7631` [K]. Review DB Validator:
 * backend/docs/db-review/G-09-web-config-schema.md — JANGAN dijalankan di Dev/Production sebelum disetujui.
 *
 * Nilai legacy yang dipertahankan [K]: nama tabel/kolom (`id_web_config`, `config_name`, `config_value`, `remark`,
 * `updated_at`, `updated_by`), PK INT signed AUTO_INCREMENT, `config_name` VARCHAR(255) NOT NULL, `config_value` TEXT
 * NULL, `remark` VARCHAR(255) NULL, `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE
 * CURRENT_TIMESTAMP, `updated_by` INT NULL, tanpa `created_at`/`created_by`/`status`.
 *
 * Deviasi dari legacy (dicatat untuk DBV, G-09 Bagian 3):
 *   - index `config_name` menjadi UNIQUE (legacy KEY non-unik; keunikan hanya dijaga aplikasi `Lm_web.php:176-195`).
 *     Nama index legacy dipertahankan. Impor wajib lolos audit duplikat dulu (G-09 Bagian 5).
 *   - collation utf8mb4_unicode_ci (legacy utf8mb4_0900_ai_ci) — standar proyek DBV-010.
 *   - COMMENT `updated_by` (id_pengguna, pola tabel master v2).
 * Tipe nilai per key TIDAK disimpan di tabel: katalog key bertipe ada di kode (`Config\WebConfig`), G-09 Bagian 4 #3.
 *
 * Nilai AUTO_INCREMENT awal tidak ditulis (impor memakai ID legacy apa adanya). Migration ini tidak men-seed data:
 * key yang belum punya baris memakai nilai bawaan katalog.
 */
class CreateWebConfig extends Migration
{
    private const TABLE_OPTIONS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    public function up(): void
    {
        $this->exec("CREATE TABLE {$this->t('web_config')} (
            `id_web_config` INT NOT NULL AUTO_INCREMENT,
            `config_name` VARCHAR(255) NOT NULL,
            `config_value` TEXT NULL,
            `remark` VARCHAR(255) NULL DEFAULT NULL,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `updated_by` INT NULL DEFAULT NULL COMMENT 'id_pengguna yang terakhir mengubah',
            PRIMARY KEY (`id_web_config`),
            UNIQUE KEY `config_name` (`config_name`)
        ) " . self::TABLE_OPTIONS);
    }

    public function down(): void
    {
        $this->exec("DROP TABLE IF EXISTS {$this->t('web_config')}");
    }

    /**
     * Nama tabel ber-prefix (DBPrefix, mis. `t_` di database test) yang sudah di-escape untuk SQL mentah.
     */
    private function t(string $table): string
    {
        if (! $this->db instanceof BaseConnection) {
            throw new RuntimeException('DBV-006: koneksi database tidak mendukung prefix tabel.');
        }

        return $this->db->escapeIdentifiers($this->db->prefixTable($table));
    }

    private function exec(string $sql): void
    {
        // Dengan DBDebug = false query() mengembalikan false tanpa exception: jangan biarkan DDL gagal diam-diam.
        if ($this->db->query($sql) === false) {
            $error = $this->db->error();

            throw new RuntimeException('DBV-006: DDL web_config gagal (' . $error['code'] . '): ' . $error['message']);
        }
    }
}
