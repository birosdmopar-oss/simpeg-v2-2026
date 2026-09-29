<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Migration;
use RuntimeException;
use Throwable;

/**
 * DBV-004 — G-04 Kenaikan Pangkat: `pangkat`, `jenis_kp`, `gol_pppk` dengan skema SIMPEG legacy (Mapping Prinsip #1).
 * Review DB Validator: backend/docs/db-review/G-04-G-05-pangkat-pendidikan-schema.md — JANGAN dijalankan di
 * Dev/Production sebelum disetujui DBV-004.
 *
 * Sumber [K]:
 *   - `gol_pppk`: DDL produksi `simpeg_prod.sql:1277-1289` (nama, tipe, default, urutan kolom).
 *   - `pangkat.id_pangkat` TINYINT signed: `dm_ak_jf.id_pangkat tinyint` + FK `fk_dm_ak_jf_ibfk_02` → `pangkat`
 *     (`simpeg_prod.sql:459, 468`); `jenis_kp.id_jenis_kp` TINYINT: FK ERD `fk_id_jenis_kp_peg_kp_to_jenis_kp`
 *     (`simpeg01.erd`, FK mewajibkan tipe & tanda sama) + `pegawai_kp.id_jenis_kp tinyint` (simpeg_prod_duplikat).
 *   - Nama kolom `pangkat` dan `jenis_kp`: kode admin legacy (`Lm_kp.php:226-241`, `:438-449`).
 * DDL `pangkat` dan `jenis_kp` tidak ada di dump produksi (terpotong di `jabatan`): panjang kolom teks (= kolom
 * snapshot `pegawai_kp`), tipe/default `cpns` dan `order`, serta kolom audit adalah nilai [I] (G-doc Bagian 7).
 *
 * Deviasi dari legacy (dicatat untuk DBV):
 *   - `gol_pppk.status` TINYINT 1/2/10 — legacy ENUM('1','2') tidak bisa menyimpan 10 (Dihapus): strict mode mati
 *     menyimpan string kosong, strict mode hidup menolak. Deviasi wajib.
 *   - UNIQUE nama `uq_pangkat_nama (gol_ruang)`, `uq_jenis_kp_nama (jenis_kp)`, `uq_gol_pppk_nama (gol_pppk)`, berlaku
 *     juga untuk baris status 2/10, case-insensitive lewat collation. Legacy hanya mengecek di aplikasi. `gol_ruang`
 *     dipakai (bukan `pangkat`) karena nama pangkat berulang antara CPNS dan PNS, sedangkan label dropdown dan peta
 *     SIASN legacy memakai `gol_ruang` (teks bebas, tidak diturunkan server).
 *   - `pangkat.order` = level pangkat (KP berikutnya = order + 1), bukan sekadar urutan tampil: aplikasi tidak
 *     menggeser maupun menomori ulang nilainya (mode order manual). UNIQUE(cpns, order) sengaja BELUM dibuat sampai
 *     data produksi terlihat.
 *   - Kolom audit `pangkat` dan `jenis_kp` = `updated_at` NOT NULL ON UPDATE + `updated_by`, tanpa created_* [I]:
 *     tidak ada bukti library (saudara satu library `Lm_kp.php`, `gol_pppk`, justru memakai created_*); bukti legacy
 *     terbelah — pola ini di `bidang_pendidikan`/`diklat`, pola created_at + updated_at NULL di `agama`/`group_jabatan`/
 *     `jabatan`. Dipilih hanya demi keseragaman dengan jenjang/bidang/jurusan (G-doc Bagian 3 #8). `gol_pppk` memakai
 *     kolom audit DDL [K] apa adanya.
 *   - Status v2 1 Aktif / 2 Tidak Aktif / 10 Dihapus (COMMENT disesuaikan); COMMENT kolom audit dan `pangkat.order`.
 *   - Tanpa FK: tabel perujuk (riwayat KP, dm_ak_jf, dst.) dibuat di Fase 3 / DBV-008 dan wajib memakai TINYINT signed.
 *
 * Kolom audit diisi aplikasi (waktu UTC, id_pengguna aktor); default DB hanya cadangan untuk penulisan di luar
 * aplikasi. Nilai AUTO_INCREMENT awal tidak ditulis (legacy gol_pppk 19): impor memakai ID legacy apa adanya karena
 * kode legacy dan tabel peta SIASN meng-hard-code ID (G-doc Bagian 6.4).
 *
 * DDL MySQL/MariaDB ter-commit per statement dan migration yang gagal tidak tercatat di tabel `migrations`: bila salah
 * satu CREATE gagal, up() men-drop tabel yang sempat dibuat PADA RUN ITU (urutan terbalik) lalu melempar ulang error,
 * sehingga `php spark migrate` bisa langsung diulang. Tabel yang sudah ada sebelum run tidak disentuh.
 */
class CreateMasterKenaikanPangkat extends Migration
{
    private const STATUS_COMMENT = '1: Aktif, 2: Tidak Aktif, 10: Dihapus';

    private const TABLE_OPTIONS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    /**
     * Urutan down(): kebalikan urutan pembuatan (tidak ada FK di antara ketiga tabel ini).
     */
    private const TABLES = ['gol_pppk', 'jenis_kp', 'pangkat'];

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
     * CREATE TABLE per tabel.
     *
     * @return array<string, string> tabel => SQL
     */
    private function createStatements(): array
    {
        $status = "COMMENT '" . self::STATUS_COMMENT . "'";
        $audit  = $this->auditUpdatedSql();
        $sql    = [];

        // Kolom dari kode legacy (Lm_kp.php:226-241). gol_ruang = label dropdown & kunci peta SIASN ("CPNS III/b" tepat
        // 10 karakter = pegawai_kp.gol_ruang varchar(10)).
        $sql['pangkat'] = "CREATE TABLE {$this->t('pangkat')} (
            `id_pangkat` TINYINT NOT NULL AUTO_INCREMENT,
            `pangkat` VARCHAR(50) NOT NULL,
            `gol` VARCHAR(10) NOT NULL,
            `ruang` VARCHAR(10) NOT NULL,
            `gol_ruang` VARCHAR(10) NOT NULL,
            `cpns` TINYINT NOT NULL DEFAULT 2 COMMENT '1: CPNS, 2: PNS',
            `order` TINYINT NOT NULL DEFAULT 1 COMMENT 'level pangkat (KP berikutnya = order + 1), bukan sekadar urutan tampil',
            `status` TINYINT NOT NULL DEFAULT 1 {$status},
            {$audit},
            PRIMARY KEY (`id_pangkat`),
            UNIQUE KEY `uq_pangkat_nama` (`gol_ruang`)
        ) " . self::TABLE_OPTIONS;

        // Kolom dari kode legacy (Lm_kp.php:438-449).
        $sql['jenis_kp'] = "CREATE TABLE {$this->t('jenis_kp')} (
            `id_jenis_kp` TINYINT NOT NULL AUTO_INCREMENT,
            `jenis_kp` VARCHAR(100) NOT NULL,
            `order` TINYINT NOT NULL DEFAULT 1,
            `status` TINYINT NOT NULL DEFAULT 1 {$status},
            {$audit},
            PRIMARY KEY (`id_jenis_kp`),
            UNIQUE KEY `uq_jenis_kp_nama` (`jenis_kp`)
        ) " . self::TABLE_OPTIONS;

        // DDL [K] simpeg_prod.sql:1277-1289 (urutan kolom legacy); status ENUM('1','2') -> TINYINT (deviasi wajib).
        $sql['gol_pppk'] = "CREATE TABLE {$this->t('gol_pppk')} (
            `id_gol_pppk` TINYINT NOT NULL AUTO_INCREMENT,
            `gol_pppk` VARCHAR(10) NOT NULL,
            `uang_makan` DOUBLE NOT NULL DEFAULT 0,
            `order` TINYINT NOT NULL DEFAULT 0,
            `keterangan` TINYTEXT NULL,
            `status` TINYINT NOT NULL DEFAULT 1 {$status},
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            `created_by` INT NULL DEFAULT NULL COMMENT 'id_pengguna pembuat',
            `updated_by` INT NULL DEFAULT NULL COMMENT 'id_pengguna yang terakhir mengubah',
            PRIMARY KEY (`id_gol_pppk`),
            UNIQUE KEY `uq_gol_pppk_nama` (`gol_pppk`)
        ) " . self::TABLE_OPTIONS;

        return $sql;
    }

    /**
     * Pembersihan setelah up() gagal: drop tabel yang dibuat pada run ini, urutan terbalik. Kegagalan drop tidak
     * menutupi error asli (yang dilempar ulang pemanggil) — sisa tabel lalu dibersihkan manual (G-doc Bagian 6,
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
     * Kolom audit pola DDL legacy `bidang_pendidikan` (simpeg_prod.sql:262-263), tanpa created_* [I] — untuk `pangkat`/
     * `jenis_kp` hanya demi keseragaman grup (G-doc Bagian 3 #8). updated_by berisi id_pengguna aktor, tanpa FK
     * (preseden DBV-001).
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

            throw new RuntimeException('DBV-004: DDL Kenaikan Pangkat gagal (' . $error['code'] . '): ' . $error['message']);
        }
    }
}
