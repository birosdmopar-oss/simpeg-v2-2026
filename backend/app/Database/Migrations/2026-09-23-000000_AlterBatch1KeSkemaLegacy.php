<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Migration;
use RuntimeException;

/**
 * DBV-001 — Revisi skema G-01 Batch 1 ke skema SIMPEG legacy (Mapping Migrasi Prinsip #1: nama tabel & kolom
 * tetap sama dengan legacy). Menggantikan kolom hasil seed lokal (`nama_*`, VARCHAR(10)/(5), ENUM '0'/'1')
 * yang dipakai migration 2026-09-22-000001/000002. Migration yang sudah disetujui TIDAK diedit; perubahan
 * dilakukan lewat ALTER di migration baru ini.
 *
 * Sumber skema (label per kolom ada di backend/docs/db-review/G-01-master-schema.md Bagian 8):
 *   - agama           : DDL produksi (simpeg_prod.sql, host 172.17.100.83) — terkonfirmasi.
 *   - wilayah 4 level : kode legacy (hr/master/c_umum, Lm_umum.php) + tipe kolom kode wilayah di DDL produksi
 *                       (form_kesehatan: char(2/4/7/10)); DDL tabel master wilayah sendiri belum tersedia.
 *   - jenis_pegawai, jenis_status : kode legacy; DDL belum tersedia (panjang kolom masih dugaan).
 *
 * Sekaligus menyelesaikan (keputusan user 23-09-2026):
 *   - ISSUE-008 panjang kode wilayah → CHAR(2/4/7/10) sesuai legacy + validasi tepat N digit di aplikasi.
 *   - ISSUE-009 keunikan nama → UNIQUE index (nama / induk+nama; jenis_status: status_pegawai+jenis_status).
 *   - ISSUE-010 collation → utf8mb4_unicode_ci (dipakai 57 dari 69 tabel di DDL produksi) untuk 7 tabel ini.
 *     DBCollat koneksi tetap utf8mb4_general_ci: tabel baru yang mereferensikan kolom string tabel ini wajib
 *     menulis COLLATE utf8mb4_unicode_ci eksplisit (FK string beda collation ditolak MySQL 8).
 *
 * Status mengikuti legacy: TINYINT 1 = Aktif, 2 = Tidak Aktif, 10 = Dihapus (soft delete v2). Kolom audit
 * (created_at, updated_at, updated_by, dan deleted_at untuk agama) mengikuti legacy.
 *
 * up() hanya boleh dijalankan selagi ketujuh tabel KOSONG (belum ada impor data legacy): kode lama (VARCHAR, mis.
 * 'PNS' atau kecamatan 6 digit) tidak bisa dikonversi otomatis ke PK legacy. Kalau ada data uji, kosongkan dulu
 * (lihat pesan error). down() mengonversi data yang ada (tipe dilebarkan, status 1→'1', 2/10→'0'); kolom legacy
 * tambahan (kd_area, kd_pos, status_pegawai, kolom audit) ikut terhapus.
 *
 * Review DB Validator: backend/docs/db-review/G-01-master-schema.md Bagian 8 (DBV-001) — JANGAN dijalankan di
 * Dev/Production sebelum disetujui.
 */
class AlterBatch1KeSkemaLegacy extends Migration
{
    private const STATUS_COMMENT = '1: Aktif, 2: Tidak Aktif, 10: Dihapus';

    /**
     * Urutan anak → induk (untuk pengecekan & drop FK).
     */
    private const TABLES = ['kelurahan', 'kecamatan', 'kabupaten_kota', 'provinsi', 'agama', 'jenis_pegawai', 'jenis_status'];

    /**
     * FK wilayah: [tabel anak, kolom, tabel induk, nama FK approved (Batch 1), nama FK legacy (ERD simpeg01)].
     *
     * @var list<array{0: string, 1: string, 2: string, 3: string, 4: string}>
     */
    private const WILAYAH_FK = [
        ['kabupaten_kota', 'id_provinsi', 'provinsi', 'fk_kabupaten_kota_provinsi', 'fk_id_provinsi_kabupaten_kota_to_provinsi'],
        ['kecamatan', 'id_kabupaten_kota', 'kabupaten_kota', 'fk_kecamatan_kabupaten_kota', 'fk_id_kabupaten_kota_kecamatan_to_kabupaten_kota'],
        ['kelurahan', 'id_kecamatan', 'kecamatan', 'fk_kelurahan_kecamatan', 'fk_id_kecamatan_kelurahan_to_kecamatan'],
    ];

    public function up(): void
    {
        $this->assertTablesEmpty();

        foreach (self::WILAYAH_FK as [$table, , , $approvedFk]) {
            $this->exec("ALTER TABLE {$this->t($table)} DROP FOREIGN KEY `{$approvedFk}`");
        }

        foreach (self::TABLES as $table) {
            $this->exec("ALTER TABLE {$this->t($table)} CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        }

        $audit = $this->auditColumnsSql();

        $this->exec("ALTER TABLE {$this->t('provinsi')}
            MODIFY `id_provinsi` CHAR(2) NOT NULL COMMENT 'kode wilayah legacy, 2 digit',
            CHANGE `nama_provinsi` `provinsi` VARCHAR(255) NOT NULL,
            MODIFY `status` TINYINT NOT NULL DEFAULT 1 COMMENT '" . self::STATUS_COMMENT . "',
            {$audit},
            ADD UNIQUE KEY `uq_provinsi_nama` (`provinsi`)");

        $this->exec("ALTER TABLE {$this->t('kabupaten_kota')}
            MODIFY `id_kabupaten_kota` CHAR(4) NOT NULL COMMENT 'kode wilayah legacy, 4 digit',
            MODIFY `id_provinsi` CHAR(2) NOT NULL,
            CHANGE `nama_kabupaten_kota` `kabupaten_kota` VARCHAR(255) NOT NULL,
            ADD `kd_area` VARCHAR(4) NULL DEFAULT NULL COMMENT 'kode area telepon' AFTER `kabupaten_kota`,
            MODIFY `status` TINYINT NOT NULL DEFAULT 1 COMMENT '" . self::STATUS_COMMENT . "',
            {$audit},
            ADD UNIQUE KEY `uq_kabupaten_kota_nama` (`id_provinsi`, `kabupaten_kota`)");

        $this->exec("ALTER TABLE {$this->t('kecamatan')}
            MODIFY `id_kecamatan` CHAR(7) NOT NULL COMMENT 'kode wilayah legacy, 7 digit',
            MODIFY `id_kabupaten_kota` CHAR(4) NOT NULL,
            CHANGE `nama_kecamatan` `kecamatan` VARCHAR(255) NOT NULL,
            MODIFY `status` TINYINT NOT NULL DEFAULT 1 COMMENT '" . self::STATUS_COMMENT . "',
            {$audit},
            ADD UNIQUE KEY `uq_kecamatan_nama` (`id_kabupaten_kota`, `kecamatan`)");

        $this->exec("ALTER TABLE {$this->t('kelurahan')}
            MODIFY `id_kelurahan` CHAR(10) NOT NULL COMMENT 'kode wilayah legacy, 10 digit',
            MODIFY `id_kecamatan` CHAR(7) NOT NULL,
            CHANGE `nama_kelurahan` `kelurahan` VARCHAR(255) NOT NULL,
            ADD `kd_pos` VARCHAR(50) NULL DEFAULT NULL COMMENT 'satu/lebih kode pos 5 digit, dipisah koma' AFTER `kelurahan`,
            MODIFY `status` TINYINT NOT NULL DEFAULT 1 COMMENT '" . self::STATUS_COMMENT . "',
            {$audit},
            ADD UNIQUE KEY `uq_kelurahan_nama` (`id_kecamatan`, `kelurahan`)");

        foreach (self::WILAYAH_FK as [$table, $column, $parent, , $legacyFk]) {
            $this->exec("ALTER TABLE {$this->t($table)} ADD CONSTRAINT `{$legacyFk}` FOREIGN KEY (`{$column}`)
                REFERENCES {$this->t($parent)} (`{$column}`) ON DELETE RESTRICT ON UPDATE RESTRICT");
        }

        $this->exec("ALTER TABLE {$this->t('agama')}
            MODIFY `id_agama` TINYINT NOT NULL AUTO_INCREMENT,
            CHANGE `nama_agama` `agama` VARCHAR(30) NOT NULL,
            MODIFY `order` TINYINT NOT NULL DEFAULT 1,
            MODIFY `status` TINYINT NOT NULL DEFAULT 1 COMMENT '" . self::STATUS_COMMENT . "',
            {$audit},
            ADD `deleted_at` DATETIME NULL DEFAULT NULL,
            ADD UNIQUE KEY `uq_agama_nama` (`agama`)");

        $this->exec("ALTER TABLE {$this->t('jenis_pegawai')}
            MODIFY `id_jenis_pegawai` TINYINT NOT NULL AUTO_INCREMENT,
            CHANGE `nama_jenis_pegawai` `jenis_pegawai` VARCHAR(50) NOT NULL,
            MODIFY `order` TINYINT NOT NULL DEFAULT 1,
            MODIFY `status` TINYINT NOT NULL DEFAULT 1 COMMENT '" . self::STATUS_COMMENT . "',
            {$audit},
            ADD UNIQUE KEY `uq_jenis_pegawai_nama` (`jenis_pegawai`)");

        $this->exec("ALTER TABLE {$this->t('jenis_status')}
            MODIFY `id_jenis_status` TINYINT NOT NULL AUTO_INCREMENT,
            CHANGE `nama_jenis_status` `jenis_status` VARCHAR(50) NOT NULL,
            ADD `status_pegawai` TINYINT NOT NULL DEFAULT 1 COMMENT '1: Aktif, 2: Tidak Aktif (kelompok status pegawai)' AFTER `jenis_status`,
            MODIFY `order` TINYINT NOT NULL DEFAULT 1,
            MODIFY `status` TINYINT NOT NULL DEFAULT 1 COMMENT '" . self::STATUS_COMMENT . "',
            {$audit},
            ADD UNIQUE KEY `uq_jenis_status_nama` (`status_pegawai`, `jenis_status`)");
    }

    /**
     * Kembali ke skema Batch 1 yang disetujui 23-09-2026 (2026-09-22-000001/000002). Data yang ada dipertahankan
     * (dipakai juga oleh migrate:refresh di test): status legacy dipetakan 1 → '1', 2/10 → '0'. Kolom legacy tambahan
     * (kd_area, kd_pos, status_pegawai, kolom audit, deleted_at) ikut terhapus. Menolak jalan kalau ada nama wilayah
     * > 100 karakter (tidak muat di VARCHAR(100) Batch 1).
     */
    public function down(): void
    {
        $this->assertNamesFitApprovedLength();

        foreach (self::WILAYAH_FK as [$table, , , , $legacyFk]) {
            $this->exec("ALTER TABLE {$this->t($table)} DROP FOREIGN KEY `{$legacyFk}`");
        }

        // Status TINYINT → ENUM tidak boleh langsung (angka diperlakukan sebagai indeks ENUM): lewat teks dulu.
        foreach (self::TABLES as $table) {
            $this->exec("ALTER TABLE {$this->t($table)} MODIFY `status` VARCHAR(2) NOT NULL DEFAULT '1'");
            $this->exec("UPDATE {$this->t($table)} SET `status` = IF(`status` = '1', '1', '0')");
        }

        $dropAudit = 'DROP COLUMN `created_at`, DROP COLUMN `updated_at`, DROP COLUMN `updated_by`';
        $status    = "MODIFY `status` ENUM('0','1') NOT NULL DEFAULT '1' COMMENT '1 aktif, 0 non-aktif/soft delete'";

        $levels = [
            // tabel => [pk, kolom induk|null, nama legacy, nama approved, kolom tambahan legacy|null]
            'provinsi'       => ['id_provinsi', null, 'provinsi', 'nama_provinsi', null],
            'kabupaten_kota' => ['id_kabupaten_kota', 'id_provinsi', 'kabupaten_kota', 'nama_kabupaten_kota', 'kd_area'],
            'kecamatan'      => ['id_kecamatan', 'id_kabupaten_kota', 'kecamatan', 'nama_kecamatan', null],
            'kelurahan'      => ['id_kelurahan', 'id_kecamatan', 'kelurahan', 'nama_kelurahan', 'kd_pos'],
        ];

        foreach ($levels as $table => [$pk, $parent, $legacyName, $approvedName, $extra]) {
            $specs = [
                "DROP INDEX `uq_{$table}_nama`",
                "MODIFY `{$pk}` VARCHAR(10) NOT NULL COMMENT 'kode wilayah (BPS/Kemendagri), tanpa titik'",
            ];

            if ($parent !== null) {
                $specs[] = "MODIFY `{$parent}` VARCHAR(10) NOT NULL";
            }

            $specs[] = "CHANGE `{$legacyName}` `{$approvedName}` VARCHAR(100) NOT NULL";

            if ($extra !== null) {
                $specs[] = "DROP COLUMN `{$extra}`";
            }

            $specs[] = $status;
            $specs[] = $dropAudit;

            $this->exec("ALTER TABLE {$this->t($table)} " . implode(', ', $specs));
        }

        $referensi = [
            // tabel => [pk, nama legacy, nama approved, spesifikasi tambahan]
            'agama'         => ['id_agama', 'agama', 'nama_agama', ['DROP COLUMN `deleted_at`']],
            'jenis_pegawai' => ['id_jenis_pegawai', 'jenis_pegawai', 'nama_jenis_pegawai', []],
            'jenis_status'  => ['id_jenis_status', 'jenis_status', 'nama_jenis_status', ['DROP COLUMN `status_pegawai`']],
        ];

        foreach ($referensi as $table => [$pk, $legacyName, $approvedName, $extraSpecs]) {
            $specs = [
                "DROP INDEX `uq_{$table}_nama`",
                "MODIFY `{$pk}` VARCHAR(5) NOT NULL",
                "CHANGE `{$legacyName}` `{$approvedName}` VARCHAR(50) NOT NULL",
                "MODIFY `order` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'urutan tampil'",
                $status,
                $dropAudit,
                ...$extraSpecs,
            ];

            $this->exec("ALTER TABLE {$this->t($table)} " . implode(', ', $specs));
        }

        foreach (self::TABLES as $table) {
            $this->exec("ALTER TABLE {$this->t($table)} CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
        }

        foreach (self::WILAYAH_FK as [$table, $column, $parent, $approvedFk]) {
            $this->exec("ALTER TABLE {$this->t($table)} ADD CONSTRAINT `{$approvedFk}` FOREIGN KEY (`{$column}`)
                REFERENCES {$this->t($parent)} (`{$column}`) ON DELETE RESTRICT ON UPDATE RESTRICT");
        }
    }

    /**
     * Kolom audit legacy: created_at, updated_at (ON UPDATE), updated_by (id pengguna). Diisi aplikasi (UTC);
     * default DB hanya cadangan untuk penulisan di luar aplikasi.
     */
    private function auditColumnsSql(): string
    {
        return 'ADD `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, '
            . 'ADD `updated_at` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP, '
            . "ADD `updated_by` INT NULL DEFAULT NULL COMMENT 'id_pengguna yang terakhir mengubah'";
    }

    /**
     * down() menyempitkan nama wilayah VARCHAR(255) → VARCHAR(100): tolak sebelum ada ALTER yang jalan kalau ada
     * nama yang tidak muat (mode non-strict akan memotong diam-diam, mode strict gagal di tengah).
     */
    private function assertNamesFitApprovedLength(): void
    {
        $tooLong = [];

        foreach (['provinsi', 'kabupaten_kota', 'kecamatan', 'kelurahan'] as $column) {
            $row = $this->db->table($column)->select('MAX(CHAR_LENGTH(`' . $column . '`)) AS panjang', false)->get()->getRowArray();

            if ((int) ($row['panjang'] ?? 0) > 100) {
                $tooLong[] = "{$column} ({$row['panjang']} karakter)";
            }
        }

        if ($tooLong !== []) {
            throw new RuntimeException(
                'DBV-001: rollback ke skema Batch 1 akan memotong nama wilayah > 100 karakter: ' . implode(', ', $tooLong)
                . '. Perpendek nama tersebut dulu.',
            );
        }
    }

    private function assertTablesEmpty(): void
    {
        $filled = [];

        foreach (self::TABLES as $table) {
            $count = $this->db->table($table)->countAllResults();

            if ($count > 0) {
                $filled[] = "{$table} ({$count} baris)";
            }
        }

        if ($filled !== []) {
            throw new RuntimeException(
                'DBV-001: migration revisi skema Batch 1 hanya boleh dijalankan selagi tabel master Batch 1 kosong. '
                . 'Tabel berisi: ' . implode(', ', $filled) . '. Kosongkan data uji dulu (urutan: kelurahan, kecamatan, '
                . 'kabupaten_kota, provinsi, agama, jenis_pegawai, jenis_status), lalu jalankan ulang migrate.',
            );
        }
    }

    /**
     * Nama tabel ber-prefix (DBPrefix, mis. `t_` di database test) yang sudah di-escape untuk SQL mentah.
     */
    private function t(string $table): string
    {
        if (! $this->db instanceof BaseConnection) {
            throw new RuntimeException('DBV-001: koneksi database tidak mendukung prefix tabel.');
        }

        return $this->db->escapeIdentifiers($this->db->prefixTable($table));
    }

    private function exec(string $sql): void
    {
        // Dengan DBDebug = false query() mengembalikan false tanpa exception: jangan biarkan ALTER gagal diam-diam.
        if ($this->db->query($sql) === false) {
            $error = $this->db->error();

            throw new RuntimeException('DBV-001: ALTER gagal (' . $error['code'] . '): ' . $error['message']);
        }
    }
}
