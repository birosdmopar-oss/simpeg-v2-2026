<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Migration;
use RuntimeException;
use Throwable;

/**
 * DBV-022 (8/9) — DRAF, DITAHAN (lihat 2026-10-26-100000_CreateMasterLayananFase4.php untuk aturan pemindahan).
 * Isi = usulan coder; belum direview DB Validator.
 *
 * Kelompok G — integrasi yang dimajukan ke Fase 4, dari dump struktur produksi D1 [K]:
 *   `esign_hist` D1:891-907 (append-only, bukti legal TTE), `firebase_token` D1:1156-1163 (tabel kosong, tanpa PK).
 * Prasyarat: `pegawai` (DBV-012).
 *
 * Deviasi (dokumen Bagian 2.19, 2.20):
 *   - `esign_hist`: FK `nip` SET NULL/CASCADE → RESTRICT/RESTRICT, kolom tetap NULL-able (V1; pegawai dengan riwayat
 *     TTE tidak boleh terhapus, konsisten B-05). `status` VARCHAR(256) dipertahankan [K] + CHECK ('1','2') (DQ-7).
 *     Tambah `status_code` VARCHAR(10) NULL [V2] (kode gateway BSrE, kontrak QASMTASK-090) setelah `status`.
 *     `ip_address` VARCHAR(20) → VARCHAR(45) [V2] (IPv6). Index tambahan `(modul, id_rw)`. `nik` tetap [K] — PII,
 *     tidak masuk respons API/log (E15). `id_rw` INT tanpa FK (polimorfik antar modul).
 *   - `firebase_token`: collation 0900_ai_ci → unicode_ci (G2); tambah PK `id_firebase_token` BIGINT AI [V2] (DQ-26);
 *     FK `nip` → `pegawai` RESTRICT aktif di CREATE (tabel kosong, tanpa risiko yatim); `created_at`/`updated_at`
 *     TIMESTAMP → DATETIME (G2); `auth_token` dibuang [V2] (DQ-25: tidak dipakai kontrak push v2). Tanpa UNIQUE
 *     `(nip, fcm_token)` (TEXT); deduplikasi di aplikasi Fase 6.
 *
 * DDL ter-commit per statement: bila CREATE kedua gagal, up() men-drop tabel yang sempat dibuat PADA RUN ITU lalu
 * melempar ulang error. down() men-drop kedua tabel.
 */
class CreateEsignFirebaseFase4 extends Migration
{
    public const TABLES = ['esign_hist', 'firebase_token'];

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
            'esign_hist' => "CREATE TABLE {$this->t('esign_hist')} (
                `id_esign_hist` BIGINT NOT NULL AUTO_INCREMENT,
                `nip` VARCHAR(30) NULL DEFAULT NULL,
                `nik` VARCHAR(20) NOT NULL COMMENT 'PII: tidak masuk respons API, log, maupun audit',
                `nama_lengkap` VARCHAR(256) NOT NULL,
                `modul` VARCHAR(50) NOT NULL DEFAULT 'Cuti Online',
                `id_rw` INT NULL DEFAULT NULL,
                `file_pdf_to_sign` VARCHAR(256) NOT NULL,
                `status` VARCHAR(256) NOT NULL DEFAULT '1' COMMENT '1: Signing Berhasil, 2: Signing Gagal',
                `status_code` VARCHAR(10) NULL DEFAULT NULL COMMENT 'kode respons gateway e-sign',
                `ip_address` VARCHAR(45) NULL DEFAULT NULL,
                `user_agent` TINYTEXT NULL,
                `keterangan` TINYTEXT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id_esign_hist`),
                KEY `fk_esign_hist_ibfk01` (`nip`),
                KEY `modul_id_rw` (`modul`, `id_rw`),
                CONSTRAINT `fk_esign_hist_ibfk01` FOREIGN KEY (`nip`) REFERENCES {$this->t('pegawai')} (`nip`) " . self::RESTRICT . ",
                CONSTRAINT `chk_esign_hist_status` CHECK (`status` IN ('1', '2'))
            ) " . self::TABLE_OPTIONS,
            'firebase_token' => "CREATE TABLE {$this->t('firebase_token')} (
                `id_firebase_token` BIGINT NOT NULL AUTO_INCREMENT,
                `nip` VARCHAR(30) NOT NULL,
                `fcm_token` TEXT NULL,
                `created_at` DATETIME NULL DEFAULT NULL,
                `updated_at` DATETIME NULL DEFAULT NULL,
                PRIMARY KEY (`id_firebase_token`),
                KEY `nip` (`nip`),
                CONSTRAINT `fk_nip_firebase_token_to_pegawai` FOREIGN KEY (`nip`) REFERENCES {$this->t('pegawai')} (`nip`) " . self::RESTRICT . '
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
