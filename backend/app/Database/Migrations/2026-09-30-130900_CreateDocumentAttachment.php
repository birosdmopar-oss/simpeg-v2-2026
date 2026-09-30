<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Migration;
use RuntimeException;

/**
 * DBV-013 — B-02 (kelompok 4): lampiran riwayat `document_attachment` (dipakai B-18 upload lampiran dan arsip Fase 7)
 * dengan skema SIMPEG legacy. Review DB Validator:
 * backend/docs/db-review/B-01-B-02-pegawai-riwayat-schema.md (Bagian 2.4) — JANGAN dijalankan di Dev/Production sebelum disetujui DBV-013.
 *
 * Sumber: DDL produksi `simpeg_prod.sql:497-519` [K] persis — kolom (termasuk nama `NIP` huruf besar), tipe, NULL,
 * default, KEY `fk_NIP_da_to_pegawai` dan `id_riwayat_id_entri`, nama FK `fk_NIP_da_to_pegawai`. Kolom per-kolom
 * `CHARACTER SET … COLLATE utf8mb4_unicode_ci` di DDL legacy = collation tabel, jadi diwarisi dari tabel.
 *
 * Kolom polimorfik legacy (TIDAK ada kolom `id_parent`/`jenis_rwy` seperti ditulis Mapping/Tech Spec/B-18):
 *   - `id_riwayat` INT = kode jenis lampiran (`ARSIP_RWY`, `config/constants.php:193-214`), kini lookup `jenis_rwy`
 *     (migration 130000); 0 = arsip belum ditautkan ke riwayat.
 *   - `id_entri` VARCHAR(100) = id baris di tabel `jenis_rwy.tabel_entri` (NULL untuk kode 37/38). Tanpa FK (satu kolom
 *     merujuk banyak tabel); integritas dijaga service B-18.
 *
 * Deviasi dari legacy (dicatat untuk DBV):
 *   - FK `fk_NIP_da_to_pegawai` CASCADE/CASCADE → RESTRICT/RESTRICT (K1: ganti NIP lewat B-06, hapus pegawai tidak
 *     menghapus lampiran diam-diam).
 *   - FK baru [V2] `fk_id_riwayat_da_to_jenis_rwy` (`id_riwayat` → `jenis_rwy.id_jenis_rwy`) RESTRICT/RESTRICT, memakai
 *     KEY legacy `id_riwayat_id_entri` (kolom terdepan = `id_riwayat`) sehingga tidak ada KEY baru.
 *   - Trigger arsip hapus → `da_deleted` [K-m] (`simpeg_prod.sql:350-368`) tidak ditiru: jejak lewat `audit_logs`.
 *
 * DDL MySQL/MariaDB ter-commit per statement dan migration yang gagal tidak tercatat di tabel `migrations`: bila CREATE
 * gagal tidak ada tabel yang tertinggal; down() men-drop tabel ini saja.
 */
class CreateDocumentAttachment extends Migration
{
    private const TABLE_OPTIONS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    private const RESTRICT = 'ON DELETE RESTRICT ON UPDATE RESTRICT';

    private const TABLE = 'document_attachment';

    /**
     * Satu CREATE TABLE: bila gagal, tabel tidak terbentuk sehingga tidak perlu pembersihan (dropCreated) seperti
     * migration multi-tabel.
     */
    public function up(): void
    {
        $this->exec($this->createSql());
    }

    public function down(): void
    {
        $this->exec("DROP TABLE IF EXISTS {$this->t(self::TABLE)}");
    }

    /**
     * [K] simpeg_prod.sql:497-519 persis + FK [V2] ke jenis_rwy. Tanpa nilai AUTO_INCREMENT awal (legacy 169499):
     * impor memakai ID legacy apa adanya lalu counter disesuaikan (dokumen B-02 6.5).
     */
    private function createSql(): string
    {
        return "CREATE TABLE {$this->t(self::TABLE)} (
            `id_attachment` INT NOT NULL AUTO_INCREMENT,
            `NIP` VARCHAR(30) NOT NULL,
            `document_id` INT NULL DEFAULT NULL,
            `filename` VARCHAR(200) NULL DEFAULT NULL,
            `id_riwayat` INT NULL DEFAULT NULL,
            `nama_riwayat` VARCHAR(255) NULL DEFAULT NULL,
            `id_entri` VARCHAR(100) NULL DEFAULT NULL,
            `tag` VARCHAR(255) NULL DEFAULT NULL,
            `path` VARCHAR(200) NULL DEFAULT NULL,
            `url` VARCHAR(200) NULL DEFAULT NULL,
            `basename` VARCHAR(200) NULL DEFAULT NULL,
            `display_name` VARCHAR(200) NULL DEFAULT NULL,
            `file_size` INT NULL DEFAULT NULL,
            `file_ext` VARCHAR(100) NULL DEFAULT NULL,
            `file_type` VARCHAR(100) NULL DEFAULT NULL,
            `created_at` DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id_attachment`),
            KEY `fk_NIP_da_to_pegawai` (`NIP`),
            KEY `id_riwayat_id_entri` (`id_riwayat`, `id_entri`),
            CONSTRAINT `fk_NIP_da_to_pegawai` FOREIGN KEY (`NIP`)
                REFERENCES {$this->t('pegawai')} (`nip`) " . self::RESTRICT . ",
            CONSTRAINT `fk_id_riwayat_da_to_jenis_rwy` FOREIGN KEY (`id_riwayat`)
                REFERENCES {$this->t('jenis_rwy')} (`id_jenis_rwy`) " . self::RESTRICT . '
        ) ' . self::TABLE_OPTIONS;
    }

    /**
     * Nama tabel ber-prefix (DBPrefix, mis. `t_` di database test) yang sudah di-escape untuk SQL mentah.
     */
    private function t(string $table): string
    {
        if (! $this->db instanceof BaseConnection) {
            throw new RuntimeException('DBV-013: koneksi database tidak mendukung prefix tabel.');
        }

        return $this->db->escapeIdentifiers($this->db->prefixTable($table));
    }

    private function exec(string $sql): void
    {
        // Dengan DBDebug = false query() mengembalikan false tanpa exception: jangan biarkan DDL gagal diam-diam.
        if ($this->db->query($sql) === false) {
            $error = $this->db->error();

            throw new RuntimeException('DBV-013: DDL document_attachment gagal (' . $error['code'] . '): ' . $error['message']);
        }
    }
}
