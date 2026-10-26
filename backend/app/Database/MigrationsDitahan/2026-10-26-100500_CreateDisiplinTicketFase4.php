<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Migration;
use RuntimeException;
use Throwable;

/**
 * DBV-022 (6/9) — DRAF, DITAHAN (lihat 2026-10-26-100000_CreateMasterLayananFase4.php untuk aturan pemindahan).
 * Isi = usulan coder; belum direview DB Validator.
 *
 * Kelompok E — ticketing DUA ARAH Halo Simpeg (skema final Fase 8, dibuat sekarang sesuai DoD C-01 064-3):
 *   `disiplin_ticket` = rename 1:1 tabel `inbox` legacy D1:1369-1393 [K];
 *   `disiplin_ticket_msg` = rename 1:1 tabel `inbox_msg` legacy D1:1398-1414 [K].
 * Prasyarat: `question_category` (migration 100000). Nama `inbox`/`inbox_msg` dipakai tabel notifikasi v2 (100400).
 *
 * Kolom = D1 apa adanya (termasuk nama PK/kolom `id_inbox` agar impor Fase 8 lurus, DQ-4) kecuali deviasi
 * (dokumen Bagian 2.10, 2.11, 3.3):
 *   - Collation tabel `utf8mb4_0900_ai_ci` → `utf8mb4_unicode_ci` (G2; MariaDB 10.4 matriks CI tidak mengenal 0900).
 *   - Aksi FK SET NULL/CASCADE → RESTRICT/RESTRICT, nama FK legacy dipertahankan (V1).
 *   - `disiplin_ticket.nip` VARCHAR(18) → VARCHAR(30) (V4); FK ke `pegawai.nip` DITAHAN di AddFkNipLayananFase4.php
 *     (DQ-4: pelapor bisa sudah tidak ada di `pegawai`); `sender`/`receiver`/`closed_by`/`msg_sender`/`msg_receiver`
 *     tanpa FK ke `pengguna` (DQ-6: sentinel 0 = semua petugas).
 *   - `disiplin_ticket_msg.id_inbox` NULL → NOT NULL (prasyarat audit Bagian 6.7.4 = 0; pesan tanpa tiket tidak bermakna).
 *   - CHECK domain (V2): `status` 0 Open / 1 Closed (WAJIB ada di sini, AC-01.3), `allow_reply` 0/1,
 *     `is_read`/`is_read_off` (1,2), `notify_pic` 0..2; msg `show_notif` 0/1, `is_read` 0/1.
 *   - Trigger `inbox_msg_afIns` tidak dibawa (V3); logikanya dicatat untuk service Fase 8. Tanpa hapus lunak (N11).
 *
 * Fase 4 tidak menulis satu baris pun ke kedua tabel ini (AC-06.4). DDL ter-commit per statement: bila CREATE kedua
 * gagal, up() men-drop tabel yang sempat dibuat PADA RUN ITU lalu melempar ulang error. down() men-drop kedua tabel.
 */
class CreateDisiplinTicketFase4 extends Migration
{
    public const TABLES = ['disiplin_ticket', 'disiplin_ticket_msg'];

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
            'disiplin_ticket' => "CREATE TABLE {$this->t('disiplin_ticket')} (
                `id_inbox` INT NOT NULL AUTO_INCREMENT,
                `id_question_category` INT NULL DEFAULT NULL,
                `subject` VARCHAR(250) NOT NULL,
                `sender` INT NOT NULL,
                `sender_name` VARCHAR(150) NOT NULL,
                `nip` VARCHAR(30) NOT NULL,
                `jabatan` VARCHAR(150) NULL DEFAULT NULL,
                `satker` VARCHAR(150) NULL DEFAULT NULL,
                `receiver` INT NOT NULL DEFAULT 0,
                `receiver_name` VARCHAR(150) NULL DEFAULT NULL,
                `allow_reply` INT NOT NULL DEFAULT 1 COMMENT '0: No, 1: Yes',
                `status` INT NOT NULL DEFAULT 0 COMMENT '0: Open, 1: Closed',
                `is_read` TINYINT NOT NULL DEFAULT 1 COMMENT '1: Yes, 2: No',
                `is_read_off` TINYINT NOT NULL DEFAULT 1 COMMENT '1: Yes, 2: No',
                `notify_pic` TINYINT NOT NULL DEFAULT 2 COMMENT '0: Default / Old Data, 1: Yes, 2: No',
                `notify_at` DATETIME NULL DEFAULT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                `closed_by` INT NULL DEFAULT NULL,
                PRIMARY KEY (`id_inbox`),
                KEY `fk_id_question_category_i_qc` (`id_question_category`),
                KEY `created_at` (`created_at`),
                KEY `nip` (`nip`),
                CONSTRAINT `fk_id_question_category_i_qc` FOREIGN KEY (`id_question_category`) REFERENCES {$this->t('question_category')} (`id_question_category`) " . self::RESTRICT . ',
                CONSTRAINT `chk_dticket_status` CHECK (`status` IN (0, 1)),
                CONSTRAINT `chk_dticket_allow_reply` CHECK (`allow_reply` IN (0, 1)),
                CONSTRAINT `chk_dticket_is_read` CHECK (`is_read` IN (1, 2)),
                CONSTRAINT `chk_dticket_is_read_off` CHECK (`is_read_off` IN (1, 2)),
                CONSTRAINT `chk_dticket_notify_pic` CHECK (`notify_pic` IN (0, 1, 2))
            ) ' . self::TABLE_OPTIONS,
            'disiplin_ticket_msg' => "CREATE TABLE {$this->t('disiplin_ticket_msg')} (
                `id_inbox_msg` INT NOT NULL AUTO_INCREMENT,
                `id_inbox` INT NOT NULL,
                `msg_sender` INT NOT NULL,
                `msg_sender_name` VARCHAR(150) NOT NULL,
                `msg_receiver` INT NOT NULL DEFAULT 0,
                `msg_receiver_name` VARCHAR(150) NULL DEFAULT NULL,
                `msg` TEXT NOT NULL,
                `attachments` TEXT NULL,
                `show_notif` TINYINT NOT NULL DEFAULT 1 COMMENT '0: No, 1: Yes',
                `is_read` TINYINT NOT NULL DEFAULT 0 COMMENT '0: No, 1: Yes',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id_inbox_msg`),
                KEY `msg_receiver_show_notif` (`msg_receiver`, `show_notif`),
                KEY `fk_id_inbox_im_qc` (`id_inbox`),
                CONSTRAINT `fk_id_inbox_im_qc` FOREIGN KEY (`id_inbox`) REFERENCES {$this->t('disiplin_ticket')} (`id_inbox`) " . self::RESTRICT . ',
                CONSTRAINT `chk_dticketmsg_show_notif` CHECK (`show_notif` IN (0, 1)),
                CONSTRAINT `chk_dticketmsg_is_read` CHECK (`is_read` IN (0, 1))
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
