<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Migration;
use RuntimeException;
use Throwable;

/**
 * DBV-005 — G-06: master diklat, tingkat & jenis hukuman disiplin, jenis konfirmasi ketidakhadiran (konket), dan tanda
 * jasa dengan skema SIMPEG legacy (Mapping Migrasi Prinsip #1, Tier 1 "Copy langsung").
 * Sumber: `diklat` = DDL produksi `simpeg_prod.sql:443-452` [K]. `tingkat_hukdis`, `jenis_hukdis`, `jenis_konket`, dan
 * `tanda_jasa` tidak ada di dump (dump berhenti di `jabatan`), jadi kolomnya [I] dari kode legacy (`Lm_hukdis`,
 * `Lm_konket`, `Lm_tj`, `rwy/L_konket`), `simpegdev_local`, dan ERD simpeg01 (nama FK). Review DB Validator:
 * backend/docs/db-review/G-06-diklat-hukdis-konket-tanda-jasa-schema.md — JANGAN dijalankan di Dev/Production sebelum
 * disetujui.
 *
 * Nilai legacy yang dipertahankan: nama tabel/kolom; DDL `diklat` (PK TINYINT signed, `jenis_diklat` TINYINT(1) dengan
 * COMMENT legacy, `order` TINYINT, `updated_at` NOT NULL ON UPDATE + `updated_by`, tanpa created_*); nama FK
 * `fk_id_tingkat_hukdis_jenhukdis_to_tkhukdis`; `tingkat_hukdis.bobot_ipasn` INT DEFAULT 5; `jenis_konket.old_id` sebagai
 * kode kategori (= `absen_ijin.kategori`); PK AUTO_INCREMENT; collation utf8mb4_unicode_ci.
 *
 * Deviasi dari legacy (dicatat untuk DBV):
 *   - Status 1 Aktif / 2 Tidak Aktif / 10 Dihapus + COMMENT v2 di kelima tabel (legacy: tingkat, konket, dan tanda jasa
 *     hard delete; `jenis_hukdis` dan jalur mati `dm_diklat` memakai 10).
 *   - UNIQUE nama `diklat (jenis_diklat, nama_diklat)`, `tingkat_hukdis`, `jenis_hukdis (id_tingkat_hukdis,
 *     jenis_hukdis)`, `jenis_konket`, `tanda_jasa`, plus UNIQUE `jenis_konket.old_id`; berlaku juga untuk baris status 2/10.
 *   - FK jenis_hukdis → tingkat_hukdis ON DELETE RESTRICT ON UPDATE RESTRICT (aksi legacy tidak tercatat di ERD).
 *   - 3 CHECK: `jenis_diklat` 1–5, `masa_sanksi_bulan` NULL atau ≥ 1, `affect_tukin` 1/2.
 *   - Kolom baru [V2] keputusan user K5: `jenis_konket.affect_tukin` TINYINT NOT NULL DEFAULT 1 (1 Ya / 2 Tidak) dan
 *     `jenis_hukdis.masa_sanksi_bulan` TINYINT UNSIGNED NULL.
 *   - `jenis_konket.old_id` INT NOT NULL (satu-satunya DDL lokal: VARCHAR(50) NULL); tipe ikut `absen_ijin.kategori`.
 *   - PK empat tabel [I] INT (bukti tipe hanya `simpegdev_local`); `order`, `status`, dan kolom audit [I] meniru `diklat`.
 *
 * Kolom audit `updated_at`/`updated_by` diisi aplikasi (waktu UTC, id_pengguna aktor) saat insert maupun update (mode
 * Batch 1); default DB hanya cadangan untuk penulisan di luar aplikasi. Nilai AUTO_INCREMENT awal tidak ditulis (impor
 * ID eksplisit menaikkan counter otomatis). Tanpa baris seed: ID yang di-hard-code kode legacy (konket 2/4/5/6 dan
 * old_id 8/10/13, tanda jasa 26/27/28/44, diklat 8) datang dari impor ID legacy apa adanya.
 *
 * DDL MySQL/MariaDB ter-commit per statement dan migration yang gagal tidak tercatat di tabel `migrations`: bila salah
 * satu CREATE gagal, up() men-drop tabel yang sempat dibuat PADA RUN ITU (urutan terbalik) lalu melempar ulang error,
 * sehingga `php spark migrate` bisa langsung diulang. Tabel yang sudah ada sebelum run tidak disentuh.
 */
class CreateDiklatHukdisKonketTandaJasa extends Migration
{
    private const STATUS_COMMENT = '1: Aktif, 2: Tidak Aktif, 10: Dihapus';

    private const TABLE_OPTIONS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    /**
     * Urutan untuk down(): `jenis_hukdis` (anak) sebelum `tingkat_hukdis` (induk).
     */
    private const TABLES = ['tanda_jasa', 'jenis_konket', 'jenis_hukdis', 'tingkat_hukdis', 'diklat'];

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
        $status = "COMMENT '" . self::STATUS_COMMENT . "'";
        $audit  = $this->auditColumnsSql();
        $sql    = [];

        // DDL legacy [K] simpeg_prod.sql:443-452. COMMENT jenis_diklat dipertahankan verbatim (dasar opsi 1–5, B1).
        $sql['diklat'] = "CREATE TABLE {$this->t('diklat')} (
            `id_diklat` TINYINT NOT NULL AUTO_INCREMENT,
            `jenis_diklat` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1: Struktural, 2: Teknis, 3: Fungsional, 4: Prajabatan,5:Sertifikasi',
            `nama_diklat` VARCHAR(255) NOT NULL,
            `order` TINYINT NOT NULL DEFAULT 1,
            `status` TINYINT NOT NULL DEFAULT 1 {$status},
            {$audit},
            PRIMARY KEY (`id_diklat`),
            UNIQUE KEY `uq_diklat_nama` (`jenis_diklat`, `nama_diklat`),
            CONSTRAINT `ck_diklat_jenis_diklat` CHECK (`jenis_diklat` BETWEEN 1 AND 5)
        ) " . self::TABLE_OPTIONS;

        // bobot_ipasn: skor IPASN dimensi disiplin legacy (L_user.php:1059-1070); disimpan, tidak dikelola v2 (B5).
        $sql['tingkat_hukdis'] = "CREATE TABLE {$this->t('tingkat_hukdis')} (
            `id_tingkat_hukdis` INT NOT NULL AUTO_INCREMENT,
            `tingkat_hukdis` VARCHAR(100) NOT NULL,
            `bobot_ipasn` INT NULL DEFAULT 5 COMMENT 'bobot skor IPASN dimensi disiplin (legacy); tidak dikelola v2',
            `order` TINYINT NOT NULL DEFAULT 1,
            `status` TINYINT NOT NULL DEFAULT 1 {$status},
            {$audit},
            PRIMARY KEY (`id_tingkat_hukdis`),
            UNIQUE KEY `uq_tingkat_hukdis_nama` (`tingkat_hukdis`)
        ) " . self::TABLE_OPTIONS;

        // UNIQUE uq_jenis_hukdis_nama sudah diawali id_tingkat_hukdis (KEY FK secara teknis berlebih); KEY FK tetap
        // dibuat dengan nama legacy agar DDL sebanding dengan produksi (preseden G-10).
        $sql['jenis_hukdis'] = "CREATE TABLE {$this->t('jenis_hukdis')} (
            `id_jenis_hukdis` INT NOT NULL AUTO_INCREMENT,
            `id_tingkat_hukdis` INT NOT NULL,
            `jenis_hukdis` VARCHAR(255) NOT NULL,
            `masa_sanksi_bulan` TINYINT UNSIGNED NULL DEFAULT NULL COMMENT 'masa sanksi bawaan dalam bulan untuk hitung akhir_hukdis; NULL = tanpa masa',
            `order` TINYINT NOT NULL DEFAULT 1,
            `status` TINYINT NOT NULL DEFAULT 1 {$status},
            {$audit},
            PRIMARY KEY (`id_jenis_hukdis`),
            UNIQUE KEY `uq_jenis_hukdis_nama` (`id_tingkat_hukdis`, `jenis_hukdis`),
            KEY `fk_id_tingkat_hukdis_jenhukdis_to_tkhukdis` (`id_tingkat_hukdis`),
            CONSTRAINT `fk_id_tingkat_hukdis_jenhukdis_to_tkhukdis` FOREIGN KEY (`id_tingkat_hukdis`)
                REFERENCES {$this->t('tingkat_hukdis')} (`id_tingkat_hukdis`) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT `ck_jenis_hukdis_masa_sanksi_bulan` CHECK (`masa_sanksi_bulan` IS NULL OR `masa_sanksi_bulan` >= 1)
        ) " . self::TABLE_OPTIONS;

        // old_id = absen_ijin.kategori: relasi logis tanpa FK fisik (legacy juga tanpa FK, simpeg_prod.sql:68).
        $sql['jenis_konket'] = "CREATE TABLE {$this->t('jenis_konket')} (
            `id_jenis_konket` INT NOT NULL AUTO_INCREMENT,
            `old_id` INT NOT NULL COMMENT 'kode kategori konket = absen_ijin.kategori',
            `jenis_konket` VARCHAR(255) NOT NULL,
            `affect_tukin` TINYINT NOT NULL DEFAULT 1 COMMENT '1: Ya, 2: Tidak (nilai awal affect_tukin pengajuan konket)',
            `order` TINYINT NOT NULL DEFAULT 1,
            `status` TINYINT NOT NULL DEFAULT 1 {$status},
            {$audit},
            PRIMARY KEY (`id_jenis_konket`),
            UNIQUE KEY `uq_jenis_konket_nama` (`jenis_konket`),
            UNIQUE KEY `uq_jenis_konket_old_id` (`old_id`),
            CONSTRAINT `ck_jenis_konket_affect_tukin` CHECK (`affect_tukin` IN (1, 2))
        ) " . self::TABLE_OPTIONS;

        $sql['tanda_jasa'] = "CREATE TABLE {$this->t('tanda_jasa')} (
            `id_tanda_jasa` INT NOT NULL AUTO_INCREMENT,
            `tanda_jasa` VARCHAR(255) NOT NULL,
            `order` TINYINT NOT NULL DEFAULT 1,
            `status` TINYINT NOT NULL DEFAULT 1 {$status},
            {$audit},
            PRIMARY KEY (`id_tanda_jasa`),
            UNIQUE KEY `uq_tanda_jasa_nama` (`tanda_jasa`)
        ) " . self::TABLE_OPTIONS;

        return $sql;
    }

    /**
     * Pembersihan setelah up() gagal: drop tabel yang dibuat pada run ini, urutan terbalik (anak dulu). Kegagalan
     * drop tidak menutupi error asli (yang dilempar ulang pemanggil) — sisa tabel lalu dibersihkan manual (G-06
     * Bagian 6.6).
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
     * Kolom audit pola `diklat` [K] (simpeg_prod.sql:449-450): updated_at NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE,
     * updated_by = id_pengguna aktor tanpa FK (preseden DBV-001). Tanpa created_at/created_by.
     */
    private function auditColumnsSql(): string
    {
        return '`updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, '
            . "`updated_by` INT NULL DEFAULT NULL COMMENT 'id_pengguna yang terakhir mengubah'";
    }

    /**
     * Nama tabel ber-prefix (DBPrefix, mis. `t_` di database test) yang sudah di-escape untuk SQL mentah.
     */
    private function t(string $table): string
    {
        if (! $this->db instanceof BaseConnection) {
            throw new RuntimeException('DBV-005: koneksi database tidak mendukung prefix tabel.');
        }

        return $this->db->escapeIdentifiers($this->db->prefixTable($table));
    }

    private function exec(string $sql): void
    {
        // Dengan DBDebug = false query() mengembalikan false tanpa exception: jangan biarkan DDL gagal diam-diam.
        if ($this->db->query($sql) === false) {
            $error = $this->db->error();

            throw new RuntimeException('DBV-005: DDL G-06 gagal (' . $error['code'] . '): ' . $error['message']);
        }
    }
}
