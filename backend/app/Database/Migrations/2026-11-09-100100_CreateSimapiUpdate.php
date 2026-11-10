<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Migration;
use RuntimeException;
use Throwable;

/**
 * DBV-031 (Fase 6 Jalur A, ISSUE-056/057, Q6-41) — antrean perubahan API SIMAPI lama `simapi/a_pegawai/pegawai_update`
 * (MAKE-076). Struktur ikut D1; dokumen: backend/docs/db-review/DBV-031-simapi-d1.md.
 *   - `simapi_update` [K D1]: nip, last_action ('INSERT'|'UPDATE'|'DELETE' — nilai yang dipakai trigger D1, CHECK [V2]),
 *     last_update (UTC [V2]; dikirim WIB oleh MAKE-076), nama_field/nilai_lama/nilai_baru MEDIUMTEXT dipisah pipe;
 *     KEY nip [V2]. `nip` tanpa FK (log; di luar registry NIP seperti `simapi_ch.nip`).
 *   - `simapi_update_hist` [K D1]: penanda baris sudah dikirim per klien. Legacy kunci `username` → v2 `id_klien` (pola
 *     `simapi_auth_ch` DBV-026). FK RESTRICT (DBV-026 P13; legacy CASCADE).
 * up() gagal → tabel yang sempat dibuat di-DROP; down() men-DROP keduanya (anak dulu).
 */
class CreateSimapiUpdate extends Migration
{
    public const TABEL = ['simapi_update', 'simapi_update_hist'];

    private const TABLE_OPTIONS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    private const RESTRICT = 'ON DELETE RESTRICT ON UPDATE RESTRICT';

    public function up(): void
    {
        try {
            foreach (self::TABEL as $tabel) {
                $this->exec($this->ddl($tabel), "CREATE TABLE {$tabel}");
            }
        } catch (Throwable $e) {
            try {
                $this->down();
            } catch (Throwable) {
                // Galat asli lebih penting.
            }

            throw $e;
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::TABEL) as $tabel) {
            $this->exec('DROP TABLE IF EXISTS ' . $this->t($tabel), "DROP TABLE {$tabel}");
        }
    }

    private function ddl(string $tabel): string
    {
        $r = self::RESTRICT;

        return match ($tabel) {
            'simapi_update' => "CREATE TABLE {$this->t('simapi_update')} (
                    `id_simapi_update` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `nip` VARCHAR(30) NOT NULL COMMENT 'NIP pemilik data; tanpa FK (log) [K D1]',
                    `last_action` VARCHAR(30) NOT NULL COMMENT 'INSERT / UPDATE / DELETE [K D1]',
                    `last_update` DATETIME NOT NULL COMMENT 'UTC saat perubahan [V2]; dikirim WIB',
                    `nama_field` MEDIUMTEXT NULL COMMENT 'Nama kolom dipisah pipe [K D1]',
                    `nilai_lama` MEDIUMTEXT NULL COMMENT 'Nilai lama dipisah pipe [K D1]',
                    `nilai_baru` MEDIUMTEXT NULL COMMENT 'Nilai baru dipisah pipe [K D1]',
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'UTC, diisi aplikasi',
                    PRIMARY KEY (`id_simapi_update`),
                    KEY `idx_simapi_update_nip` (`nip`),
                    CONSTRAINT `chk_simapi_update_action` CHECK (`last_action` IN ('INSERT', 'UPDATE', 'DELETE'))
                ) " . self::TABLE_OPTIONS . " COMMENT='Antrean perubahan pegawai untuk API SIMAPI lama (pegawai_update)'",
            'simapi_update_hist' => "CREATE TABLE {$this->t('simapi_update_hist')} (
                    `id_klien` INT UNSIGNED NOT NULL,
                    `id_simapi_update` INT UNSIGNED NOT NULL,
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'UTC saat dikirim',
                    PRIMARY KEY (`id_klien`, `id_simapi_update`),
                    KEY `idx_simapi_update_hist_upd` (`id_simapi_update`),
                    CONSTRAINT `fk_simapi_update_hist_klien` FOREIGN KEY (`id_klien`) REFERENCES {$this->t('klien_layanan')} (`id_klien`) {$r},
                    CONSTRAINT `fk_simapi_update_hist_upd` FOREIGN KEY (`id_simapi_update`) REFERENCES {$this->t('simapi_update')} (`id_simapi_update`) {$r}
                ) " . self::TABLE_OPTIONS . " COMMENT='Penanda baris simapi_update sudah dikirim ke klien (ack-on-read)'",
            default => throw new RuntimeException("DBV-031: tabel {$tabel} tidak dikenal."),
        };
    }

    private function t(string $table): string
    {
        if (! $this->db instanceof BaseConnection) {
            throw new RuntimeException('DBV-031: koneksi database tidak mendukung prefix tabel.');
        }

        return $this->db->escapeIdentifiers($this->db->prefixTable($table));
    }

    private function exec(string $sql, string $label): void
    {
        if ($this->db->query($sql) === false) {
            $error = $this->db->error();

            throw new RuntimeException("DBV-031: {$label} gagal (" . $error['code'] . '): ' . $error['message']);
        }
    }
}
