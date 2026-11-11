<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Migration;
use RuntimeException;
use Throwable;

/**
 * DBV-032 (Fase 7, F-01, PRD v2.0 §5.1) — DRAF DITAHAN sampai disetujui DB Validator dan D1 Fase 7 (G7-2) diterima;
 * dipindah ke `Migrations/` dengan timestamp baru setelah approve (pola DBV-026/DBV-027/DBV-030). Dokumen:
 * backend/docs/db-review/DBV-032-konten-interaksi-fase7.md.
 *
 * Tiga belas tabel baru, tanpa ALTER tabel lama dan tanpa data (impor = tiket tersendiri, Q7-24):
 *   - konten (Jalur A): `popup`, `user_popup`, `banner`, `user_banner`, `news_category`, `news`, `news_flag`, `jenis_dibo`,
 *     `dibo`;
 *   - interaksi (Jalur B): `form_digital`, `user_form_digital`, `filled_form_digital`, `skl`.
 * `inbox`/`inbox_msg` TIDAK dibuat di sini (sudah ada, DBV-022).
 *
 * Konvensi: kolom [K-kode] mengikuti nama legacy; tipe dicocokkan dengan D1 Fase 7 (salinan dev) 11-11-2026, dokumen §2a.
 * FK NIP memakai nama registry `TabelAnakNip` (RESTRICT/RESTRICT, koreksi NIP lewat aplikasi B-06); FK anak→induk
 * ON DELETE CASCADE kecuali `filled_form_digital` (RESTRICT, hapus form = lunak Q7-19). Kolom `*_by` = `id_pengguna`
 * tanpa FK (pola DBV-026). Waktu UTC diisi aplikasi (CR-086). Path berkas = kunci storage (Q7-14).
 * up() gagal → tabel yang sempat dibuat di-DROP (urutan anak dulu); down() men-DROP ketiga belas tabel.
 */
class CreateKontenInteraksiFase7 extends Migration
{
    /** Urutan pembuatan (induk dulu); DROP memakai urutan terbalik. */
    public const TABEL = [
        'popup', 'user_popup', 'banner', 'user_banner',
        'news_category', 'news', 'news_flag',
        'jenis_dibo', 'dibo',
        'form_digital', 'user_form_digital', 'filled_form_digital',
        'skl',
    ];

    private const TABLE_OPTIONS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    private const RESTRICT = 'ON DELETE RESTRICT ON UPDATE RESTRICT';

    private const CASCADE = 'ON DELETE CASCADE ON UPDATE RESTRICT';

    public function up(): void
    {
        try {
            foreach (self::TABEL as $tabel) {
                $this->exec($this->ddl($tabel), "CREATE TABLE {$tabel}");
            }
        } catch (Throwable $e) {
            try {
                $this->down();
            } catch (Throwable) {
                // Galat asli lebih penting.
            }

            throw $e;
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::TABEL) as $tabel) {
            $this->exec('DROP TABLE IF EXISTS ' . $this->t($tabel), "DROP TABLE {$tabel}");
        }
    }

    /** Kolom target penargetan JSON (popup/banner/form_digital): array id unit/satker dan array NIP. */
    private function kolomTarget(string $tabel): string
    {
        return "`target_uns` MEDIUMTEXT NULL DEFAULT NULL COMMENT 'JSON array unit/satker (\"0\" kementerian, id_unit, \"sat_<id>\") [K-kode]',
                    `target_peg` MEDIUMTEXT NULL DEFAULT NULL COMMENT 'JSON array NIP target (JsonArrayNip) [K-kode]',
                    `excl_peg` MEDIUMTEXT NULL DEFAULT NULL COMMENT 'JSON array NIP dikecualikan (JsonArrayNip) [K-kode]',
                    CONSTRAINT `chk_{$tabel}_target_uns_json` CHECK (`target_uns` IS NULL OR JSON_VALID(`target_uns`)),
                    CONSTRAINT `chk_{$tabel}_target_peg_json` CHECK (`target_peg` IS NULL OR JSON_VALID(`target_peg`)),
                    CONSTRAINT `chk_{$tabel}_excl_peg_json` CHECK (`excl_peg` IS NULL OR JSON_VALID(`excl_peg`)),";
    }

    /** Kolom audit v2 (UTC, diisi aplikasi). */
    private function kolomAudit(): string
    {
        return "`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'UTC, diisi aplikasi',
                    `updated_at` DATETIME NULL DEFAULT NULL COMMENT 'UTC, diisi aplikasi',
                    `created_by` INT UNSIGNED NULL DEFAULT NULL COMMENT 'id_pengguna (tanpa FK)',
                    `updated_by` INT UNSIGNED NULL DEFAULT NULL COMMENT 'id_pengguna (tanpa FK) [K-kode]',";
    }

    private function ddl(string $tabel): string
    {
        $r = self::RESTRICT;
        $c = self::CASCADE;

        return match ($tabel) {
            'popup' => "CREATE TABLE {$this->t('popup')} (
                    `id_popup` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `tgl_mulai` DATETIME NOT NULL COMMENT 'WIB jam:menit [K] D1 DATETIME',
                    `tgl_akhir` DATETIME NOT NULL COMMENT 'WIB jam:menit [K] D1',
                    `order` INT NOT NULL DEFAULT 1 COMMENT 'Urutan tampil [K-kode]; DEFAULT 1 [K] D1',
                    `url` VARCHAR(255) NULL DEFAULT NULL COMMENT 'Tautan [K] D1',
                    `file` VARCHAR(255) NOT NULL COMMENT 'Kunci storage gambar (Q7-14)',
                    `keterangan` TEXT NULL DEFAULT NULL COMMENT '[K-kode] D1',
                    {$this->kolomTarget('popup')}
                    {$this->kolomAudit()}
                    PRIMARY KEY (`id_popup`),
                    KEY `tgl_mulai_tgl_akhir` (`tgl_mulai`, `tgl_akhir`),
                    CONSTRAINT `chk_popup_periode` CHECK (`tgl_akhir` >= `tgl_mulai`)
                ) " . self::TABLE_OPTIONS . " COMMENT='Popup pengumuman (F-04); kolom konstan legacy tidak dibawa (Q7-03), *_desc tidak dibawa (Q7-47)'",
            'user_popup' => "CREATE TABLE {$this->t('user_popup')} (
                    `id_user_popup` INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '[V2] PK sintetis (legacy PK komposit, D1)',
                    `id_popup` INT UNSIGNED NOT NULL,
                    `nip` VARCHAR(30) NOT NULL,
                    PRIMARY KEY (`id_user_popup`),
                    UNIQUE KEY `uk_user_popup_id_popup_nip` (`id_popup`, `nip`),
                    KEY `nip` (`nip`),
                    CONSTRAINT `fk_id_popup_userpopup_to_popup` FOREIGN KEY (`id_popup`) REFERENCES {$this->t('popup')} (`id_popup`) {$c},
                    CONSTRAINT `fk_nip_userpopup_to_pegawai` FOREIGN KEY (`nip`) REFERENCES {$this->t('pegawai')} (`nip`) {$r}
                ) " . self::TABLE_OPTIONS . " COMMENT='Snapshot penerima popup (Q7-15); tanpa kolom dilihat (Q7-02)'",
            'banner' => "CREATE TABLE {$this->t('banner')} (
                    `id_banner` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `tgl_mulai` DATE NOT NULL COMMENT '[K] D1 DATE',
                    `tgl_akhir` DATE NOT NULL COMMENT '[K-kode] D1',
                    `order` INT NOT NULL DEFAULT 1 COMMENT 'Urutan slider [K-kode]; DEFAULT 1 [K] D1',
                    `url` VARCHAR(255) NULL DEFAULT NULL COMMENT '[K] D1',
                    `file` VARCHAR(255) NOT NULL COMMENT 'Kunci storage gambar (Q7-14)',
                    `keterangan` TEXT NULL DEFAULT NULL COMMENT '[K-kode] D1',
                    {$this->kolomTarget('banner')}
                    {$this->kolomAudit()}
                    PRIMARY KEY (`id_banner`),
                    KEY `tgl_mulai_tgl_akhir` (`tgl_mulai`, `tgl_akhir`),
                    CONSTRAINT `chk_banner_periode` CHECK (`tgl_akhir` >= `tgl_mulai`)
                ) " . self::TABLE_OPTIONS . " COMMENT='Banner dashboard (F-05); kolom konstan legacy tidak dibawa (Q7-03), *_desc tidak dibawa (Q7-47)'",
            'user_banner' => "CREATE TABLE {$this->t('user_banner')} (
                    `id_user_banner` INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '[V2] PK sintetis (legacy PK komposit/tanpa PK, D1)',
                    `id_banner` INT UNSIGNED NOT NULL,
                    `nip` VARCHAR(30) NOT NULL,
                    PRIMARY KEY (`id_user_banner`),
                    UNIQUE KEY `uk_user_banner_id_banner_nip` (`id_banner`, `nip`),
                    KEY `nip` (`nip`),
                    CONSTRAINT `fk_id_banner_usbanner_to_banner` FOREIGN KEY (`id_banner`) REFERENCES {$this->t('banner')} (`id_banner`) {$c},
                    CONSTRAINT `fk_nip_usbanner_to_pegawai` FOREIGN KEY (`nip`) REFERENCES {$this->t('pegawai')} (`nip`) {$r}
                ) " . self::TABLE_OPTIONS . " COMMENT='Snapshot penerima banner (Q7-15)'",
            'news_category' => "CREATE TABLE {$this->t('news_category')} (
                    `id_news_category` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `category` VARCHAR(150) NOT NULL COMMENT '[K-kode L_news.php:1317-1352] D1',
                    `order` INT NOT NULL DEFAULT 0 COMMENT '[K-kode]',
                    `allow_ap` TINYINT NOT NULL DEFAULT 2 COMMENT '1 boleh dipakai role 3 (butuh persetujuan), 2 tidak [K] D1 enum 1/2',
                    `keterangan` TEXT NULL DEFAULT NULL COMMENT '[K-kode] D1',
                    `status` TINYINT NOT NULL DEFAULT 1 COMMENT '1 aktif, 2 nonaktif, 10 dihapus [V2]; legacy 1/0 (0 → 2 saat impor)',
                    {$this->kolomAudit()}
                    PRIMARY KEY (`id_news_category`),
                    CONSTRAINT `chk_news_category_allow_ap` CHECK (`allow_ap` IN (1, 2)),
                    CONSTRAINT `chk_news_category_status` CHECK (`status` IN (1, 2, 10))
                ) " . self::TABLE_OPTIONS . " COMMENT='Kategori berita (F-07)'",
            'news' => "CREATE TABLE {$this->t('news')} (
                    `id_news` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `id_news_category` INT UNSIGNED NULL DEFAULT NULL COMMENT 'NULL-able [K] D1; wajib di form v2 (DBV-032-Q10)',
                    `title` VARCHAR(255) NOT NULL COMMENT '[K-kode L_news.php:866-930] D1',
                    `news` MEDIUMTEXT NOT NULL COMMENT 'HTML terbatas, dibersihkan server (Q7-10) D1',
                    `lampiran` VARCHAR(255) NULL DEFAULT NULL COMMENT 'Kunci storage (Q7-14)',
                    `publish_at` DATETIME NOT NULL COMMENT 'UTC [K-kode]',
                    `status` TINYINT NOT NULL DEFAULT 0 COMMENT 'Alur persetujuan [K]: 0 menunggu, 1 terbit, 2 ditolak',
                    `show_notif` TINYINT NOT NULL DEFAULT 0 COMMENT 'Notifikasi pembuat role 3 [K]: 0 belum, 1 baru, 2 dihapus, 3 dibaca; diisi 1 + notif_date oleh service saat status 0→1/2 (pengganti trigger news_beUpd, D1)',
                    `notif_date` DATETIME NULL DEFAULT NULL COMMENT 'UTC saat show_notif diisi 1 [K] D1',
                    `target` TINYINT NOT NULL DEFAULT 1 COMMENT '1 kementerian, 2 unit, 3 satker [K-kode]',
                    `id_unit_target` INT NULL DEFAULT NULL COMMENT '[K-kode] D1 tanpa FK',
                    `id_satker_target` INT NULL DEFAULT NULL COMMENT '[K-kode] D1 tanpa FK',
                    `ul_creator` TINYINT NOT NULL COMMENT 'Role pembuat (1/3) [K-kode]',
                    `id_unit_creator` INT NULL DEFAULT NULL COMMENT 'Unit pembuat role 3 [K-kode] D1',
                    `id_satker_creator` INT NULL DEFAULT NULL COMMENT 'Satker pembuat role 3 [K-kode] D1',
                    `approved_at` DATETIME NULL DEFAULT NULL COMMENT 'UTC [K-kode]',
                    `approved_by` INT UNSIGNED NULL DEFAULT NULL COMMENT 'id_pengguna (tanpa FK) [K-kode]',
                    `reason_note` TEXT NULL DEFAULT NULL COMMENT 'Alasan penolakan [K-kode]',
                    {$this->kolomAudit()}
                    PRIMARY KEY (`id_news`),
                    KEY `status_publish_at` (`status`, `publish_at`),
                    KEY `id_news_category` (`id_news_category`),
                    CONSTRAINT `fk_id_news_category_news_to_nc` FOREIGN KEY (`id_news_category`) REFERENCES {$this->t('news_category')} (`id_news_category`) {$r},
                    CONSTRAINT `chk_news_status` CHECK (`status` IN (0, 1, 2)),
                    CONSTRAINT `chk_news_show_notif` CHECK (`show_notif` IN (0, 1, 2, 3)),
                    CONSTRAINT `chk_news_target` CHECK (`target` IN (1, 2, 3))
                ) " . self::TABLE_OPTIONS . " COMMENT='Berita (F-07); salinan nama category/unit_satker_target legacy tidak dibawa, diturunkan saat baca (Q2)'",
            'news_flag' => "CREATE TABLE {$this->t('news_flag')} (
                    `id_news_flag` INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '[V2] PK sintetis (legacy PK komposit/tanpa PK, D1)',
                    `id_user` INT UNSIGNED NOT NULL COMMENT 'pengguna.id_pengguna [K-kode news_flag.id_user]',
                    `id_news` INT UNSIGNED NOT NULL,
                    `created_at` DATETIME NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'UTC, diisi aplikasi [K] D1',
                    PRIMARY KEY (`id_news_flag`),
                    UNIQUE KEY `uk_news_flag_id_user_id_news` (`id_user`, `id_news`),
                    KEY `id_news` (`id_news`),
                    CONSTRAINT `fk_id_user_newsflag_to_pengguna` FOREIGN KEY (`id_user`) REFERENCES {$this->t('pengguna')} (`id_pengguna`) {$r},
                    CONSTRAINT `fk_id_news_newsflag_to_news` FOREIGN KEY (`id_news`) REFERENCES {$this->t('news')} (`id_news`) {$c}
                ) " . self::TABLE_OPTIONS . " COMMENT='Penanda berita belum dibaca per pengguna (Q7-09)'",
            'jenis_dibo' => "CREATE TABLE {$this->t('jenis_dibo')} (
                    `id_jenis_dibo` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `jenis_dibo` VARCHAR(255) NOT NULL COMMENT '[K] D1',
                    `order` INT NOT NULL DEFAULT 0 COMMENT '[K-kode]',
                    `keterangan` TEXT NULL DEFAULT NULL COMMENT '[K] D1',
                    `status` TINYINT NOT NULL DEFAULT 1 COMMENT '1 aktif, 2 nonaktif [K], 10 dihapus (Q7-11)',
                    {$this->kolomAudit()}
                    PRIMARY KEY (`id_jenis_dibo`),
                    CONSTRAINT `chk_jenis_dibo_status` CHECK (`status` IN (1, 2, 10))
                ) " . self::TABLE_OPTIONS . " COMMENT='Master jenis DIBO (F-12, Q7-11); seed dari D1 (G7-2)'",
            'dibo' => "CREATE TABLE {$this->t('dibo')} (
                    `id_dibo` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `id_jenis_dibo` INT UNSIGNED NULL DEFAULT NULL COMMENT 'NULL-able [K] D1; wajib di form v2 (DBV-032-Q12)',
                    `nomor` VARCHAR(50) NULL DEFAULT NULL COMMENT 'NULL-able [K] D1; wajib di form v2 (Q12)',
                    `tahun` YEAR NULL DEFAULT NULL COMMENT 'NULL-able [K] D1; wajib di form v2 (Q12)',
                    `tentang` MEDIUMTEXT NOT NULL COMMENT '[K] D1',
                    `keterangan` MEDIUMTEXT NULL DEFAULT NULL COMMENT '[K] D1',
                    `file_1` VARCHAR(255) NOT NULL COMMENT 'Kunci storage, wajib (Q7-14)',
                    `file_2` VARCHAR(255) NULL DEFAULT NULL COMMENT 'Kunci storage',
                    `file_3` VARCHAR(255) NULL DEFAULT NULL COMMENT 'Kunci storage',
                    `status` TINYINT NOT NULL DEFAULT 1 COMMENT '1 aktif, 2 dicabut [K-kode views/hr/dibo/form.php:25-26], 10 dihapus [V2]',
                    {$this->kolomAudit()}
                    PRIMARY KEY (`id_dibo`),
                    KEY `id_jenis_dibo_status` (`id_jenis_dibo`, `status`),
                    CONSTRAINT `fk_id_jenis_dibo_dibo_to_jd` FOREIGN KEY (`id_jenis_dibo`) REFERENCES {$this->t('jenis_dibo')} (`id_jenis_dibo`) {$r},
                    CONSTRAINT `chk_dibo_status` CHECK (`status` IN (1, 2, 10))
                ) " . self::TABLE_OPTIONS . " COMMENT='Kamus Digital Kepegawaian (F-12)'",
            'form_digital' => "CREATE TABLE {$this->t('form_digital')} (
                    `id_form_digital` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `judul_form` VARCHAR(255) NOT NULL COMMENT '[K-kode L_announcement.php:2615-2624] D1',
                    `tgl_mulai` DATE NOT NULL COMMENT '[K-kode] D1',
                    `tgl_akhir` DATE NOT NULL COMMENT '[K-kode] D1',
                    `order` INT NOT NULL DEFAULT 0 COMMENT '[K-kode]',
                    `form_digital` LONGTEXT NOT NULL COMMENT 'Skema form JSON v2 tervalidasi (Q7-18); legacy = JSON formBuilder D1',
                    `keterangan` TEXT NULL DEFAULT NULL COMMENT '[K-kode] D1',
                    `status` TINYINT NOT NULL DEFAULT 1 COMMENT '1 aktif, 2 nonaktif, 10 dihapus lunak (Q7-19) [V2]',
                    `deleted_at` DATETIME NULL DEFAULT NULL COMMENT 'UTC saat status 10',
                    {$this->kolomTarget('form_digital')}
                    {$this->kolomAudit()}
                    PRIMARY KEY (`id_form_digital`),
                    KEY `status_tgl_mulai_tgl_akhir` (`status`, `tgl_mulai`, `tgl_akhir`),
                    CONSTRAINT `chk_form_digital_skema_json` CHECK (JSON_VALID(`form_digital`)),
                    CONSTRAINT `chk_form_digital_periode` CHECK (`tgl_akhir` >= `tgl_mulai`),
                    CONSTRAINT `chk_form_digital_status` CHECK (`status` IN (1, 2, 10))
                ) " . self::TABLE_OPTIONS . " COMMENT='Form digital/kuesioner (F-06); kolom target JSON [K] D1'",
            'user_form_digital' => "CREATE TABLE {$this->t('user_form_digital')} (
                    `id_user_form_digital` INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '[V2] PK sintetis (legacy PK komposit/tanpa PK, D1)',
                    `id_form_digital` INT UNSIGNED NOT NULL,
                    `nip` VARCHAR(30) NOT NULL,
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'UTC, diisi aplikasi',
                    PRIMARY KEY (`id_user_form_digital`),
                    UNIQUE KEY `uk_user_form_digital_id_fd_nip` (`id_form_digital`, `nip`),
                    KEY `nip` (`nip`),
                    CONSTRAINT `fk_id_fd_userfd_to_fd` FOREIGN KEY (`id_form_digital`) REFERENCES {$this->t('form_digital')} (`id_form_digital`) {$c},
                    CONSTRAINT `fk_nip_user_fd_to_pegawai` FOREIGN KEY (`nip`) REFERENCES {$this->t('pegawai')} (`nip`) {$r}
                ) " . self::TABLE_OPTIONS . " COMMENT='Target form digital, permanen (Q7-20; legacy dihapus saat submit)'",
            'filled_form_digital' => "CREATE TABLE {$this->t('filled_form_digital')} (
                    `id_filled_form_digital` INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '[V2] PK sintetis (legacy PK komposit/tanpa PK, D1)',
                    `id_form_digital` INT UNSIGNED NOT NULL,
                    `nip` VARCHAR(30) NOT NULL,
                    `response` LONGTEXT NOT NULL COMMENT 'Jawaban JSON per id pertanyaan; berkas = kunci storage [K-kode Announcement.php:511-518] D1',
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'UTC, diisi aplikasi',
                    `created_by` INT UNSIGNED NULL DEFAULT NULL COMMENT 'id_pengguna (tanpa FK) [K-kode]',
                    PRIMARY KEY (`id_filled_form_digital`),
                    UNIQUE KEY `uk_filled_form_digital_id_fd_nip` (`id_form_digital`, `nip`),
                    KEY `nip` (`nip`),
                    CONSTRAINT `fk_id_fd_ffd_to_fd` FOREIGN KEY (`id_form_digital`) REFERENCES {$this->t('form_digital')} (`id_form_digital`) {$r},
                    CONSTRAINT `fk_nip_ffd_to_pegawai` FOREIGN KEY (`nip`) REFERENCES {$this->t('pegawai')} (`nip`) {$r},
                    CONSTRAINT `chk_filled_form_digital_response_json` CHECK (JSON_VALID(`response`))
                ) " . self::TABLE_OPTIONS . " COMMENT='Jawaban form digital; satu per pegawai per form (Q7-17)'",
            'skl' => "CREATE TABLE {$this->t('skl')} (
                    `id_skl` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `nip` VARCHAR(30) NOT NULL,
                    `jenis_rwy` TINYINT NOT NULL COMMENT '1 cuti, 2 KARIS/KARSU, 3 KARPEG, 4 TB, 5 IB [K-kode L_survey.php:15-21]; lookup master v2 (Q7-22)',
                    `id_rwy` INT NOT NULL COMMENT 'PK baris riwayat_* sesuai jenis_rwy; polimorfik, tanpa FK',
                    `rate` TINYINT NOT NULL COMMENT '1-5 [K-kode survey_kepuasan.php:28-37]',
                    `kritik_saran` TEXT NULL DEFAULT NULL COMMENT '[K] D1 tinytext; TEXT superset',
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'UTC, diisi aplikasi',
                    `created_by` INT UNSIGNED NULL DEFAULT NULL COMMENT 'id_pengguna (tanpa FK) [K-kode]',
                    PRIMARY KEY (`id_skl`),
                    UNIQUE KEY `uk_skl_nip_jenis_rwy_id_rwy` (`nip`, `jenis_rwy`, `id_rwy`),
                    KEY `jenis_rwy_created_at` (`jenis_rwy`, `created_at`),
                    CONSTRAINT `fk_nip_skl_to_peg` FOREIGN KEY (`nip`) REFERENCES {$this->t('pegawai')} (`nip`) {$r},
                    CONSTRAINT `chk_skl_jenis_rwy` CHECK (`jenis_rwy` BETWEEN 1 AND 5),
                    CONSTRAINT `chk_skl_rate` CHECK (`rate` BETWEEN 1 AND 5)
                ) " . self::TABLE_OPTIONS . " COMMENT='Survei Kepuasan Layanan (F-11); satu per pegawai per riwayat layanan (Q7-21)'",
            default => throw new RuntimeException("DBV-032: tabel tidak dikenal {$tabel}"),
        };
    }

    private function t(string $table): string
    {
        if (! $this->db instanceof BaseConnection) {
            throw new RuntimeException('DBV-032: koneksi database tidak mendukung prefix tabel.');
        }

        return $this->db->escapeIdentifiers($this->db->prefixTable($table));
    }

    private function exec(string $sql, string $label): void
    {
        if ($this->db->query($sql) === false) {
            $error = $this->db->error();

            throw new RuntimeException("DBV-032: {$label} gagal (" . $error['code'] . '): ' . $error['message']);
        }
    }
}
