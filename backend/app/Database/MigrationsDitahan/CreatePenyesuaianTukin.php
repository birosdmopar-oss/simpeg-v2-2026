<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Migration;
use RuntimeException;

/**
 * DBV-024 (Fase 5B, F5B-14) — DRAF, DITAHAN di app/Database/MigrationsDitahan (tidak dijalankan `spark migrate`) sampai
 * disetujui DB Validator. Setelah disetujui: dipindah ke app/Database/Migrations dengan timestamp lebih baru dari semua
 * migration 5A (`2026-10-27-100100`) dan DDL tidak berubah, bersama registry NIP & test skema (PRD 5B §10 M3).
 * Dokumen: backend/docs/db-review/DBV-024-penyesuaian-tukin-schema.md.
 *
 * Tabel `penyesuaian_tukin` [V2] — pengganti daftar NIP & periode penyesuaian Tukin yang di-hard-code legacy
 * (`L_presensi.php:11755-11806`; per pegawai `:6526-6560`). Satu baris = satu NIP × rentang bulan Tukin × persen
 * penyesuaian (negatif = pengurangan) × alasan; dikelola role 1 lewat CRUD (MAKE-045) dengan audit `BaseAuditableModel`.
 * Efeknya hanya keterangan/kolom di laporan & rekap; `total_potongan` hasil `KalkulasiTukin` tidak berubah (PRD 5B §7.3.10).
 *
 *   - FK `fk_nip_ptk_to_pegawai` → `pegawai.nip` RESTRICT/RESTRICT (ADR-008); koreksi NIP oleh `NipCascadeService`.
 *   - `bulan_mulai`/`bulan_akhir` = tanggal 1 bulan Tukin (DBV-Q1); CHECK tanggal 1 & urutan.
 *   - `status` 1/2/10 (aktif / tidak aktif / dihapus) pola master; hapus = status 10.
 *   - Tumpang tindih rentang aktif per NIP ditolak aplikasi (tidak bisa dijaga UNIQUE).
 *
 * Tanpa impor (sumber legacy berupa kode); riwayat periode ≥ 2024-04 diisi admin saat cutover (F5B-21). Stempel waktu UTC
 * diisi aplikasi. up() men-DROP tabel bila gagal; down() men-DROP tabel.
 */
class CreatePenyesuaianTukin extends Migration
{
    private const TABLE_OPTIONS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    public const TABLE = 'penyesuaian_tukin';

    public function up(): void
    {
        try {
            $this->exec($this->statement());
        } catch (\Throwable $e) {
            $this->db->query('DROP TABLE IF EXISTS ' . $this->t(self::TABLE));

            throw $e;
        }
    }

    public function down(): void
    {
        $this->exec('DROP TABLE IF EXISTS ' . $this->t(self::TABLE));
    }

    private function statement(): string
    {
        return "CREATE TABLE {$this->t(self::TABLE)} (
            `id_penyesuaian_tukin` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `nip` VARCHAR(30) NOT NULL COMMENT 'Pegawai yang Tukin-nya disesuaikan',
            `bulan_mulai` DATE NOT NULL COMMENT 'Tanggal 1 bulan Tukin pertama yang disesuaikan (bulan laporan, bukan bulan kalender data)',
            `bulan_akhir` DATE NOT NULL COMMENT 'Tanggal 1 bulan Tukin terakhir yang disesuaikan (inklusif)',
            `persen` DECIMAL(5,2) NOT NULL COMMENT 'Persen penyesuaian; negatif = pengurangan (mis. -20.00); keterangan saja, tidak mengubah total potongan',
            `alasan` VARCHAR(255) NOT NULL COMMENT 'Alasan penyesuaian yang dicetak di keterangan laporan/rekap',
            `status` TINYINT NOT NULL DEFAULT 1 COMMENT '1: Aktif, 2: Tidak Aktif, 10: Dihapus',
            `created_at` DATETIME NULL DEFAULT NULL COMMENT 'UTC, diisi aplikasi',
            `updated_at` DATETIME NULL DEFAULT NULL COMMENT 'UTC, diisi aplikasi',
            `created_by` INT NULL DEFAULT NULL COMMENT 'id_pengguna pembuat',
            `updated_by` INT NULL DEFAULT NULL COMMENT 'id_pengguna yang terakhir mengubah',
            PRIMARY KEY (`id_penyesuaian_tukin`),
            KEY `idx_ptk_nip_status_bulan` (`nip`, `status`, `bulan_mulai`),
            KEY `idx_ptk_status_bulan` (`status`, `bulan_mulai`, `bulan_akhir`),
            CONSTRAINT `fk_nip_ptk_to_pegawai` FOREIGN KEY (`nip`) REFERENCES {$this->t('pegawai')} (`nip`) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT `chk_penyesuaian_tukin_bulan` CHECK (DAYOFMONTH(`bulan_mulai`) = 1 AND DAYOFMONTH(`bulan_akhir`) = 1 AND `bulan_akhir` >= `bulan_mulai`),
            CONSTRAINT `chk_penyesuaian_tukin_persen` CHECK (`persen` <> 0 AND `persen` BETWEEN -100 AND 100),
            CONSTRAINT `chk_penyesuaian_tukin_status` CHECK (`status` IN (1, 2, 10))
        ) " . self::TABLE_OPTIONS . " COMMENT='Penyesuaian Tukin per NIP per rentang bulan (pengganti daftar hard-code legacy); efek keterangan laporan/rekap saja'";
    }

    private function t(string $table): string
    {
        if (! $this->db instanceof BaseConnection) {
            throw new RuntimeException('DBV-024: koneksi database tidak mendukung prefix tabel.');
        }

        return $this->db->escapeIdentifiers($this->db->prefixTable($table));
    }

    private function exec(string $sql): void
    {
        if ($this->db->query($sql) === false) {
            $error = $this->db->error();

            throw new RuntimeException('DBV-024: DDL gagal (' . $error['code'] . '): ' . $error['message']);
        }
    }
}
