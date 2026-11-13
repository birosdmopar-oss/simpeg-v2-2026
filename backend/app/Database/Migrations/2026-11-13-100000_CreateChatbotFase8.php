<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Migration;
use RuntimeException;
use Throwable;

/**
 * DBV-033 (Fase 8 E-10, chatbot "Vanya", PRD Fase 8 v1.1 §7.10.1) — enam tabel chatbot mengikuti DDL DB dev hasil D1
 * chatbot 12-11-2026 [K dev D1] dengan penyesuaian [V2]. Dokumen: backend/docs/db-review/DBV-033-skema-chatbot.md.
 *
 * Tabel: `qna_status` (master status, diisi di sini: 1 Aktif, 2 Tidak Aktif, 10 Dihapus), `qna_kategori`, `qna_tag`,
 * `qna`, `qna_has_tag`, `log_chatbot`. Tanpa data QnA (isi dev = C4, tidak pernah di repo, F8-46) dan tanpa FK ke tabel
 * helpdesk/FAQ.
 *
 * Konvensi: FK RESTRICT/RESTRICT (hapus v2 = status 10, bukan hapus fisik; dev CASCADE pada `qna_has_tag`); waktu UTC
 * diisi aplikasi (BP8-8); `log_chatbot` anonim tanpa kolom identitas (K-4). Judul kategori/tag unik di antara status 1/2
 * lewat kolom bangkitan `judul_unik` (NULL bila status 10) + UNIQUE, pengaman DB untuk aturan aplikasi F8-40.
 * up() gagal → hanya tabel yang dibuat pada run itu yang di-DROP (tabel yang sudah ada tidak disentuh); down() men-DROP
 * keenam tabel (anak dulu).
 */
class CreateChatbotFase8 extends Migration
{
    /** Urutan pembuatan (induk dulu); DROP memakai urutan terbalik. */
    public const TABEL = ['qna_status', 'qna_kategori', 'qna_tag', 'qna', 'qna_has_tag', 'log_chatbot'];

    /** Isi master `qna_status` [V2, A1]; dev: 1 Aktif, 2 Nonaktif (D1 blok [2]). */
    public const STATUS = [
        1  => 'Aktif',
        2  => 'Tidak Aktif',
        10 => 'Dihapus',
    ];

    private const TABLE_OPTIONS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    private const RESTRICT = 'ON DELETE RESTRICT ON UPDATE RESTRICT';

    public function up(): void
    {
        $dibuat = [];

        try {
            foreach (self::TABEL as $tabel) {
                $this->exec($this->ddl($tabel), "CREATE TABLE {$tabel}");
                $dibuat[] = $tabel;
            }

            foreach (self::STATUS as $id => $judul) {
                if ($this->db->table('qna_status')->insert(['id_qna_status' => $id, 'judul_status' => $judul]) === false) {
                    throw new RuntimeException("DBV-033: seed qna_status id {$id} gagal: " . $this->db->error()['message']);
                }
            }
        } catch (Throwable $e) {
            $this->dropDibuat($dibuat);

            throw $e;
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::TABEL) as $tabel) {
            $this->exec('DROP TABLE IF EXISTS ' . $this->t($tabel), "DROP TABLE {$tabel}");
        }
    }

    /**
     * Pembersihan setelah up() gagal: hanya tabel yang dibuat pada run ini, urutan terbalik (anak dulu). Tabel yang sudah
     * ada sebelumnya (CREATE gagal 1050) tidak disentuh. Kegagalan DROP tidak menutupi galat asli (pola CreateFaq).
     *
     * @param list<string> $dibuat
     */
    private function dropDibuat(array $dibuat): void
    {
        foreach (array_reverse($dibuat) as $tabel) {
            try {
                $this->db->query('DROP TABLE IF EXISTS ' . $this->t($tabel));
            } catch (Throwable) {
                // Lanjut; galat asli tetap dilempar up().
            }
        }
    }

    /** Kolom audit v2 (UTC, diisi aplikasi); `qna` sudah punya keduanya di dev [K dev D1]. */
    private function kolomWaktu(string $asal): string
    {
        return "`created_at` DATETIME NULL DEFAULT NULL COMMENT 'UTC, diisi aplikasi {$asal}',
                    `updated_at` DATETIME NULL DEFAULT NULL COMMENT 'UTC, diisi aplikasi {$asal}',";
    }

    private function ddl(string $tabel): string
    {
        $r = self::RESTRICT;

        return match ($tabel) {
            'qna_status' => "CREATE TABLE {$this->t('qna_status')} (
                    `id_qna_status` INT UNSIGNED NOT NULL COMMENT 'Tanpa AUTO_INCREMENT, id baku 1/2/10 [V2] (dev AI)',
                    `judul_status` VARCHAR(50) NOT NULL COMMENT '[K dev D1]',
                    PRIMARY KEY (`id_qna_status`),
                    CONSTRAINT `chk_qna_status_id` CHECK (`id_qna_status` IN (1, 2, 10))
                ) " . self::TABLE_OPTIONS . " COMMENT='Master status chatbot: 1 Aktif, 2 Tidak Aktif, 10 Dihapus (DBV-033, A1)'",
            'qna_kategori' => "CREATE TABLE {$this->t('qna_kategori')} (
                    `id_qna_kategori` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `judul_kategori` VARCHAR(150) NOT NULL COMMENT 'Di-trim aplikasi [K dev D1]',
                    `status_qna_kategori_fk` INT UNSIGNED NOT NULL DEFAULT 1 COMMENT '1/2/10 [K dev D1] + 10 [V2]',
                    `tampil_bubble` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Tampil sebagai bubble topik [K dev D1]',
                    {$this->kolomWaktu('[V2]')}
                    `judul_unik` VARCHAR(150) COLLATE utf8mb4_unicode_ci GENERATED ALWAYS AS (IF(`status_qna_kategori_fk` <> 10, `judul_kategori`, NULL)) STORED COMMENT 'Pengaman unik judul status 1/2 (F8-40) [V2]',
                    PRIMARY KEY (`id_qna_kategori`),
                    UNIQUE KEY `uk_qna_kategori_judul_unik` (`judul_unik`),
                    KEY `status_qna_kategori_fk` (`status_qna_kategori_fk`),
                    CONSTRAINT `fk_qna_kategori_status` FOREIGN KEY (`status_qna_kategori_fk`) REFERENCES {$this->t('qna_status')} (`id_qna_status`) {$r},
                    CONSTRAINT `chk_qna_kategori_status` CHECK (`status_qna_kategori_fk` IN (1, 2, 10)),
                    CONSTRAINT `chk_qna_kategori_tampil_bubble` CHECK (`tampil_bubble` IN (0, 1))
                ) " . self::TABLE_OPTIONS . " COMMENT='Kategori QnA chatbot (E-10)'",
            'qna_tag' => "CREATE TABLE {$this->t('qna_tag')} (
                    `id_qna_tag` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `judul_tag` VARCHAR(100) NOT NULL COMMENT 'Di-trim aplikasi [K dev D1]',
                    `status_qna_tag_fk` INT UNSIGNED NOT NULL DEFAULT 1 COMMENT '1/2/10 [K dev D1] + 10 [V2]',
                    {$this->kolomWaktu('[V2]')}
                    `judul_unik` VARCHAR(100) COLLATE utf8mb4_unicode_ci GENERATED ALWAYS AS (IF(`status_qna_tag_fk` <> 10, `judul_tag`, NULL)) STORED COMMENT 'Pengaman unik judul status 1/2 (F8-40) [V2]',
                    PRIMARY KEY (`id_qna_tag`),
                    UNIQUE KEY `uk_qna_tag_judul_unik` (`judul_unik`),
                    KEY `status_qna_tag_fk` (`status_qna_tag_fk`),
                    CONSTRAINT `fk_qna_tag_status` FOREIGN KEY (`status_qna_tag_fk`) REFERENCES {$this->t('qna_status')} (`id_qna_status`) {$r},
                    CONSTRAINT `chk_qna_tag_status` CHECK (`status_qna_tag_fk` IN (1, 2, 10))
                ) " . self::TABLE_OPTIONS . " COMMENT='Tag QnA chatbot (E-10)'",
            'qna' => "CREATE TABLE {$this->t('qna')} (
                    `id_qna` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `pertanyaan` TEXT NOT NULL COMMENT '[K dev D1]',
                    `jawaban` TEXT NOT NULL COMMENT 'HTML tersanitasi saat simpan (BP8-14) [K dev D1]',
                    `id_qna_kategori_fk` INT UNSIGNED NULL DEFAULT NULL COMMENT 'NULL-able [K dev D1]; wajib di aplikasi (F8-41)',
                    `status_qna_fk` INT UNSIGNED NOT NULL DEFAULT 1 COMMENT '1/2/10 [K dev D1] + 10 [V2]',
                    `is_fallback_option` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'QnA unggulan (populer) [K dev D1]',
                    {$this->kolomWaktu('[K dev D1]')}
                    PRIMARY KEY (`id_qna`),
                    KEY `status_kategori` (`status_qna_fk`, `id_qna_kategori_fk`),
                    KEY `id_qna_kategori_fk` (`id_qna_kategori_fk`),
                    CONSTRAINT `fk_qna_kategori` FOREIGN KEY (`id_qna_kategori_fk`) REFERENCES {$this->t('qna_kategori')} (`id_qna_kategori`) {$r},
                    CONSTRAINT `fk_qna_status` FOREIGN KEY (`status_qna_fk`) REFERENCES {$this->t('qna_status')} (`id_qna_status`) {$r},
                    CONSTRAINT `chk_qna_status` CHECK (`status_qna_fk` IN (1, 2, 10)),
                    CONSTRAINT `chk_qna_is_fallback_option` CHECK (`is_fallback_option` IN (0, 1))
                ) " . self::TABLE_OPTIONS . " COMMENT='QnA chatbot (E-10)'",
            'qna_has_tag' => "CREATE TABLE {$this->t('qna_has_tag')} (
                    `qna_id` INT UNSIGNED NOT NULL COMMENT '[K dev D1]',
                    `qna_tag_id` INT UNSIGNED NOT NULL COMMENT '[K dev D1]',
                    PRIMARY KEY (`qna_id`, `qna_tag_id`) COMMENT 'PK komposit = UNIQUE relasi [V2]; dev PK id_qna_has_tag tanpa UNIQUE',
                    KEY `qna_tag_id` (`qna_tag_id`),
                    CONSTRAINT `fk_qna_has_tag_qna` FOREIGN KEY (`qna_id`) REFERENCES {$this->t('qna')} (`id_qna`) {$r},
                    CONSTRAINT `fk_qna_has_tag_tag` FOREIGN KEY (`qna_tag_id`) REFERENCES {$this->t('qna_tag')} (`id_qna_tag`) {$r}
                ) " . self::TABLE_OPTIONS . " COMMENT='Relasi QnA-tag chatbot (E-10); FK RESTRICT [V2] (dev CASCADE)'",
            'log_chatbot' => "CREATE TABLE {$this->t('log_chatbot')} (
                    `id_log` INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'UNSIGNED [V2] (dev signed)',
                    `pesan_user` TEXT NOT NULL COMMENT 'Teks ketikan atau pertanyaan QnA jalur bubble (F8-48) [K dev D1]',
                    `top_1_id_qna` INT UNSIGNED NOT NULL COMMENT 'Kandidat teratas, tanpa FK [K dev D1]; UNSIGNED [V2]',
                    `top_1_skor` DECIMAL(5,4) NOT NULL COMMENT 'Skor asli 4 desimal [V2] (dev FLOAT)',
                    `top_3_kandidat` LONGTEXT NOT NULL COMMENT 'JSON tiga kandidat [K dev D1 longtext] + CHECK JSON [V2]',
                    `status_match` TINYINT NOT NULL COMMENT '1 cocok, 0 fallback; NOT NULL [V2] (dev NULL)',
                    `feedback` TINYINT NULL DEFAULT NULL COMMENT '1 membantu, -1 tidak, NULL belum dinilai [K dev D1]',
                    `waktu_interaksi` DATETIME NOT NULL COMMENT 'UTC diisi aplikasi (BP8-8) [V2] (dev DEFAULT CURRENT_TIMESTAMP)',
                    PRIMARY KEY (`id_log`),
                    KEY `idx_log_qna_feedback` (`top_1_id_qna`, `status_match`, `feedback`),
                    KEY `idx_log_waktu` (`waktu_interaksi`),
                    CONSTRAINT `chk_log_chatbot_top_3_json` CHECK (JSON_VALID(`top_3_kandidat`)),
                    CONSTRAINT `chk_log_chatbot_status_match` CHECK (`status_match` IN (0, 1)),
                    CONSTRAINT `chk_log_chatbot_feedback` CHECK (`feedback` IS NULL OR `feedback` IN (-1, 1)),
                    CONSTRAINT `chk_log_chatbot_skor` CHECK (`top_1_skor` >= 0 AND `top_1_skor` <= 1)
                ) " . self::TABLE_OPTIONS . " COMMENT='Log percakapan chatbot anonim (K-4, BP8-15); tanpa kolom identitas'",
            default => throw new RuntimeException("DBV-033: tabel tidak dikenal {$tabel}"),
        };
    }

    private function t(string $table): string
    {
        if (! $this->db instanceof BaseConnection) {
            throw new RuntimeException('DBV-033: koneksi database tidak mendukung prefix tabel.');
        }

        return $this->db->escapeIdentifiers($this->db->prefixTable($table));
    }

    private function exec(string $sql, string $label): void
    {
        if ($this->db->query($sql) === false) {
            $error = $this->db->error();

            throw new RuntimeException("DBV-033: {$label} gagal (" . $error['code'] . '): ' . $error['message']);
        }
    }
}
