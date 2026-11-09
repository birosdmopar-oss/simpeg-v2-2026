<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Migration;
use RuntimeException;

/**
 * DBV-031 (Fase 6 Jalur A, ISSUE-056) — koreksi kolom `simapi_ch` hasil pencocokan D1 (DBV-026 §9a P8, P9, P12). Dokumen:
 * backend/docs/db-review/DBV-031-simapi-d1.md.
 *   - P8  `id_rwy` VARCHAR(50) NULL [I] → INT NULL [K D1] (NULL untuk jenis data tanpa id riwayat, mis. Data Umum);
 *   - P9  `nip` VARCHAR(30) NULL → NOT NULL [K D1] (penulis tidak mencatat akun tanpa NIP, CR-093);
 *   - P12 `field_name` TEXT → MEDIUMTEXT, `field_value` TEXT → LONGTEXT [K D1].
 *
 * Pra-cek SEBELUM ALTER (tanpa mengubah data): ada `nip` NULL/'' atau `id_rwy` bukan bilangan bulat → gagal jelas.
 * down() mengembalikan tipe DBV-026 (TEXT, VARCHAR NULL); data yang lebih panjang dari TEXT akan ditolak MySQL → gagal jelas.
 */
class AlterSimapiChD1 extends Migration
{
    public function up(): void
    {
        $t = $this->t('simapi_ch');

        $nipKosong    = (int) ($this->db->query("SELECT COUNT(*) AS n FROM {$t} WHERE `nip` IS NULL")->getRowArray()['n'] ?? 0);
        $idBukanAngka = (int) ($this->db->query("SELECT COUNT(*) AS n FROM {$t} WHERE `id_rwy` IS NOT NULL AND `id_rwy` NOT REGEXP '^-?[0-9]{1,10}$'")->getRowArray()['n'] ?? 0);

        if ($nipKosong > 0 || $idBukanAngka > 0) {
            throw new RuntimeException("DBV-031: pra-cek simapi_ch gagal (nip NULL: {$nipKosong}, id_rwy bukan angka: {$idBukanAngka}); data tidak diubah.");
        }

        $this->exec("ALTER TABLE {$t}
            MODIFY `id_rwy` INT NULL DEFAULT NULL COMMENT 'Id baris sumber; NULL untuk jenis data tanpa id riwayat [K D1]',
            MODIFY `nip` VARCHAR(30) NOT NULL COMMENT 'NIP pemilik data saat perubahan; tanpa FK (log riwayat) [K D1]',
            MODIFY `field_name` MEDIUMTEXT NULL COMMENT 'Nama kolom dipisah pipe [K D1]',
            MODIFY `field_value` LONGTEXT NULL COMMENT 'Nilai kolom dipisah pipe [K D1]'", 'ALTER simapi_ch');
    }

    public function down(): void
    {
        $this->exec("ALTER TABLE {$this->t('simapi_ch')}
            MODIFY `id_rwy` VARCHAR(50) NULL DEFAULT NULL COMMENT 'Id baris sumber (riwayat) [K-kode AE:84; tipe dicocokkan D1]',
            MODIFY `nip` VARCHAR(30) NULL DEFAULT NULL COMMENT 'NIP pemilik data saat perubahan; tanpa FK (log riwayat)',
            MODIFY `field_name` TEXT NULL COMMENT 'Nama kolom dipisah pipe [K]',
            MODIFY `field_value` TEXT NULL COMMENT 'Nilai kolom dipisah pipe [K]'", 'ALTER simapi_ch (down)');
    }

    private function t(string $table): string
    {
        if (! $this->db instanceof BaseConnection) {
            throw new RuntimeException('DBV-031: koneksi database tidak mendukung prefix tabel.');
        }

        return $this->db->escapeIdentifiers($this->db->prefixTable($table));
    }

    private function exec(string $sql, string $label): void
    {
        if ($this->db->query($sql) === false) {
            $error = $this->db->error();

            throw new RuntimeException("DBV-031: {$label} gagal (" . $error['code'] . '): ' . $error['message']);
        }
    }
}
