<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Migration;
use RuntimeException;
use Throwable;

/**
 * DBV-004 — G-05 Pendidikan: `jenjang_pendidikan`, `bidang_pendidikan`, `jurusan_pendidikan` dengan skema SIMPEG
 * legacy (Mapping Prinsip #1). Review DB Validator: backend/docs/db-review/G-04-G-05-pangkat-pendidikan-schema.md —
 * JANGAN dijalankan di Dev/Production sebelum disetujui DBV-004.
 *
 * Sumber [K]:
 *   - `bidang_pendidikan`: DDL produksi `simpeg_prod.sql:257-265` (+ kolom `order` v2).
 *   - Nama FK `fk_id_bidang_pendidikan_jpend_to_bpend` (jurusan → bidang): ERD `simpeg01.erd`.
 *   - Nama kolom `jenjang_pendidikan` dan `jurusan_pendidikan`: kode legacy (`Lm_pendidikan.php:204-213`,
 *     `:592-607`; `row_jurusan` dibaca `Local.php:83`, `bobot_ipasn` dibaca `L_user.php:878-884`). Flag D_I..S_3
 *     bernilai 1/0 (`Lm_pendidikan.php:599-605`). ID jurusan ≥ 1185 (`L_pendidikan.php:829`).
 * DDL `jenjang_pendidikan` dan `jurusan_pendidikan` tidak ada di dump produksi: tipe PK INT, panjang kolom teks, tipe
 * flag/`order`/`bobot_ipasn`, dan kolom audit adalah nilai [I] (G-doc Bagian 7).
 *
 * Deviasi dari legacy (dicatat untuk DBV):
 *   - UNIQUE `uq_jenjang_pendidikan_nama`, `uq_jenjang_pendidikan_singkat` (kode legacy membandingkan string
 *     singkatan), `uq_bidang_pendidikan_nama`, `uq_jurusan_pendidikan_nama (id_bidang_pendidikan, jurusan_pendidikan)`;
 *     berlaku juga untuk status 2/10, case-insensitive lewat collation. Legacy hanya mengecek di aplikasi (jurusan
 *     tidak sama sekali) — audit duplikat wajib sebelum impor.
 *   - CHECK `chk_jenjang_pendidikan_row_jurusan`: NULL atau persis salah satu nama kolom flag (perbandingan biner:
 *     peka huruf dan spasi di akhir ikut dihitung). Nilai ini menentukan kolom flag yang dibaca dropdown jurusan.
 *   - `order` INT NOT NULL DEFAULT 1 di `bidang_pendidikan` dan `jurusan_pendidikan` (Keputusan #5 G-01; impor diisi
 *     urut nama ASC seperti dropdown legacy).
 *   - FK jurusan → bidang ON DELETE RESTRICT ON UPDATE RESTRICT, nama legacy (aksi legacy tidak diketahui).
 *   - Kolom audit `jenjang_pendidikan` dan `jurusan_pendidikan` = pola `bidang_pendidikan` [K] (`updated_at` NOT NULL
 *     ON UPDATE + `updated_by`, tanpa created_*) [I]; satu library legacy (`Lm_pendidikan.php`) dengan `bidang_pendidikan`.
 *   - Status v2 1 Aktif / 2 Tidak Aktif / 10 Dihapus (COMMENT disesuaikan; legacy bidang '1: Active, 2: Inactive,
 *     10: Deleted'); COMMENT kolom audit, flag, `row_jurusan`, `bobot_ipasn`.
 *   - `bobot_ipasn` disimpan (dibaca skor IP ASN) tetapi tidak diekspos API/FE. DEFAULT NULL (keputusan DBV, G-doc
 *     Bagian 4 #16; `simpegdev_local` DEFAULT 25): jenjang baru lewat v2 = "belum ditetapkan", bukan skor yang tidak
 *     pernah ditinjau. Legacy memakai skor hanya bila > 0 (`L_user.php:878-884`).
 *
 * Kolom audit diisi aplikasi (waktu UTC, id_pengguna aktor). Nilai AUTO_INCREMENT awal tidak ditulis (legacy bidang
 * 100 — PK TINYINT signed menyisakan ID sampai 127): impor memakai ID legacy apa adanya karena kode legacy dan tabel
 * peta SIASN meng-hard-code ID (G-doc Bagian 6.4).
 *
 * DDL MySQL/MariaDB ter-commit per statement dan migration yang gagal tidak tercatat di tabel `migrations`: bila salah
 * satu CREATE gagal, up() men-drop tabel yang sempat dibuat PADA RUN ITU (urutan terbalik, anak dulu) lalu melempar
 * ulang error, sehingga `php spark migrate` bisa langsung diulang. Tabel yang sudah ada sebelum run tidak disentuh.
 */
class CreateMasterPendidikan extends Migration
{
    private const STATUS_COMMENT = '1: Aktif, 2: Tidak Aktif, 10: Dihapus';

    private const TABLE_OPTIONS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    /**
     * Urutan anak → induk untuk down().
     */
    private const TABLES = ['jurusan_pendidikan', 'bidang_pendidikan', 'jenjang_pendidikan'];

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
        $audit  = $this->auditUpdatedSql();
        $sql    = [];

        // Kolom dari kode legacy (Lm_pendidikan.php:204-213; row_jurusan & bobot_ipasn hanya dibaca, tidak ada di form).
        // row_jurusan = nama kolom flag di jurusan_pendidikan yang dibaca dropdown jurusan untuk jenjang ini. CHECK
        // membandingkan CAST(... AS BINARY) agar nilainya persis salah satu nama kolom flag: 's_1' ditolak, dan 'S_1 '
        // juga ditolak (collation utf8mb4_bin bersifat PAD SPACE sehingga mengabaikan spasi di akhir; biner tidak).
        $sql['jenjang_pendidikan'] = "CREATE TABLE {$this->t('jenjang_pendidikan')} (
            `id_jenjang_pendidikan` INT NOT NULL AUTO_INCREMENT,
            `jenjang_pendidikan_singkat` VARCHAR(50) NOT NULL,
            `jenjang_pendidikan` VARCHAR(100) NOT NULL,
            `row_jurusan` VARCHAR(10) NULL DEFAULT NULL COMMENT 'kolom flag jenjang di jurusan_pendidikan (D_I..S_3); NULL = jenjang tanpa jurusan',
            `bobot_ipasn` INT NULL DEFAULT NULL COMMENT 'skor kualifikasi pendidikan IP ASN',
            `order` TINYINT NOT NULL DEFAULT 1,
            `status` TINYINT NOT NULL DEFAULT 1 {$status},
            {$audit},
            PRIMARY KEY (`id_jenjang_pendidikan`),
            UNIQUE KEY `uq_jenjang_pendidikan_nama` (`jenjang_pendidikan`),
            UNIQUE KEY `uq_jenjang_pendidikan_singkat` (`jenjang_pendidikan_singkat`),
            CONSTRAINT `chk_jenjang_pendidikan_row_jurusan` CHECK (`row_jurusan` IS NULL
                OR CAST(`row_jurusan` AS BINARY) IN ('D_I', 'D_II', 'D_III', 'D_IV', 'S_1', 'S_2', 'S_3'))
        ) " . self::TABLE_OPTIONS;

        // DDL [K] simpeg_prod.sql:257-265 + `order` [V2] (legacy urut bidang_pendidikan ASC).
        $sql['bidang_pendidikan'] = "CREATE TABLE {$this->t('bidang_pendidikan')} (
            `id_bidang_pendidikan` TINYINT NOT NULL AUTO_INCREMENT,
            `bidang_pendidikan` VARCHAR(100) NOT NULL,
            `bidang_pendidikan_english` VARCHAR(100) NULL DEFAULT NULL,
            `order` INT NOT NULL DEFAULT 1,
            `status` TINYINT NOT NULL DEFAULT 1 {$status},
            {$audit},
            PRIMARY KEY (`id_bidang_pendidikan`),
            UNIQUE KEY `uq_bidang_pendidikan_nama` (`bidang_pendidikan`)
        ) " . self::TABLE_OPTIONS;

        // Kolom dari kode legacy (Lm_pendidikan.php:592-607); id_bidang_pendidikan bertipe sama dengan PK induk (TINYINT
        // signed, syarat FK). Flag D_I..S_3 bernilai 1/0 seperti legacy. UNIQUE uq_jurusan_pendidikan_nama sudah diawali
        // id_bidang_pendidikan (KEY FK secara teknis berlebih); KEY FK tetap dibuat dengan nama legacy [I]: legacy jurusan
        // tanpa UNIQUE, sehingga KEY bernama FK kemungkinan ada di produksi (di dump, FK tanpa KEY senama hanya yang
        // kolomnya sudah diawali index lain); dipertahankan agar sebanding dengan produksi (preseden FAQ faq_article).
        $sql['jurusan_pendidikan'] = "CREATE TABLE {$this->t('jurusan_pendidikan')} (
            `id_jurusan_pendidikan` INT NOT NULL AUTO_INCREMENT,
            `id_bidang_pendidikan` TINYINT NOT NULL,
            `jurusan_pendidikan` VARCHAR(255) NOT NULL,
            `jurusan_pendidikan_english` VARCHAR(255) NULL DEFAULT NULL,
            `gelar` VARCHAR(50) NULL DEFAULT NULL,
            `D_I` TINYINT NOT NULL DEFAULT 0 COMMENT '1: tersedia untuk jenjang D.I, 0: tidak',
            `D_II` TINYINT NOT NULL DEFAULT 0 COMMENT '1: tersedia untuk jenjang D.II, 0: tidak',
            `D_III` TINYINT NOT NULL DEFAULT 0 COMMENT '1: tersedia untuk jenjang D.III, 0: tidak',
            `D_IV` TINYINT NOT NULL DEFAULT 0 COMMENT '1: tersedia untuk jenjang D.IV, 0: tidak',
            `S_1` TINYINT NOT NULL DEFAULT 0 COMMENT '1: tersedia untuk jenjang S.1, 0: tidak',
            `S_2` TINYINT NOT NULL DEFAULT 0 COMMENT '1: tersedia untuk jenjang S.2, 0: tidak',
            `S_3` TINYINT NOT NULL DEFAULT 0 COMMENT '1: tersedia untuk jenjang S.3, 0: tidak',
            `order` INT NOT NULL DEFAULT 1,
            `status` TINYINT NOT NULL DEFAULT 1 {$status},
            {$audit},
            PRIMARY KEY (`id_jurusan_pendidikan`),
            UNIQUE KEY `uq_jurusan_pendidikan_nama` (`id_bidang_pendidikan`, `jurusan_pendidikan`),
            KEY `fk_id_bidang_pendidikan_jpend_to_bpend` (`id_bidang_pendidikan`),
            CONSTRAINT `fk_id_bidang_pendidikan_jpend_to_bpend` FOREIGN KEY (`id_bidang_pendidikan`)
                REFERENCES {$this->t('bidang_pendidikan')} (`id_bidang_pendidikan`) ON DELETE RESTRICT ON UPDATE RESTRICT
        ) " . self::TABLE_OPTIONS;

        return $sql;
    }

    /**
     * Pembersihan setelah up() gagal: drop tabel yang dibuat pada run ini, urutan terbalik (anak dulu). Kegagalan drop
     * tidak menutupi error asli (yang dilempar ulang pemanggil) — sisa tabel lalu dibersihkan manual (G-doc Bagian 6,
     * paragraf pemulihan).
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
     * Kolom audit master yang kodenya hanya menulis updated_by (pola DDL legacy `bidang_pendidikan`,
     * simpeg_prod.sql:262-263): tanpa created_*. updated_by berisi id_pengguna aktor, tanpa FK (preseden DBV-001).
     */
    private function auditUpdatedSql(): string
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
            throw new RuntimeException('DBV-004: koneksi database tidak mendukung prefix tabel.');
        }

        return $this->db->escapeIdentifiers($this->db->prefixTable($table));
    }

    private function exec(string $sql): void
    {
        // Dengan DBDebug = false query() mengembalikan false tanpa exception: jangan biarkan DDL gagal diam-diam.
        if ($this->db->query($sql) === false) {
            $error = $this->db->error();

            throw new RuntimeException('DBV-004: DDL Pendidikan gagal (' . $error['code'] . '): ' . $error['message']);
        }
    }
}
