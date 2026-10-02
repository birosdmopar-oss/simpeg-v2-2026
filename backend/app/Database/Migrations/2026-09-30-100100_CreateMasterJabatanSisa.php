<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Migration;
use RuntimeException;
use Throwable;

/**
 * DBV-018 — G-02 sisa: delapan tabel jabatan yang belum dibuat DBV-008, dengan skema SIMPEG legacy dari dump struktur
 * produksi lengkap `simpeg01_struktur_lengkap_20261001.sql` (D1, MySQL 8.0.21) [K]:
 *   `jenjang_jf` D1:1787-1796, `rumpun_jabatan` D1:6678-6688, `subrumpun_jabatan` D1:7165-7178,
 *   `jabatan_akademik` D1:1495-1505, `periode_struktur_jabatan` D1:3880-3888, `struktur_jabatan` D1:7087-7107,
 *   `peta_jabatan` D1:3893-3912, `jabatan_koordinasi` D1:1510-1545.
 * FK `jabatan.id_jenjang_jf` → `jenjang_jf` (D1:1469) ada di migration terpisah `2026-09-30-100200`.
 * Review DB Validator: backend/docs/db-review/G-02b-jabatan-sisa-schema.md — JANGAN dijalankan di Dev/Production
 * sebelum disetujui.
 *
 * Nilai legacy yang dipertahankan: nama tabel/kolom, urutan kolom, tipe & panjang (termasuk PK TINYINT `jenjang_jf`,
 * `rumpun_jabatan`, `subrumpun_jabatan`; ENUM `kategori_jf`), default, nullability, nama KEY/FK legacy, kolom audit
 * legacy per tabel (ada/tidaknya `created_by`), COMMENT legacy yang bukan COMMENT status.
 *
 * Deviasi dari legacy (dicatat untuk DBV, alasan di dokumen Bagian 3):
 *   - Status TINYINT 1 Aktif / 2 Tidak Aktif / 10 Dihapus + COMMENT v2 di kedelapan tabel: ENUM('1','2')
 *     `rumpun_jabatan`/`subrumpun_jabatan` → TINYINT; kolom `status` ditambahkan di `jenjang_jf` dan `struktur_jabatan`
 *     (legacy tanpa status, hapus keras).
 *   - Collation `utf8mb4_unicode_ci` per tabel/kolom (legacy `struktur_jabatan` utf8mb4_0900_ai_ci).
 *   - FK ON DELETE RESTRICT ON UPDATE RESTRICT dengan nama legacy (legacy CASCADE / SET NULL / NO ACTION).
 *   - 6 UNIQUE: nama `jenjang_jf (kategori_jf, jenjang_jf)`, `rumpun_jabatan`, `subrumpun_jabatan (id_rumpun_jabatan,
 *     subrumpun_jabatan)`, `jabatan_akademik`, `periode_struktur_jabatan`, dan `struktur_jabatan
 *     (id_periode_struktur_jabatan, id_jabatan, id_jabatan_atasan)`. `peta_jabatan` dan `jabatan_koordinasi` tanpa
 *     UNIQUE (keputusan dokumen Bagian 4).
 *   - 4 CHECK: `jabatan_akademik.is_atasan` 1/2, `struktur_jabatan.level_struktur` 1..5, `jabatan_koordinasi.jenis` 1/2,
 *     `peta_jabatan` kebutuhan ≥ 0.
 *   - Trigger legacy tidak ada di delapan tabel ini (D1 tidak memuat trigger untuk tabel-tabel tersebut).
 *
 * Kolom audit diisi aplikasi (waktu UTC, id_pengguna aktor). Nilai AUTO_INCREMENT awal tidak ditulis (counter D1 dicatat
 * di dokumen; disalin saat impor). Tanpa baris seed: data datang dari impor ID legacy apa adanya.
 *
 * DDL ter-commit per statement: bila salah satu CREATE gagal, up() men-drop tabel yang sempat dibuat PADA RUN ITU (urutan
 * terbalik) lalu melempar ulang error, sehingga `php spark migrate` bisa langsung diulang (pola DBV-008).
 */
class CreateMasterJabatanSisa extends Migration
{
    private const STATUS_COMMENT = '1: Aktif, 2: Tidak Aktif, 10: Dihapus';

    private const TABLE_OPTIONS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    /**
     * Urutan untuk down(): anak sebelum induk.
     */
    private const TABLES = [
        'jabatan_koordinasi', 'peta_jabatan', 'struktur_jabatan', 'periode_struktur_jabatan', 'jabatan_akademik',
        'subrumpun_jabatan', 'rumpun_jabatan', 'jenjang_jf',
    ];

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
        $status    = "`status` TINYINT NOT NULL DEFAULT 1 COMMENT '" . self::STATUS_COMMENT . "'";
        $createdBy = "`created_by` INT NULL DEFAULT NULL COMMENT 'id_pengguna pembuat'";
        $updatedBy = "`updated_by` INT NULL DEFAULT NULL COMMENT 'id_pengguna yang terakhir mengubah'";
        $createdAt = '`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP';
        $updatedAt = '`updated_at` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP';
        $restrict  = 'ON DELETE RESTRICT ON UPDATE RESTRICT';
        $sql       = [];

        // [K] D1:1787-1796. Tanpa CRUD admin di legacy (dipakai rwy/L_ak.php & jabatan.id_jenjang_jf). status [V2].
        $sql['jenjang_jf'] = "CREATE TABLE {$this->t('jenjang_jf')} (
            `id_jenjang_jf` TINYINT NOT NULL AUTO_INCREMENT,
            `kategori_jf` ENUM('Ahli','Terampil') NOT NULL DEFAULT 'Ahli',
            `jenjang_jf` VARCHAR(50) NOT NULL,
            `extra_nama_jab` VARCHAR(50) NOT NULL,
            `order` TINYINT NOT NULL DEFAULT 1,
            {$status},
            {$createdAt},
            {$updatedAt},
            PRIMARY KEY (`id_jenjang_jf`),
            UNIQUE KEY `uq_jenjang_jf_nama` (`kategori_jf`, `jenjang_jf`)
        ) " . self::TABLE_OPTIONS;

        // [K] D1:6678-6688. status ENUM('1','2') → TINYINT 1/2/10 [V2]. Urutan global (form legacy "Urutan").
        $sql['rumpun_jabatan'] = "CREATE TABLE {$this->t('rumpun_jabatan')} (
            `id_rumpun_jabatan` TINYINT NOT NULL AUTO_INCREMENT,
            `rumpun_jabatan` VARCHAR(50) NOT NULL,
            `order` TINYINT NOT NULL DEFAULT 0,
            {$status},
            {$createdAt},
            {$createdBy},
            {$updatedAt},
            {$updatedBy},
            PRIMARY KEY (`id_rumpun_jabatan`),
            UNIQUE KEY `uq_rumpun_jabatan_nama` (`rumpun_jabatan`)
        ) " . self::TABLE_OPTIONS;

        // [K] D1:7165-7178. FK legacy CASCADE/CASCADE → RESTRICT [V2]. Urutan per rumpun.
        $sql['subrumpun_jabatan'] = "CREATE TABLE {$this->t('subrumpun_jabatan')} (
            `id_subrumpun_jabatan` TINYINT NOT NULL AUTO_INCREMENT,
            `id_rumpun_jabatan` TINYINT NULL DEFAULT NULL,
            `subrumpun_jabatan` VARCHAR(255) NOT NULL,
            `order` TINYINT NOT NULL DEFAULT 0,
            {$status},
            {$createdAt},
            {$createdBy},
            {$updatedAt},
            {$updatedBy},
            PRIMARY KEY (`id_subrumpun_jabatan`),
            UNIQUE KEY `uq_subrumpun_jabatan_nama` (`id_rumpun_jabatan`, `subrumpun_jabatan`),
            KEY `subrumpun_jabatan_ibfk_01` (`id_rumpun_jabatan`),
            CONSTRAINT `subrumpun_jabatan_ibfk_01` FOREIGN KEY (`id_rumpun_jabatan`)
                REFERENCES {$this->t('rumpun_jabatan')} (`id_rumpun_jabatan`) {$restrict}
        ) " . self::TABLE_OPTIONS;

        // [K] D1:1495-1505. Tanpa kolom `order` (legacy urut nama).
        $sql['jabatan_akademik'] = "CREATE TABLE {$this->t('jabatan_akademik')} (
            `id_jabatan_akademik` INT NOT NULL AUTO_INCREMENT,
            `jabatan_akademik` VARCHAR(255) NOT NULL,
            `is_atasan` TINYINT NOT NULL DEFAULT 2 COMMENT '1: Ya, 2: Tidak',
            {$status},
            {$createdAt},
            {$createdBy},
            {$updatedAt},
            {$updatedBy},
            PRIMARY KEY (`id_jabatan_akademik`),
            UNIQUE KEY `uq_jabatan_akademik_nama` (`jabatan_akademik`),
            CONSTRAINT `chk_jabatan_akademik_is_atasan` CHECK (`is_atasan` IN (1, 2))
        ) " . self::TABLE_OPTIONS;

        // [K] D1:3880-3888. Tanpa kolom `order` dan tanpa created_by.
        $sql['periode_struktur_jabatan'] = "CREATE TABLE {$this->t('periode_struktur_jabatan')} (
            `id_periode_struktur_jabatan` INT NOT NULL AUTO_INCREMENT,
            `periode_struktur_jabatan` VARCHAR(45) NOT NULL,
            {$status},
            {$createdAt},
            {$updatedAt},
            {$updatedBy},
            PRIMARY KEY (`id_periode_struktur_jabatan`),
            UNIQUE KEY `uq_periode_struktur_jabatan_nama` (`periode_struktur_jabatan`)
        ) " . self::TABLE_OPTIONS;

        // [K] D1:7087-7107. Kolom nama periode/jabatan/atasan = salinan denormalisasi (diisi saat simpan, legacy
        // set_param_struktur). status [V2] (legacy hapus keras), collation unicode_ci [V2] (legacy 0900).
        $sql['struktur_jabatan'] = "CREATE TABLE {$this->t('struktur_jabatan')} (
            `id_struktur_jabatan` INT NOT NULL AUTO_INCREMENT,
            `id_periode_struktur_jabatan` INT NULL DEFAULT NULL,
            `id_jabatan` INT NULL DEFAULT NULL,
            `id_jabatan_atasan` INT NULL DEFAULT NULL,
            `periode_struktur_jabatan` VARCHAR(45) NOT NULL,
            `jabatan` VARCHAR(100) NOT NULL,
            `jabatan_atasan` VARCHAR(100) NULL DEFAULT NULL,
            `level_struktur` INT NOT NULL COMMENT '1: Menteri, 2: Es.I, 3: Es.II, 4: Es.III, 5: Es.IV',
            `urutan` INT NOT NULL DEFAULT 1,
            {$status},
            {$createdAt},
            {$updatedAt},
            {$updatedBy},
            PRIMARY KEY (`id_struktur_jabatan`),
            UNIQUE KEY `uq_struktur_jabatan` (`id_periode_struktur_jabatan`, `id_jabatan`, `id_jabatan_atasan`),
            KEY `fk_id_periode_struktur_jabatan_strukjab_to_psj` (`id_periode_struktur_jabatan`),
            KEY `fk_id_jabatan_strukjab_to_jabatan` (`id_jabatan`),
            KEY `fk_id_jabatan_atasan_strukjab_to_jabatan` (`id_jabatan_atasan`),
            CONSTRAINT `fk_id_jabatan_atasan_strukjab_to_jabatan` FOREIGN KEY (`id_jabatan_atasan`)
                REFERENCES {$this->t('jabatan')} (`id_jabatan`) {$restrict},
            CONSTRAINT `fk_id_jabatan_strukjab_to_jabatan` FOREIGN KEY (`id_jabatan`)
                REFERENCES {$this->t('jabatan')} (`id_jabatan`) {$restrict},
            CONSTRAINT `fk_id_periode_struktur_jabatan_strukjab_to_psj` FOREIGN KEY (`id_periode_struktur_jabatan`)
                REFERENCES {$this->t('periode_struktur_jabatan')} (`id_periode_struktur_jabatan`) {$restrict},
            CONSTRAINT `chk_struktur_jabatan_level_struktur` CHECK (`level_struktur` BETWEEN 1 AND 5)
        ) " . self::TABLE_OPTIONS;

        // [K] D1:3893-3912 (DoD G-02 "peta formasi"). Status legacy tanpa COMMENT → COMMENT v2. FK legacy CASCADE/SET
        // NULL → RESTRICT [V2]. Tanpa UNIQUE (keunikan legacy memakai `kebutuhan`, dokumen Bagian 4).
        $sql['peta_jabatan'] = "CREATE TABLE {$this->t('peta_jabatan')} (
            `id_peta_jabatan` INT NOT NULL AUTO_INCREMENT,
            `id_satker` INT NOT NULL,
            `id_jabatan` INT NOT NULL,
            `id_jabatan_at` INT NULL DEFAULT NULL,
            `kebutuhan` INT NOT NULL DEFAULT 0,
            `urutan` INT NOT NULL DEFAULT 1,
            {$status},
            {$createdAt},
            {$createdBy},
            {$updatedAt},
            {$updatedBy},
            PRIMARY KEY (`id_peta_jabatan`),
            KEY `fk_peta_jabatan_01` (`id_jabatan`),
            KEY `fk_peta_jabatan_03` (`id_satker`),
            KEY `fk_peta_jabatan_02` (`id_jabatan_at`),
            CONSTRAINT `fk_peta_jabatan_01` FOREIGN KEY (`id_jabatan`)
                REFERENCES {$this->t('jabatan')} (`id_jabatan`) {$restrict},
            CONSTRAINT `fk_peta_jabatan_02` FOREIGN KEY (`id_jabatan_at`)
                REFERENCES {$this->t('jabatan')} (`id_jabatan`) {$restrict},
            CONSTRAINT `fk_peta_jabatan_03` FOREIGN KEY (`id_satker`)
                REFERENCES {$this->t('satker')} (`id_satker`) {$restrict},
            CONSTRAINT `chk_peta_jabatan_kebutuhan` CHECK (`kebutuhan` >= 0)
        ) " . self::TABLE_OPTIONS;

        // [K] D1:1510-1545. 7 FK legacy (termasuk ke diri sendiri) → RESTRICT [V2]. `updated_at` NOT NULL dengan default
        // [K] (beda dari tabel lain). `old_id_*`, `id_rmj`, `nip` = kolom legacy tanpa FK, disimpan apa adanya.
        $sql['jabatan_koordinasi'] = "CREATE TABLE {$this->t('jabatan_koordinasi')} (
            `id_jabatan_koordinasi` INT NOT NULL AUTO_INCREMENT,
            `old_id_jabatan` INT NULL DEFAULT NULL,
            `id_unit` INT NULL DEFAULT NULL,
            `id_satker` INT NULL DEFAULT NULL,
            `id_atasan_es_1` INT NULL DEFAULT NULL,
            `id_atasan_es_2` INT NULL DEFAULT NULL,
            `id_atasan_es_3` INT NULL DEFAULT NULL,
            `id_atasan_es_3_koord` INT NULL DEFAULT NULL,
            `old_id_atasan_es_3` INT NULL DEFAULT NULL,
            `jenis` TINYINT NOT NULL DEFAULT 1 COMMENT '1: Koordinator, 2: Sub Koordinator',
            `jabatan` VARCHAR(255) NOT NULL,
            `kelas_jabatan` TINYINT NULL DEFAULT NULL,
            `umur_pensiun` TINYINT NULL DEFAULT NULL,
            {$status},
            `id_rmj` INT NULL DEFAULT NULL,
            `nip` VARCHAR(30) NULL DEFAULT NULL,
            {$createdAt},
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            {$updatedBy},
            PRIMARY KEY (`id_jabatan_koordinasi`),
            KEY `fk_id_unit_jabkoor_to_unit` (`id_unit`),
            KEY `fk_id_satker_jabkoor_to_satker` (`id_satker`),
            KEY `fk_id_atasan_es_1_jabkoor_to_jab` (`id_atasan_es_1`),
            KEY `fk_id_atasan_es_2_jabkoor_to_jab` (`id_atasan_es_2`),
            KEY `fk_id_atasan_es_3_koord_jabkoor_to_self` (`id_atasan_es_3_koord`),
            KEY `fk_id_atasan_es_3_jabkoor_to_jab` (`id_atasan_es_3`),
            KEY `fk_old_id_jabatan_jabkoor_to_jab` (`old_id_jabatan`),
            CONSTRAINT `fk_id_atasan_es_1_jabkoor_to_jab` FOREIGN KEY (`id_atasan_es_1`)
                REFERENCES {$this->t('jabatan')} (`id_jabatan`) {$restrict},
            CONSTRAINT `fk_id_atasan_es_2_jabkoor_to_jab` FOREIGN KEY (`id_atasan_es_2`)
                REFERENCES {$this->t('jabatan')} (`id_jabatan`) {$restrict},
            CONSTRAINT `fk_id_atasan_es_3_jabkoor_to_jab` FOREIGN KEY (`id_atasan_es_3`)
                REFERENCES {$this->t('jabatan')} (`id_jabatan`) {$restrict},
            CONSTRAINT `fk_id_atasan_es_3_koord_jabkoor_to_self` FOREIGN KEY (`id_atasan_es_3_koord`)
                REFERENCES {$this->t('jabatan_koordinasi')} (`id_jabatan_koordinasi`) {$restrict},
            CONSTRAINT `fk_id_satker_jabkoor_to_satker` FOREIGN KEY (`id_satker`)
                REFERENCES {$this->t('satker')} (`id_satker`) {$restrict},
            CONSTRAINT `fk_id_unit_jabkoor_to_unit` FOREIGN KEY (`id_unit`)
                REFERENCES {$this->t('unit')} (`id_unit`) {$restrict},
            CONSTRAINT `fk_old_id_jabatan_jabkoor_to_jab` FOREIGN KEY (`old_id_jabatan`)
                REFERENCES {$this->t('jabatan')} (`id_jabatan`) {$restrict},
            CONSTRAINT `chk_jabatan_koordinasi_jenis` CHECK (`jenis` IN (1, 2))
        ) " . self::TABLE_OPTIONS;

        return $sql;
    }

    /**
     * Pembersihan setelah up() gagal: drop tabel yang dibuat pada run ini, urutan terbalik (anak dulu). Kegagalan drop
     * tidak menutupi error asli (yang dilempar ulang pemanggil) — sisa tabel lalu dibersihkan manual (G-02b Bagian 6.4).
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
            throw new RuntimeException('DBV-018: koneksi database tidak mendukung prefix tabel.');
        }

        return $this->db->escapeIdentifiers($this->db->prefixTable($table));
    }

    private function exec(string $sql): void
    {
        // Dengan DBDebug = false query() mengembalikan false tanpa exception: jangan biarkan DDL gagal diam-diam.
        if ($this->db->query($sql) === false) {
            $error = $this->db->error();

            throw new RuntimeException('DBV-018: DDL G-02 sisa gagal (' . $error['code'] . '): ' . $error['message']);
        }
    }
}
