<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Migration;
use RuntimeException;
use Throwable;

/**
 * DBV-022 (7/9) — disetujui DB Validator 27-10-2026; dipindah ke app/Database/Migrations di M2 (DDL tidak berubah).
 * Isi = usulan coder; belum direview DB Validator.
 *
 * Kelompok F — petugas layanan (PIC) dan aturan petugas approval, dari dump struktur produksi D1 [K]:
 *   `petugas_layanan` D1:3952-3962, `pa_layanan` D1:2429-2471.
 * Prasyarat: `jenis_layanan`, `jenis_pa` (100000); master `jenis_pegawai` (TINYINT), `group_jabatan`,
 * `sub_group_jabatan`, `unit`, `satker`, `jabatan`, `jabatan_koordinasi` (INT) sudah ada di `main` (G-02/DBV-019).
 *
 * Kolom = D1 apa adanya kecuali deviasi (dokumen Bagian 2.16, 2.17):
 *   - `petugas_layanan.nip` VARCHAR(50) → VARCHAR(30) (V4); FK ke `pegawai.nip` DITAHAN di AddFkNipLayananFase4.php
 *     (DQ-12). `id_jenis_layanan` NULL → NOT NULL + FK RESTRICT (DQ-27; prasyarat audit NULL Bagian 6.5 = 0).
 *     UNIQUE `(id_jenis_layanan, nip)` (DQ-12; prasyarat audit duplikat = 0). CHECK `status` (0,1,10): 0/1 [K], 10
 *     disertakan karena kode legacy membaca `status != '10'` — DBV boleh mempersempit setelah audit.
 *   - `pa_layanan`: collation 0900_ai_ci → unicode_ci (G2); 11 FK SET NULL/CASCADE → RESTRICT/RESTRICT dengan nama
 *     legacy (V1); tipe kolom FK = PK master v2 (`id_jenis_pa`/`id_jenis_pegawai` TINYINT, sisanya INT — sudah sama
 *     dengan D1); `nip` VARCHAR(50) → VARCHAR(30), FK DITAHAN (DQ-17); CHECK `status` (1,2,10). Hapus fisik + audit
 *     [K] (PRD §6.4.8). `ROW_FORMAT=DYNAMIC` tidak ditulis (G2).
 *
 * DDL ter-commit per statement: bila CREATE kedua gagal, up() men-drop tabel yang sempat dibuat PADA RUN ITU lalu
 * melempar ulang error. down() men-drop kedua tabel.
 */
class CreatePetugasPaLayananFase4 extends Migration
{
    public const TABLES = ['petugas_layanan', 'pa_layanan'];

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
            'petugas_layanan' => "CREATE TABLE {$this->t('petugas_layanan')} (
                `id_petugas_layanan` INT NOT NULL AUTO_INCREMENT,
                `id_jenis_layanan` INT NOT NULL,
                `nip` VARCHAR(30) NOT NULL,
                `status` INT NOT NULL DEFAULT 1 COMMENT '0: Inactive, 1: Active',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id_petugas_layanan`),
                UNIQUE KEY `uq_petugas_layanan_jenis_nip` (`id_jenis_layanan`, `nip`),
                KEY `id_jenis_layanan_nip_status` (`id_jenis_layanan`, `nip`, `status`),
                KEY `nip` (`nip`),
                CONSTRAINT `fk_id_jenis_layanan_pl_jl` FOREIGN KEY (`id_jenis_layanan`) REFERENCES {$this->t('jenis_layanan')} (`id_jenis_layanan`) " . self::RESTRICT . ',
                CONSTRAINT `chk_petugas_layanan_status` CHECK (`status` IN (0, 1, 10))
            ) ' . self::TABLE_OPTIONS,
            'pa_layanan' => "CREATE TABLE {$this->t('pa_layanan')} (
                `id_pa_layanan` INT NOT NULL AUTO_INCREMENT,
                `id_jenis_pa` TINYINT NULL DEFAULT NULL,
                `id_jenis_pegawai` TINYINT NULL DEFAULT NULL,
                `id_group_jabatan_pemohon` INT NULL DEFAULT NULL,
                `id_sub_group_jabatan_pemohon` INT NULL DEFAULT NULL,
                `gol_ruang_pemohon` VARCHAR(20) NULL DEFAULT NULL,
                `id_unit_pemohon` INT NULL DEFAULT NULL,
                `id_group_jabatan_petugas` INT NULL DEFAULT NULL,
                `id_sub_group_jabatan_petugas` INT NULL DEFAULT NULL,
                `id_unit_petugas` INT NULL DEFAULT NULL,
                `id_satker_petugas` INT NULL DEFAULT NULL,
                `id_jabatan` INT NULL DEFAULT NULL,
                `id_jabatan_koord` INT NULL DEFAULT NULL,
                `nip` VARCHAR(30) NULL DEFAULT NULL COMMENT 'Only For Non Struktural / Non Koordinator, Ex: JFT, JF',
                `status` TINYINT NOT NULL DEFAULT 1 COMMENT '1: Active, 2: Not Active, 10: Dihapus',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                `updated_by` INT NULL DEFAULT NULL,
                PRIMARY KEY (`id_pa_layanan`),
                KEY `fk_id_jenis_pa_pa_layanan_to_jenis_pa` (`id_jenis_pa`),
                KEY `fk_id_unit_pemohon_pa_layanan_to_unit` (`id_unit_pemohon`),
                KEY `fk_id_group_jabatan_pemohon_pa_layanan_to_gj` (`id_group_jabatan_pemohon`),
                KEY `fk_id_sub_group_jabatan_pemohon_pa_layanan_to_sgj` (`id_sub_group_jabatan_pemohon`),
                KEY `fk_id_unit_petugas_pa_layanan_to_unit` (`id_unit_petugas`),
                KEY `fk_id_satker_petugas_pa_layanan_to_satker` (`id_satker_petugas`),
                KEY `fk_id_group_jabatan_petugas_pa_layanan_to_gj` (`id_group_jabatan_petugas`),
                KEY `fk_id_sub_group_jabatan_petugas_pa_layanan_to_sgj` (`id_sub_group_jabatan_petugas`),
                KEY `fk_id_jabatan_pa_layanan_to_jabatan` (`id_jabatan`),
                KEY `fk_id_jabatan_koord_pal_to_jabkoord` (`id_jabatan_koord`),
                KEY `pa_layanan_ibfk_01` (`id_jenis_pegawai`),
                KEY `nip` (`nip`),
                CONSTRAINT `fk_id_group_jabatan_pemohon_pa_layanan_to_gj` FOREIGN KEY (`id_group_jabatan_pemohon`) REFERENCES {$this->t('group_jabatan')} (`id_group_jabatan`) " . self::RESTRICT . ",
                CONSTRAINT `fk_id_group_jabatan_petugas_pa_layanan_to_gj` FOREIGN KEY (`id_group_jabatan_petugas`) REFERENCES {$this->t('group_jabatan')} (`id_group_jabatan`) " . self::RESTRICT . ",
                CONSTRAINT `fk_id_jabatan_koord_pal_to_jabkoord` FOREIGN KEY (`id_jabatan_koord`) REFERENCES {$this->t('jabatan_koordinasi')} (`id_jabatan_koordinasi`) " . self::RESTRICT . ",
                CONSTRAINT `fk_id_jabatan_pa_layanan_to_jabatan` FOREIGN KEY (`id_jabatan`) REFERENCES {$this->t('jabatan')} (`id_jabatan`) " . self::RESTRICT . ",
                CONSTRAINT `fk_id_jenis_pa_pa_layanan_to_jenis_pa` FOREIGN KEY (`id_jenis_pa`) REFERENCES {$this->t('jenis_pa')} (`id_jenis_pa`) " . self::RESTRICT . ",
                CONSTRAINT `fk_id_satker_petugas_pa_layanan_to_satker` FOREIGN KEY (`id_satker_petugas`) REFERENCES {$this->t('satker')} (`id_satker`) " . self::RESTRICT . ",
                CONSTRAINT `fk_id_sub_group_jabatan_pemohon_pa_layanan_to_sgj` FOREIGN KEY (`id_sub_group_jabatan_pemohon`) REFERENCES {$this->t('sub_group_jabatan')} (`id_sub_group_jabatan`) " . self::RESTRICT . ",
                CONSTRAINT `fk_id_sub_group_jabatan_petugas_pa_layanan_to_sgj` FOREIGN KEY (`id_sub_group_jabatan_petugas`) REFERENCES {$this->t('sub_group_jabatan')} (`id_sub_group_jabatan`) " . self::RESTRICT . ",
                CONSTRAINT `fk_id_unit_pemohon_pa_layanan_to_unit` FOREIGN KEY (`id_unit_pemohon`) REFERENCES {$this->t('unit')} (`id_unit`) " . self::RESTRICT . ",
                CONSTRAINT `fk_id_unit_petugas_pa_layanan_to_unit` FOREIGN KEY (`id_unit_petugas`) REFERENCES {$this->t('unit')} (`id_unit`) " . self::RESTRICT . ",
                CONSTRAINT `pa_layanan_ibfk_01` FOREIGN KEY (`id_jenis_pegawai`) REFERENCES {$this->t('jenis_pegawai')} (`id_jenis_pegawai`) " . self::RESTRICT . ',
                CONSTRAINT `chk_pa_layanan_status` CHECK (`status` IN (1, 2, 10))
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
