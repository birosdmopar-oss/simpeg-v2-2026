<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Migration;
use RuntimeException;
use Throwable;

/**
 * DBV-022 (4/9) — DRAF, DITAHAN (lihat 2026-10-26-100000_CreateMasterLayananFase4.php untuk aturan pemindahan).
 * Isi = usulan coder; belum direview DB Validator.
 *
 * Kelompok C — Peninjauan Masa Kerja, dari dump struktur produksi D1 [K]:
 *   `riwayat_pmk` D1:6202-6227, `riwayat_pmk_req` D1:6232-6259. Prasyarat: `pegawai` (DBV-012).
 *
 * Kolom = D1 apa adanya kecuali deviasi berikut (dokumen Bagian 2.8, 2.9):
 *   - Aksi FK CASCADE/CASCADE → RESTRICT/RESTRICT, nama FK legacy dipertahankan (V1).
 *   - CHECK domain (V2): `riwayat_pmk.status` (1,2) = status sinkron SIASN (bukan approval), `siasn_flag` 0/1;
 *     `riwayat_pmk_req.status` 0..3.
 *   - `riwayat_pmk_req.status` DEFAULT 1 dipertahankan [K] (DQ-18: aplikasi selalu mengisi eksplisit);
 *     `reason_note` TINYTEXT dipertahankan [K], aplikasi membatasi 255 byte (Q-23); berkas tetap di kolom (Q-21).
 *   - `ROW_FORMAT=DYNAMIC` (`riwayat_pmk_req`) tidak ditulis (G2).
 *   - `riwayat_pmk` hanya diisi impor/SIASN; Fase 4 tidak menulis baris baru (D16/P-2).
 *
 * DDL ter-commit per statement: bila CREATE kedua gagal, up() men-drop tabel yang sempat dibuat PADA RUN ITU lalu
 * melempar ulang error. down() men-drop kedua tabel.
 */
class CreateRiwayatPmkFase4 extends Migration
{
    public const TABLES = ['riwayat_pmk', 'riwayat_pmk_req'];

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
            'riwayat_pmk' => "CREATE TABLE {$this->t('riwayat_pmk')} (
                `id_riwayat_pmk` BIGINT NOT NULL AUTO_INCREMENT,
                `nip` VARCHAR(30) NOT NULL,
                `id_pns_siasn` VARCHAR(100) NULL DEFAULT NULL,
                `no_sk` VARCHAR(100) NOT NULL,
                `tgl_sk` DATE NOT NULL,
                `no_bkn` VARCHAR(100) NULL DEFAULT NULL,
                `tgl_bkn` DATE NULL DEFAULT NULL,
                `instansi` VARCHAR(255) NULL DEFAULT NULL,
                `tgl_awal` DATE NULL DEFAULT NULL,
                `tgl_akhir` DATE NULL DEFAULT NULL,
                `mker_th` TINYINT NULL DEFAULT 0,
                `mker_bl` TINYINT NULL DEFAULT 0,
                `dinilai` VARCHAR(50) NULL DEFAULT NULL,
                `id_rw_siasn` VARCHAR(100) NULL DEFAULT NULL,
                `siasn_flag` TINYINT NOT NULL DEFAULT 0,
                `siasn_error` TEXT NULL,
                `siasn_lu` DATETIME NULL DEFAULT NULL,
                `status` TINYINT NOT NULL DEFAULT 1 COMMENT '1: Ok, 2: Fail',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                `updated_by` INT NULL DEFAULT NULL,
                PRIMARY KEY (`id_riwayat_pmk`),
                KEY `fk_rw_pmk_01` (`nip`),
                CONSTRAINT `fk_rw_pmk_01` FOREIGN KEY (`nip`) REFERENCES {$this->t('pegawai')} (`nip`) " . self::RESTRICT . ',
                CONSTRAINT `chk_rwpmk_status` CHECK (`status` IN (1, 2)),
                CONSTRAINT `chk_rwpmk_siasn_flag` CHECK (`siasn_flag` IN (0, 1))
            ) ' . self::TABLE_OPTIONS,
            'riwayat_pmk_req' => "CREATE TABLE {$this->t('riwayat_pmk_req')} (
                `id_riwayat_pmk_req` BIGINT NOT NULL AUTO_INCREMENT,
                `nip` VARCHAR(30) NOT NULL,
                `jenis_pmk` VARCHAR(20) NOT NULL DEFAULT 'Swasta' COMMENT 'Swasta, Negeri',
                `instansi` VARCHAR(255) NOT NULL,
                `tgl_awal` DATE NOT NULL,
                `tgl_akhir` DATE NOT NULL,
                `mker_th` TINYINT NULL DEFAULT 0,
                `mker_bl` TINYINT NULL DEFAULT 0,
                `file_kontrak` VARCHAR(255) NULL DEFAULT NULL,
                `file_paklaring` VARCHAR(255) NULL DEFAULT NULL,
                `file_surat_phk` VARCHAR(255) NULL DEFAULT NULL,
                `file_slip_gaji` VARCHAR(255) NULL DEFAULT NULL,
                `id_rw_siasn` VARCHAR(100) NULL DEFAULT NULL,
                `status` TINYINT NOT NULL DEFAULT 1 COMMENT '0: Pengajuan, 1: Selesai, 2: Ditolak, 3: Proses',
                `reason_note` TINYTEXT NULL,
                `show_notif` TINYINT NULL DEFAULT 0,
                `notif_date` DATETIME NULL DEFAULT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `created_by` INT NULL DEFAULT NULL,
                `updated_at` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                `updated_by` INT NULL DEFAULT NULL,
                `approved_at` DATETIME NULL DEFAULT NULL,
                `approved_by` INT NULL DEFAULT NULL,
                PRIMARY KEY (`id_riwayat_pmk_req`),
                KEY `fk_rriwayat_pmk_req_01` (`nip`),
                CONSTRAINT `fk_rriwayat_pmk_req_01` FOREIGN KEY (`nip`) REFERENCES {$this->t('pegawai')} (`nip`) " . self::RESTRICT . ',
                CONSTRAINT `chk_rwpmkreq_status` CHECK (`status` IN (0, 1, 2, 3))
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
