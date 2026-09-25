<?php

declare(strict_types=1);

namespace Tests\Support\Database\Migrations;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Migration;
use RuntimeException;

/**
 * Tabel master UJI — KHUSUS test engine master generik (CR-009), namespace Tests\Support: tidak pernah dijalankan di
 * Dev/Prod dan bukan master grup mana pun. Didaftarkan ke engine hanya di test lewat Tests\Support\Config\MasterDataUji
 * (config tiruan), untuk menguji fitur engine yang belum dipakai master sungguhan:
 *   - uji_level   : mode urutan manual (order TINYINT = level, tidak digeser), filter options `kategori`
 *   - uji_diklat  : orderScope `jenis` (urutan per jenis), nama unik per jenis
 *   - uji_bidang  : induk uji_jurusan
 *   - uji_jurusan : statusChain, uniqueFields (`singkat` global, `kode_lama` per bidang), boolean (flag_d3/flag_s1),
 *                   batas angka (`bobot` TINYINT, `kuota` INT UNSIGNED)
 *   - uji_kantor  : field ref ke provinsi & kabupaten-kota (dependsOn)
 *   - uji_dusun   : statusChain 5 level di bawah wilayah (kelurahan → … → provinsi)
 * Collation utf8mb4_unicode_ci seperti tabel master (kolom kode wilayah dibandingkan dengan tabel wilayah).
 */
class CreateMasterUjiTables extends Migration
{
    private const TABLE_OPTIONS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    private const TABLES = ['uji_dusun', 'uji_kantor', 'uji_jurusan', 'uji_bidang', 'uji_diklat', 'uji_level'];

    public function up(): void
    {
        $options = self::TABLE_OPTIONS;
        $audit   = '`updated_at` DATETIME NULL, `updated_by` INT NULL';

        $this->exec("CREATE TABLE {$this->t('uji_level')} (
            `id_level` TINYINT NOT NULL AUTO_INCREMENT,
            `level` VARCHAR(50) NOT NULL,
            `kategori` TINYINT NOT NULL DEFAULT 2,
            `order` TINYINT NOT NULL DEFAULT 1,
            `status` TINYINT NOT NULL DEFAULT 1,
            {$audit},
            PRIMARY KEY (`id_level`),
            UNIQUE KEY `uq_uji_level_nama` (`level`)
        ) {$options}");

        $this->exec("CREATE TABLE {$this->t('uji_diklat')} (
            `id_diklat` TINYINT NOT NULL AUTO_INCREMENT,
            `jenis` TINYINT NOT NULL DEFAULT 1,
            `diklat` VARCHAR(100) NOT NULL,
            `order` TINYINT NOT NULL DEFAULT 1,
            `status` TINYINT NOT NULL DEFAULT 1,
            {$audit},
            PRIMARY KEY (`id_diklat`),
            UNIQUE KEY `uq_uji_diklat_nama` (`jenis`, `diklat`)
        ) {$options}");

        $this->exec("CREATE TABLE {$this->t('uji_bidang')} (
            `id_bidang` INT NOT NULL AUTO_INCREMENT,
            `bidang` VARCHAR(100) NOT NULL,
            `order` INT NOT NULL DEFAULT 1,
            `status` TINYINT NOT NULL DEFAULT 1,
            {$audit},
            PRIMARY KEY (`id_bidang`),
            UNIQUE KEY `uq_uji_bidang_nama` (`bidang`)
        ) {$options}");

        $this->exec("CREATE TABLE {$this->t('uji_jurusan')} (
            `id_jurusan` INT NOT NULL AUTO_INCREMENT,
            `id_bidang` INT NOT NULL,
            `jurusan` VARCHAR(100) NOT NULL,
            `singkat` VARCHAR(10) NULL,
            `kode_lama` INT NULL,
            `flag_d3` TINYINT NOT NULL DEFAULT 0,
            `flag_s1` TINYINT NOT NULL DEFAULT 0,
            `bobot` TINYINT NULL,
            `kuota` INT UNSIGNED NULL,
            `order` INT NOT NULL DEFAULT 1,
            `status` TINYINT NOT NULL DEFAULT 1,
            {$audit},
            PRIMARY KEY (`id_jurusan`),
            UNIQUE KEY `uq_uji_jurusan_nama` (`id_bidang`, `jurusan`),
            UNIQUE KEY `uq_uji_jurusan_singkat` (`singkat`),
            UNIQUE KEY `uq_uji_jurusan_kode_lama` (`id_bidang`, `kode_lama`)
        ) {$options}");

        $this->exec("CREATE TABLE {$this->t('uji_kantor')} (
            `id_kantor` INT NOT NULL AUTO_INCREMENT,
            `kantor` VARCHAR(100) NOT NULL,
            `id_provinsi` CHAR(2) NULL,
            `id_kabupaten` CHAR(4) NULL,
            `order` INT NOT NULL DEFAULT 1,
            `status` TINYINT NOT NULL DEFAULT 1,
            {$audit},
            PRIMARY KEY (`id_kantor`),
            UNIQUE KEY `uq_uji_kantor_nama` (`kantor`)
        ) {$options}");

        $this->exec("CREATE TABLE {$this->t('uji_dusun')} (
            `id_dusun` INT NOT NULL AUTO_INCREMENT,
            `id_kelurahan` CHAR(10) NOT NULL,
            `dusun` VARCHAR(100) NOT NULL,
            `order` INT NOT NULL DEFAULT 1,
            `status` TINYINT NOT NULL DEFAULT 1,
            {$audit},
            PRIMARY KEY (`id_dusun`),
            UNIQUE KEY `uq_uji_dusun_nama` (`id_kelurahan`, `dusun`)
        ) {$options}");
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            $this->exec("DROP TABLE IF EXISTS {$this->t($table)}");
        }
    }

    private function t(string $table): string
    {
        if (! $this->db instanceof BaseConnection) {
            throw new RuntimeException('Koneksi database tidak mendukung prefix tabel.');
        }

        return $this->db->escapeIdentifiers($this->db->prefixTable($table));
    }

    private function exec(string $sql): void
    {
        if ($this->db->query($sql) === false) {
            $error = $this->db->error();

            throw new RuntimeException('DDL tabel master uji gagal (' . $error['code'] . '): ' . $error['message']);
        }
    }
}
