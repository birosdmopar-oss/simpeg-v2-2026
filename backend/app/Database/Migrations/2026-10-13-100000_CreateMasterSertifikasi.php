<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Migration;
use RuntimeException;
use Throwable;

/**
 * DBV-020 (1/3) — master sertifikasi untuk Riwayat Diklat jenis 5 (WS-1 MAKE-007, keputusan C1), skema SIMPEG legacy dari
 * dump struktur produksi `simpeg01_struktur_lengkap_20261001.sql` (D1) [K]:
 *   `rumpun_sertifikasi` D1:6693-6702, `lembaga_sertifikasi` D1:2137-2146.
 * Review DB Validator: backend/docs/db-review/DBV-020-skema-tambahan-fase3.md — JANGAN dijalankan di Dev/Production
 * sebelum disetujui.
 *
 * Nilai legacy yang dipertahankan: nama tabel/kolom, urutan kolom, tipe & panjang, PK INT tanpa AUTO_INCREMENT (id
 * diimpor apa adanya; legacy tidak punya form tambah master ini), default, nullability, kolom audit.
 *
 * Deviasi [V2]: COMMENT status '1: Aktif, 2: Tidak Aktif, 10: Dihapus' (legacy tanpa COMMENT; nilai legacy yang dibaca
 * aplikasi hanya `status`='1', `L_diklat.php:53-54`). Tanpa UNIQUE nama dan tanpa CHECK status (sama dengan D1;
 * keputusan DBV dokumen Bagian 6).
 *
 * FK `riwayat_diklat.id_rumpun_sertifikasi`/`id_lembaga_sertifikasi` → kedua master TIDAK ada di D1 dan diajukan
 * terpisah sebagai migration DITAHAN (app/Database/MigrationsDitahan/AddFkSertifikasiDiRiwayatDiklat.php).
 *
 * DDL ter-commit per statement: bila CREATE kedua gagal, up() men-drop tabel yang sempat dibuat PADA RUN ITU lalu
 * melempar ulang error, sehingga `php spark migrate` bisa langsung diulang (pola DBV-008/DBV-018).
 */
class CreateMasterSertifikasi extends Migration
{
    public const TABLES = ['rumpun_sertifikasi', 'lembaga_sertifikasi'];

    private const STATUS_COMMENT = '1: Aktif, 2: Tidak Aktif, 10: Dihapus';

    private const TABLE_OPTIONS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    public function up(): void
    {
        $created = [];

        try {
            foreach (self::TABLES as $table) {
                $this->exec($this->createStatement($table));
                $created[] = $table;
            }
        } catch (Throwable $e) {
            foreach (array_reverse($created) as $table) {
                try {
                    $this->db->query("DROP TABLE IF EXISTS {$this->t($table)}");
                } catch (Throwable) {
                    // Error asli tetap dilempar; sisa tabel dibersihkan manual (dokumen DBV-020 Bagian 7).
                }
            }

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
     * [K] D1: kedua tabel berstruktur sama (id INT PK tanpa AUTO_INCREMENT, nama VARCHAR(255), status, audit).
     */
    private function createStatement(string $table): string
    {
        return "CREATE TABLE {$this->t($table)} (
            `id_{$table}` INT NOT NULL,
            `{$table}` VARCHAR(255) NOT NULL,
            `status` TINYINT NOT NULL DEFAULT 1 COMMENT '" . self::STATUS_COMMENT . "',
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `created_by` INT NULL DEFAULT NULL,
            `updated_at` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            `updated_by` INT NULL DEFAULT NULL,
            PRIMARY KEY (`id_{$table}`)
        ) " . self::TABLE_OPTIONS;
    }

    /**
     * Nama tabel ber-prefix (DBPrefix, mis. `t_` di database test) yang sudah di-escape untuk SQL mentah.
     */
    private function t(string $table): string
    {
        if (! $this->db instanceof BaseConnection) {
            throw new RuntimeException('DBV-020: koneksi database tidak mendukung prefix tabel.');
        }

        return $this->db->escapeIdentifiers($this->db->prefixTable($table));
    }

    private function exec(string $sql): void
    {
        // Dengan DBDebug = false query() mengembalikan false tanpa exception: jangan biarkan DDL gagal diam-diam.
        if ($this->db->query($sql) === false) {
            $error = $this->db->error();

            throw new RuntimeException('DBV-020: DDL master sertifikasi gagal (' . $error['code'] . '): ' . $error['message']);
        }
    }
}
