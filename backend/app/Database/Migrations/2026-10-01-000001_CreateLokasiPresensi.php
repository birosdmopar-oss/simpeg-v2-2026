<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Migration;
use RuntimeException;
use Throwable;

/** DBV-007 — G-03 Master Lokasi Presensi. */
class CreateLokasiPresensi extends Migration
{
    private const TABLE_OPTIONS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    private const STATUS_COMMENT = '1: Aktif, 2: Tidak Aktif, 10: Dihapus';

    public function up(): void
    {
        $created = [];
        try {
            foreach ($this->statements() as $table => $sql) {
                $this->exec($sql);
                $created[] = $table;
            }
        } catch (Throwable $e) {
            foreach (array_reverse($created) as $table) {
                $this->db->query('DROP TABLE IF EXISTS ' . $this->t($table));
            }
            throw $e;
        }
    }

    public function down(): void
    {
        foreach (['dm_user_lokasi_presensi', 'lokasi_presensi'] as $table) {
            $this->exec('DROP TABLE IF EXISTS ' . $this->t($table));
        }
    }

    /** @return array<string, string> */
    private function statements(): array
    {
        $status = self::STATUS_COMMENT;
        $audit = '`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `created_by` INT NULL DEFAULT NULL COMMENT \'id_pengguna pembuat\',
            `updated_at` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            `updated_by` INT NULL DEFAULT NULL COMMENT \'id_pengguna yang terakhir mengubah\'';

        return [
            'lokasi_presensi' => "CREATE TABLE {$this->t('lokasi_presensi')} (
                `id_lokasi_presensi` INT NOT NULL AUTO_INCREMENT,
                `nama_lokasi` VARCHAR(255) NOT NULL,
                `latitude` VARCHAR(50) NOT NULL,
                `longitude` VARCHAR(50) NOT NULL,
                `radius` DOUBLE NOT NULL DEFAULT 0 COMMENT 'Meter',
                `status` TINYINT NOT NULL DEFAULT 1 COMMENT '{$status}',
                {$audit},
                PRIMARY KEY (`id_lokasi_presensi`),
                KEY `status` (`status`)
            ) " . self::TABLE_OPTIONS,
            'dm_user_lokasi_presensi' => "CREATE TABLE {$this->t('dm_user_lokasi_presensi')} (
                `id_dm_user_lokasi_presensi` INT NOT NULL AUTO_INCREMENT,
                `target_lp` MEDIUMTEXT NOT NULL,
                `target_lp_desc` MEDIUMTEXT NOT NULL,
                `target_uns` MEDIUMTEXT NULL,
                `target_uns_desc` MEDIUMTEXT NULL,
                `target_jp` MEDIUMTEXT NULL,
                `target_jp_desc` MEDIUMTEXT NULL,
                `target_peg` MEDIUMTEXT NULL,
                `target_peg_desc` MEDIUMTEXT NULL,
                `excl_peg` MEDIUMTEXT NULL,
                `excl_peg_desc` MEDIUMTEXT NULL,
                `keterangan` TEXT NULL,
                `hari_berlaku` VARCHAR(20) NULL COMMENT 'ISO 1=Senin..7=Minggu; NULL=setiap hari',
                `status` TINYINT NOT NULL DEFAULT 1 COMMENT '{$status}',
                {$audit},
                PRIMARY KEY (`id_dm_user_lokasi_presensi`),
                KEY `status` (`status`)
            ) " . self::TABLE_OPTIONS,
        ];
    }

    private function t(string $table): string
    {
        if (! $this->db instanceof BaseConnection) {
            throw new RuntimeException('DBV-007: koneksi database tidak mendukung prefix tabel.');
        }
        return $this->db->escapeIdentifiers($this->db->prefixTable($table));
    }

    private function exec(string $sql): void
    {
        if ($this->db->query($sql) === false) {
            $error = $this->db->error();
            throw new RuntimeException('DBV-007: DDL gagal (' . $error['code'] . '): ' . $error['message']);
        }
    }
}
