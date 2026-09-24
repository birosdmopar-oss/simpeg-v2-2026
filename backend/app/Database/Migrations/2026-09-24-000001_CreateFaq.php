<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Migration;
use RuntimeException;
use Throwable;

/**
 * DBV-002 — G-10 FAQ (pilot): lima tabel FAQ dengan skema SIMPEG legacy (Mapping Migrasi Prinsip #1).
 * Sumber: DDL produksi `simpeg_prod.sql:949-1030` [K] + nama FK dari ERD simpeg01. Review DB Validator:
 * backend/docs/db-review/G-10-faq-schema.md — JANGAN dijalankan di Dev/Production sebelum disetujui.
 *
 * Nilai legacy yang dipertahankan: nama tabel/kolom/index/FK, tipe kolom (termasuk `faq_topic.status` INT, D3),
 * PK AUTO_INCREMENT, PK komposit `faq_rate` & `faq_related_article`, `KEY status`, FULLTEXT `title_content_stripped`
 * (pencarian MATCH ... AGAINST), collation utf8mb4_unicode_ci.
 *
 * Deviasi dari legacy (dicatat untuk DBV):
 *   - FK ON DELETE/UPDATE RESTRICT (legacy CASCADE) — keputusan U4, seperti Batch 1: aplikasi tidak pernah hard
 *     delete (status 10 = Dihapus, bisa dipulihkan), RESTRICT menjadi lapis kedua.
 *   - `faq_article.order` INT NOT NULL DEFAULT 1 (kolom v2, D4 / Keputusan #5 G-01).
 *   - UNIQUE nama per induk (D5), berlaku juga untuk baris status 2/10, case-insensitive lewat collation.
 *   - Status v2 1 Aktif / 2 Tidak Aktif / 10 Dihapus (COMMENT disesuaikan).
 *   - `faq_rate.nip` tanpa FK ke `pegawai` (tabel belum ada, D2): FK `fk_nip_faqrate_to_peg` ditambahkan lewat
 *     migration baru di B-01 (Fase 3); KEY dengan nama legacy tetap dibuat.
 *   - `faq_related_article` dibuat persis DDL legacy (D1) tanpa API/UI.
 *
 * Kolom audit `created_at`/`created_by`/`updated_at`/`updated_by` diisi aplikasi (waktu UTC, id_pengguna aktor);
 * default DB hanya cadangan untuk penulisan di luar aplikasi. Nilai AUTO_INCREMENT awal tidak ditulis (impor ID
 * eksplisit menaikkan counter otomatis).
 *
 * DDL MySQL/MariaDB ter-commit per statement dan migration yang gagal tidak tercatat di tabel `migrations`: bila salah
 * satu CREATE gagal, up() men-drop tabel yang sempat dibuat PADA RUN ITU (urutan terbalik) lalu melempar ulang error,
 * sehingga `php spark migrate` bisa langsung diulang. Tabel yang sudah ada sebelum run tidak disentuh.
 */
class CreateFaq extends Migration
{
    private const STATUS_COMMENT = '1: Aktif, 2: Tidak Aktif, 10: Dihapus';

    private const TABLE_OPTIONS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    /**
     * Urutan anak → induk untuk down().
     */
    private const TABLES = ['faq_related_article', 'faq_rate', 'faq_article', 'faq_sub_topic', 'faq_topic'];

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

        $sql['faq_topic'] = "CREATE TABLE {$this->t('faq_topic')} (
            `id_faq_topic` INT NOT NULL AUTO_INCREMENT,
            `faq_topic` VARCHAR(255) NOT NULL,
            `icon` VARCHAR(255) NULL DEFAULT NULL,
            `order` INT NOT NULL DEFAULT 1,
            `status` INT NOT NULL DEFAULT 1 {$status},
            `remark` TINYTEXT NULL,
            {$audit},
            PRIMARY KEY (`id_faq_topic`),
            UNIQUE KEY `uq_faq_topic_nama` (`faq_topic`)
        ) " . self::TABLE_OPTIONS;

        $sql['faq_sub_topic'] = "CREATE TABLE {$this->t('faq_sub_topic')} (
            `id_faq_sub_topic` INT NOT NULL AUTO_INCREMENT,
            `id_faq_topic` INT NOT NULL,
            `faq_sub_topic` VARCHAR(255) NOT NULL,
            `order` INT NOT NULL DEFAULT 1,
            `status` TINYINT NOT NULL DEFAULT 1 {$status},
            `remark` TINYTEXT NULL,
            {$audit},
            PRIMARY KEY (`id_faq_sub_topic`),
            UNIQUE KEY `uq_faq_sub_topic_nama` (`id_faq_topic`, `faq_sub_topic`),
            KEY `fk_id_faq_topic_faqst_to_faqto` (`id_faq_topic`),
            CONSTRAINT `fk_id_faq_topic_faqst_to_faqto` FOREIGN KEY (`id_faq_topic`)
                REFERENCES {$this->t('faq_topic')} (`id_faq_topic`) ON DELETE RESTRICT ON UPDATE RESTRICT
        ) " . self::TABLE_OPTIONS;

        // UNIQUE uq_faq_article_nama sudah diawali id_faq_sub_topic (KEY FK secara teknis berlebih); KEY FK tetap
        // dibuat dengan nama legacy agar DDL sebanding dengan produksi.
        $sql['faq_article'] = "CREATE TABLE {$this->t('faq_article')} (
            `id_faq_article` INT NOT NULL AUTO_INCREMENT,
            `id_faq_sub_topic` INT NOT NULL,
            `title` VARCHAR(255) NOT NULL,
            `content` LONGTEXT NOT NULL,
            `content_stripped` LONGTEXT NOT NULL,
            `order` INT NOT NULL DEFAULT 1,
            `status` TINYINT NOT NULL DEFAULT 1 {$status},
            {$audit},
            PRIMARY KEY (`id_faq_article`),
            UNIQUE KEY `uq_faq_article_nama` (`id_faq_sub_topic`, `title`),
            KEY `fk_id_faq_sub_topic_faqar_to_faqst` (`id_faq_sub_topic`),
            KEY `status` (`status`),
            FULLTEXT KEY `title_content_stripped` (`title`, `content_stripped`),
            CONSTRAINT `fk_id_faq_sub_topic_faqar_to_faqst` FOREIGN KEY (`id_faq_sub_topic`)
                REFERENCES {$this->t('faq_sub_topic')} (`id_faq_sub_topic`) ON DELETE RESTRICT ON UPDATE RESTRICT
        ) " . self::TABLE_OPTIONS;

        // nip = nip pegawai penilai (JWT sub). FK ke pegawai.nip menyusul di B-01 (Fase 3), KEY legacy tetap dibuat.
        $sql['faq_rate'] = "CREATE TABLE {$this->t('faq_rate')} (
            `id_faq_article` INT NOT NULL,
            `nip` VARCHAR(30) NOT NULL,
            `rate` TINYINT NOT NULL COMMENT '1: Membantu, 2: Kurang Membantu',
            `reason` TINYTEXT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `created_by` INT NULL DEFAULT NULL COMMENT 'id_pengguna pembuat',
            PRIMARY KEY (`id_faq_article`, `nip`),
            KEY `fk_nip_faqrate_to_peg` (`nip`),
            CONSTRAINT `fk_id_faq_article_faqrate_to_faqar` FOREIGN KEY (`id_faq_article`)
                REFERENCES {$this->t('faq_article')} (`id_faq_article`) ON DELETE RESTRICT ON UPDATE RESTRICT
        ) " . self::TABLE_OPTIONS;

        $sql['faq_related_article'] = "CREATE TABLE {$this->t('faq_related_article')} (
            `id_article_main` INT NOT NULL,
            `id_article_related` INT NOT NULL,
            PRIMARY KEY (`id_article_main`, `id_article_related`),
            KEY `fk_id_article_related_faqrel_to_faqar` (`id_article_related`),
            CONSTRAINT `fk_id_article_main_faqrel_to_faqar` FOREIGN KEY (`id_article_main`)
                REFERENCES {$this->t('faq_article')} (`id_faq_article`) ON DELETE RESTRICT ON UPDATE RESTRICT,
            CONSTRAINT `fk_id_article_related_faqrel_to_faqar` FOREIGN KEY (`id_article_related`)
                REFERENCES {$this->t('faq_article')} (`id_faq_article`) ON DELETE RESTRICT ON UPDATE RESTRICT
        ) " . self::TABLE_OPTIONS;

        return $sql;
    }

    /**
     * Pembersihan setelah up() gagal: drop tabel yang dibuat pada run ini, urutan terbalik (anak dulu). Kegagalan
     * drop tidak menutupi error asli (yang dilempar ulang pemanggil) — sisa tabel lalu dibersihkan manual (G-10
     * Bagian 6.3, paragraf pemulihan).
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
     * Kolom audit legacy FAQ (created_at, created_by, updated_at ON UPDATE, updated_by). created_by/updated_by berisi
     * id_pengguna aktor, tanpa FK (preseden DBV-001).
     */
    private function auditColumnsSql(): string
    {
        return '`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, '
            . "`created_by` INT NULL DEFAULT NULL COMMENT 'id_pengguna pembuat', "
            . '`updated_at` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP, '
            . "`updated_by` INT NULL DEFAULT NULL COMMENT 'id_pengguna yang terakhir mengubah'";
    }

    /**
     * Nama tabel ber-prefix (DBPrefix, mis. `t_` di database test) yang sudah di-escape untuk SQL mentah.
     */
    private function t(string $table): string
    {
        if (! $this->db instanceof BaseConnection) {
            throw new RuntimeException('DBV-002: koneksi database tidak mendukung prefix tabel.');
        }

        return $this->db->escapeIdentifiers($this->db->prefixTable($table));
    }

    private function exec(string $sql): void
    {
        // Dengan DBDebug = false query() mengembalikan false tanpa exception: jangan biarkan DDL gagal diam-diam.
        if ($this->db->query($sql) === false) {
            $error = $this->db->error();

            throw new RuntimeException('DBV-002: DDL FAQ gagal (' . $error['code'] . '): ' . $error['message']);
        }
    }
}
