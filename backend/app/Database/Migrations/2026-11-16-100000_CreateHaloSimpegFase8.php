<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Migration;
use RuntimeException;
use Throwable;

/**
 * DBV-028 (Fase 8 E-01, helpdesk Halo Simpeg; PRD Fase 8 v1.2 §7.1, F8-03) — tiga tabel master helpdesk yang belum ada di v2:
 * `help_desk_contact`, `support_officer`, `officer_category`. Dokumen: backend/docs/db-review/DBV-028-skema-helpdesk.md.
 *
 * Kolom ikut legacy [K]/[I] dari KODE legacy (`libraries/hr/L_support.php`); DDL produksi/dev ketiga tabel tidak tersedia,
 * sehingga tipe/panjang berlabel [I] dan diputus DB Validator. Deviasi [V2]:
 * - `status` TINYINT domain master 0/1 + CHECK (legacy varchar '1'/'0'; sama dengan `question_category` DBV-022 DQ-5);
 * - `support_officer.id_user` INT UNSIGNED NOT NULL + UNIQUE + FK RESTRICT → `pengguna.id_pengguna` (legacy hanya unik di
 *   aplikasi `LS:765-788`); nama kolom tetap `id_user` agar impor T12b lurus (H8-6, K-M11);
 * - `officer_category` UNIQUE (`id_support_officer`, `id_question_category`) + FK RESTRICT (legacy CASCADE; penggantian set
 *   kategori = hapus + sisip dalam satu transaksi di service, [K `LS:627-629`]);
 * - `help_desk_contact.contact` UNIQUE (legacy unik di aplikasi `LS:176-197`, B-16);
 * - `updated_at` di `support_officer` (audit); waktu UTC diisi aplikasi (BP8-8), tanpa DEFAULT DB.
 *
 * `question_category`, `disiplin_ticket(_msg)` SUDAH ADA (DBV-022) dan tidak dibuat ulang; indeksnya di migration
 * `2026-11-16-100100_AlterDisiplinTicketIndeksFase8`.
 * up() gagal → hanya tabel yang dibuat pada run itu yang di-DROP; down() men-DROP ketiga tabel (anak dulu).
 */
class CreateHaloSimpegFase8 extends Migration
{
    /** Urutan pembuatan (induk dulu); DROP memakai urutan terbalik. */
    public const TABEL = ['help_desk_contact', 'support_officer', 'officer_category'];

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
     * Pembersihan setelah up() gagal: hanya tabel yang dibuat pada run ini (pola CreateChatbotFase8).
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

    private function ddl(string $tabel): string
    {
        $r = self::RESTRICT;

        return match ($tabel) {
            'help_desk_contact' => "CREATE TABLE {$this->t('help_desk_contact')} (
                    `id_help_desk_contact` INT NOT NULL AUTO_INCREMENT COMMENT '[K] LS:25',
                    `name` VARCHAR(150) NOT NULL COMMENT 'Nama kontak [K] LS:156-200; panjang [I]',
                    `contact` VARCHAR(20) NOT NULL COMMENT 'Nomor kontak, unik [K] LS:176-197 + UNIQUE [V2]; panjang [I]',
                    `email` VARCHAR(150) NULL DEFAULT NULL COMMENT 'Opsional [K] help_desk/form.php:55; panjang [I]',
                    `status` TINYINT NOT NULL DEFAULT 1 COMMENT '0 Tidak Aktif, 1 Aktif [K] + TINYINT/CHECK [V2]',
                    `created_at` DATETIME NULL DEFAULT NULL COMMENT 'UTC, diisi aplikasi [K] LS:16',
                    `updated_at` DATETIME NULL DEFAULT NULL COMMENT 'UTC, diisi aplikasi [I]',
                    PRIMARY KEY (`id_help_desk_contact`),
                    UNIQUE KEY `uk_help_desk_contact_contact` (`contact`),
                    KEY `status` (`status`),
                    CONSTRAINT `chk_help_desk_contact_status` CHECK (`status` IN (0, 1))
                ) " . self::TABLE_OPTIONS . " COMMENT='Kontak helpdesk Halo Simpeg (E-07, DBV-028)'",
            'support_officer' => "CREATE TABLE {$this->t('support_officer')} (
                    `id_support_officer` INT NOT NULL AUTO_INCREMENT COMMENT '[K] LS:500',
                    `id_user` INT UNSIGNED NOT NULL COMMENT 'Akun petugas → pengguna.id_pengguna [K] LS:440 + FK/UNIQUE [V2]',
                    `email` VARCHAR(150) NOT NULL COMMENT 'Wajib di form [K] LS:756; panjang [I]',
                    `no_hp` VARCHAR(20) NULL DEFAULT NULL COMMENT 'Opsional [K] LS:798; panjang [I]',
                    `status` TINYINT NOT NULL DEFAULT 1 COMMENT '0 Tidak Aktif, 1 Aktif [K] varchar + TINYINT/CHECK [V2]',
                    `created_at` DATETIME NULL DEFAULT NULL COMMENT 'UTC, diisi aplikasi [K] LS:441',
                    `updated_at` DATETIME NULL DEFAULT NULL COMMENT 'UTC, diisi aplikasi [V2]',
                    PRIMARY KEY (`id_support_officer`),
                    UNIQUE KEY `uk_support_officer_id_user` (`id_user`),
                    KEY `status` (`status`),
                    CONSTRAINT `fk_id_user_so_to_pengguna` FOREIGN KEY (`id_user`) REFERENCES {$this->t('pengguna')} (`id_pengguna`) {$r},
                    CONSTRAINT `chk_support_officer_status` CHECK (`status` IN (0, 1))
                ) " . self::TABLE_OPTIONS . " COMMENT='Petugas Halo Simpeg (E-02, DBV-028); petugas = penugasan data, bukan role'",
            'officer_category' => "CREATE TABLE {$this->t('officer_category')} (
                    `id` INT NOT NULL AUTO_INCREMENT COMMENT '[K] LS:803-812',
                    `id_support_officer` INT NOT NULL COMMENT '[K] LS:807',
                    `id_question_category` INT NOT NULL COMMENT '[K] LS:808',
                    `created_at` DATETIME NULL DEFAULT NULL COMMENT 'UTC, diisi aplikasi [K] LS:221',
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uk_officer_category` (`id_support_officer`, `id_question_category`),
                    KEY `id_question_category` (`id_question_category`),
                    CONSTRAINT `fk_id_so_oc_to_support_officer` FOREIGN KEY (`id_support_officer`) REFERENCES {$this->t('support_officer')} (`id_support_officer`) {$r},
                    CONSTRAINT `fk_id_qc_oc_to_question_category` FOREIGN KEY (`id_question_category`) REFERENCES {$this->t('question_category')} (`id_question_category`) {$r}
                ) " . self::TABLE_OPTIONS . " COMMENT='Kategori yang ditugaskan ke petugas Halo Simpeg (E-02, DBV-028)'",
            default => throw new RuntimeException("DBV-028: tabel tidak dikenal {$tabel}"),
        };
    }

    private function t(string $table): string
    {
        if (! $this->db instanceof BaseConnection) {
            throw new RuntimeException('DBV-028: koneksi database tidak mendukung prefix tabel.');
        }

        return $this->db->escapeIdentifiers($this->db->prefixTable($table));
    }

    private function exec(string $sql, string $label): void
    {
        if ($this->db->query($sql) === false) {
            $error = $this->db->error();

            throw new RuntimeException("DBV-028: {$label} gagal (" . $error['code'] . '): ' . $error['message']);
        }
    }
}
