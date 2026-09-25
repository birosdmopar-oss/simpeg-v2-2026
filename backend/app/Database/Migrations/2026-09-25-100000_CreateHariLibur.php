<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Migration;
use RuntimeException;
use Throwable;

/**
 * DBV-003 — G-08 hari libur: `jenis_libur` dan `hari_libur` dengan skema SIMPEG legacy (Mapping Migrasi Prinsip #1).
 * Review DB Validator: backend/docs/db-review/G-07-G-08-kantor-hari-libur-kursem-schema.md — JANGAN dijalankan di
 * Dev/Production sebelum disetujui.
 *
 * Sumber:
 *   - `hari_libur`: DDL produksi `simpeg_prod.sql:1308-1321` [K] + nama FK dari ERD simpeg01.
 *   - `jenis_libur`: DDL legacy tidak tersedia (dump terpotong di `jabatan`). Nama kolom dari kode legacy [K]
 *     (`L_presensi.php:20348-20349, 20362`); tipe PK TINYINT signed diturunkan dari kolom FK
 *     `hari_libur.id_jenis_libur tinyint` (:1310, tipe & signedness wajib sama); panjang nama, tipe `order`/`status`,
 *     dan kolom audit adalah nilai [I] (G-doc Bagian 3.1).
 *
 * Deviasi dari legacy (dicatat untuk DBV):
 *   - FK `fk_id_jenis_libur_harilibur_to_jenislibur` ON DELETE/UPDATE RESTRICT (legacy SET NULL/CASCADE): aplikasi
 *     tidak pernah hard delete (status 10 = Dihapus).
 *   - `hari_libur.status` TINYINT 1/2/10 (kolom v2, G-01 Keputusan #5); hanya status 1 yang dihitung sebagai libur.
 *   - `UNIQUE uq_hari_libur_tgl_mulai (tgl_mulai)` dan `CHECK chk_hari_libur_rentang (tgl_akhir >= tgl_mulai)`, CHECK
 *     pertama di repo (legacy hanya datepicker klien). UNIQUE berlaku juga untuk baris status 2/10 (Paket A).
 *   - Sengaja TANPA UNIQUE `nama_libur`: nama libur berulang tiap tahun (deviasi eksplisit dari aturan UNIQUE nama).
 *   - `hari_libur.id_jenis_libur` tetap NULL seperti legacy [K], wajib diisi di aplikasi.
 *   - `UNIQUE uq_jenis_libur_nama (jenis_libur)`, status 10 & COMMENT v2, COMMENT kolom audit.
 *
 * Kolom audit diisi aplikasi (waktu UTC, id_pengguna aktor); default DB hanya cadangan. Nilai AUTO_INCREMENT awal
 * tidak ditulis (impor memakai ID legacy apa adanya, counter naik otomatis). Migration ini tidak men-seed data.
 *
 * DDL MySQL/MariaDB ter-commit per statement dan migration yang gagal tidak tercatat di tabel `migrations`: bila salah
 * satu CREATE gagal, up() men-drop tabel yang sempat dibuat PADA RUN ITU (urutan terbalik) lalu melempar ulang error,
 * sehingga `php spark migrate` bisa langsung diulang. Tabel yang sudah ada sebelum run tidak disentuh.
 */
class CreateHariLibur extends Migration
{
    private const STATUS_COMMENT = '1: Aktif, 2: Tidak Aktif, 10: Dihapus';

    private const TABLE_OPTIONS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    /**
     * Urutan anak → induk untuk down().
     */
    private const TABLES = ['hari_libur', 'jenis_libur'];

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
     * CREATE TABLE per tabel, urut induk → anak.
     *
     * @return array<string, string> tabel => SQL
     */
    private function createStatements(): array
    {
        $status    = "COMMENT '" . self::STATUS_COMMENT . "'";
        $updatedBy = "`updated_by` INT NULL DEFAULT NULL COMMENT 'id_pengguna yang terakhir mengubah'";
        $sql       = [];

        // Seluruh tipe [I] kecuali PK (turunan FK hari_libur). Kolom audit meniru jenis_pegawai (DBV-001).
        $sql['jenis_libur'] = "CREATE TABLE {$this->t('jenis_libur')} (
            `id_jenis_libur` TINYINT NOT NULL AUTO_INCREMENT,
            `jenis_libur` VARCHAR(255) NOT NULL,
            `order` TINYINT NOT NULL DEFAULT 1,
            `status` TINYINT NOT NULL DEFAULT 1 {$status},
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            {$updatedBy},
            PRIMARY KEY (`id_jenis_libur`),
            UNIQUE KEY `uq_jenis_libur_nama` (`jenis_libur`)
        ) " . self::TABLE_OPTIONS;

        // Tanpa created_by [K]: legacy mengisi updated_by saat tambah maupun ubah (L_presensi.php:20561).
        $sql['hari_libur'] = "CREATE TABLE {$this->t('hari_libur')} (
            `id_libur` INT NOT NULL AUTO_INCREMENT,
            `id_jenis_libur` TINYINT NULL DEFAULT NULL,
            `tgl_mulai` DATE NOT NULL,
            `tgl_akhir` DATE NOT NULL,
            `nama_libur` VARCHAR(100) NOT NULL,
            `keterangan` TEXT NULL,
            `status` TINYINT NOT NULL DEFAULT 1 {$status},
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            {$updatedBy},
            PRIMARY KEY (`id_libur`),
            UNIQUE KEY `uq_hari_libur_tgl_mulai` (`tgl_mulai`),
            KEY `fk_id_jenis_libur_harilibur_to_jenislibur` (`id_jenis_libur`),
            CONSTRAINT `fk_id_jenis_libur_harilibur_to_jenislibur` FOREIGN KEY (`id_jenis_libur`)
                REFERENCES {$this->t('jenis_libur')} (`id_jenis_libur`) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT `chk_hari_libur_rentang` CHECK (`tgl_akhir` >= `tgl_mulai`)
        ) " . self::TABLE_OPTIONS . ' ROW_FORMAT=DYNAMIC';

        return $sql;
    }

    /**
     * Pembersihan setelah up() gagal: drop tabel yang dibuat pada run ini, urutan terbalik (anak dulu). Kegagalan
     * drop tidak menutupi error asli (yang dilempar ulang pemanggil) — sisa tabel lalu dibersihkan manual (G-doc
     * Bagian 6, paragraf pemulihan).
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

            throw new RuntimeException('DBV-003: DDL hari libur gagal (' . $error['code'] . '): ' . $error['message']);
        }
    }
}
