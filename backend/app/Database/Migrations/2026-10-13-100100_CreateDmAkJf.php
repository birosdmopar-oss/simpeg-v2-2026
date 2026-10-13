<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Migration;
use RuntimeException;

/**
 * DBV-020 (2/3) — master angka kredit kumulatif jabatan fungsional `dm_ak_jf` (B-15 AK, WS-1 MAKE-008), skema SIMPEG
 * legacy dari dump struktur produksi `simpeg01_struktur_lengkap_20261001.sql` [K] D1:457-469. Dibaca legacy
 * `libraries/hr/rwy/L_ak.php` form() :357-405 dan dikelola `libraries/hr/master/Lm_jabatan.php` :2277-2512 (tambah/ubah
 * per pasangan jabatan + pangkat; `delete_akjf()` :2421-2452 salin-tempel hapus unit yang tidak berfungsi, jadi
 * praktis tanpa hapus; `jenjang` tidak ditulis form legacy).
 * Review DB Validator: backend/docs/db-review/DBV-020-skema-tambahan-fase3.md — JANGAN dijalankan di Dev/Production
 * sebelum disetujui.
 *
 * Nilai legacy yang dipertahankan: nama tabel/kolom, urutan kolom, tipe (`id_pangkat` TINYINT = PK `pangkat` v2,
 * `id_jabatan` INT = PK `jabatan` v2, `ak` DOUBLE), default, nullability, kolom audit (tanpa `created_by`/`status`, sama
 * dengan D1), PRIMARY KEY (`id_jabatan`, `id_pangkat`), nama KEY/FK legacy.
 *
 * Deviasi [V2]: kedua FK ON DELETE RESTRICT ON UPDATE RESTRICT (legacy CASCADE/CASCADE; konvensi V1 DBV-012/013 —
 * master jabatan/pangkat dihapus lunak, hapus keras yang memutus relasi ditolak DB).
 *
 * Kode v2 `App\Libraries\Kepegawaian\Riwayat\Definisi\Ak::adaMasterAkJf()` mendeteksi tabel ini lewat
 * information_schema, jadi AK kumulatif terisi otomatis setelah migration ini jalan dan data diimpor (tanpa perubahan
 * kode).
 */
class CreateDmAkJf extends Migration
{
    public const FOREIGN_KEYS = [
        'fk_dm_ak_jf_ibfk_01' => ['id_jabatan', 'jabatan'],
        'fk_dm_ak_jf_ibfk_02' => ['id_pangkat', 'pangkat'],
    ];

    public function up(): void
    {
        $restrict = 'ON DELETE RESTRICT ON UPDATE RESTRICT';

        $this->exec("CREATE TABLE {$this->t('dm_ak_jf')} (
            `id_jabatan` INT NOT NULL,
            `id_pangkat` TINYINT NOT NULL,
            `jenjang` VARCHAR(50) NULL DEFAULT NULL,
            `ak` DOUBLE NOT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_by` INT NULL DEFAULT NULL,
            `updated_at` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id_jabatan`, `id_pangkat`),
            KEY `fk_dm_ak_jf_ibfk_02` (`id_pangkat`),
            CONSTRAINT `fk_dm_ak_jf_ibfk_01` FOREIGN KEY (`id_jabatan`)
                REFERENCES {$this->t('jabatan')} (`id_jabatan`) {$restrict},
            CONSTRAINT `fk_dm_ak_jf_ibfk_02` FOREIGN KEY (`id_pangkat`)
                REFERENCES {$this->t('pangkat')} (`id_pangkat`) {$restrict}
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public function down(): void
    {
        $this->exec("DROP TABLE IF EXISTS {$this->t('dm_ak_jf')}");
    }

    /**
     * Nama tabel ber-prefix (DBPrefix, mis. `t_` di database test) yang sudah di-escape untuk SQL mentah.
     */
    private function t(string $table): string
    {
        if (! $this->db instanceof BaseConnection) {
            throw new RuntimeException('DBV-020: koneksi database tidak mendukung prefix tabel.');
        }

        return $this->db->escapeIdentifiers($this->db->prefixTable($table));
    }

    private function exec(string $sql): void
    {
        // Dengan DBDebug = false query() mengembalikan false tanpa exception: jangan biarkan DDL gagal diam-diam.
        if ($this->db->query($sql) === false) {
            $error = $this->db->error();

            throw new RuntimeException('DBV-020: DDL dm_ak_jf gagal (' . $error['code'] . '): ' . $error['message']);
        }
    }
}
