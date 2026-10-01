<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Migration;
use CodeIgniter\Database\ResultInterface;
use RuntimeException;

/**
 * DBV-010 (3/3) — identitas akun pindah dari NIP ke `id_pengguna` (K2): akun role 1/3/4/5/8 boleh tanpa NIP, sehingga
 * sesi dan pelaku audit tidak bisa lagi dikunci dengan NIP. `id_pengguna` = `pengguna.id` legacy (impor memakai ID legacy)
 * dan sudah menjadi isi kolom `created_by`/`updated_by` master.
 *
 * token
 *   - Isi tabel DIKOSONGKAN (D-9): claims refresh token lama memakai `sub` = NIP dan akan ditolak aplikasi baru; seluruh
 *     pengguna login ulang satu kali.
 *   - `id_pengguna` INT UNSIGNED NOT NULL + KEY (pemilik sesi; pencabutan massal & reuse detection). Tanpa FK (D-7).
 *   - `nip` VARCHAR(30) NULL: jejak NIP pemilik saat token terbit; KEY `nip` dihapus (tidak dipakai query lagi).
 * audit_logs
 *   - `id_pengguna_actor` INT UNSIGNED NULL + KEY: pelaku (NULL = proses sistem/CLI). Tanpa FK (tabel log, D-8).
 *     Baris lama diisi dari `nip_actor` (JOIN pengguna, termasuk akun yang sudah dihapus).
 *   - `nip_actor` VARCHAR(30) NULL tetap disimpan sebagai jejak NIP pelaku saat kejadian (NULL untuk akun tanpa NIP).
 *
 * Satu ALTER per tabel; bagian ADD/DROP INDEX dilewati bila sudah dalam keadaan tujuan (up() bisa diulang).
 *
 * down() menolak (sebelum ALTER apa pun) baris audit yang pelakunya tidak punya NIP atau NIP-nya > 20 karakter (tidak
 * bisa diwakili `nip_actor` lama), lalu mengosongkan `token` lagi (semua sesi berakhir).
 *
 * Review DB Validator: backend/docs/db-review/A-01-auth-schema.md Bagian 9 (DBV-010).
 */
class AlterIdentitasAkunIdPengguna extends Migration
{
    private const UNICODE = 'utf8mb4_unicode_ci';

    public function up(): void
    {
        $this->exec("DELETE FROM {$this->t('token')}");

        $token = $this->structure('token');
        $specs = [];

        if (! in_array('id_pengguna', $token['columns'], true)) {
            $specs[] = "ADD `id_pengguna` INT UNSIGNED NOT NULL COMMENT 'pemilik sesi (pengguna.id_pengguna)' AFTER `id`";
        }

        $specs[] = 'MODIFY `nip` VARCHAR(30) COLLATE ' . self::UNICODE
            . " NULL DEFAULT NULL COMMENT 'NIP pemilik saat token terbit (jejak); NULL untuk akun tanpa NIP'";

        if (in_array('nip', $token['indexes'], true)) {
            $specs[] = 'DROP INDEX `nip`';
        }

        if (! in_array('id_pengguna', $token['indexes'], true)) {
            $specs[] = 'ADD KEY `id_pengguna` (`id_pengguna`)';
        }

        $this->exec("ALTER TABLE {$this->t('token')} " . implode(', ', $specs));

        $audit = $this->structure('audit_logs');
        $specs = [];

        if (! in_array('id_pengguna_actor', $audit['columns'], true)) {
            $specs[] = "ADD `id_pengguna_actor` INT UNSIGNED NULL DEFAULT NULL COMMENT 'pelaku (pengguna.id_pengguna); NULL untuk proses sistem/CLI' AFTER `id_log`";
        }

        $specs[] = 'MODIFY `nip_actor` VARCHAR(30) COLLATE ' . self::UNICODE
            . " NULL DEFAULT NULL COMMENT 'NIP pelaku saat kejadian (jejak); NULL untuk akun tanpa NIP dan proses sistem/CLI'";

        if (! in_array('id_pengguna_actor', $audit['indexes'], true)) {
            $specs[] = 'ADD KEY `id_pengguna_actor` (`id_pengguna_actor`)';
        }

        $this->exec("ALTER TABLE {$this->t('audit_logs')} " . implode(', ', $specs));

        // Backfill pelaku baris lama. JOIN aman karena kedua kolom sudah utf8mb4_unicode_ci (2026-09-25-130000).
        $this->exec(
            "UPDATE {$this->t('audit_logs')} a JOIN {$this->t('pengguna')} p ON p.`nip` = a.`nip_actor`
             SET a.`id_pengguna_actor` = p.`id_pengguna`
             WHERE a.`id_pengguna_actor` IS NULL AND a.`nip_actor` IS NOT NULL",
        );
    }

    public function down(): void
    {
        $this->assertAuditRepresentableInOldSchema();

        $this->exec("DELETE FROM {$this->t('token')}");

        $token = $this->structure('token');
        $specs = [];

        if (in_array('id_pengguna', $token['indexes'], true)) {
            $specs[] = 'DROP INDEX `id_pengguna`';
        }

        if (in_array('id_pengguna', $token['columns'], true)) {
            $specs[] = 'DROP COLUMN `id_pengguna`';
        }

        $specs[] = 'MODIFY `nip` VARCHAR(20) COLLATE ' . self::UNICODE . ' NOT NULL';

        if (! in_array('nip', $token['indexes'], true)) {
            $specs[] = 'ADD KEY `nip` (`nip`)';
        }

        $this->exec("ALTER TABLE {$this->t('token')} " . implode(', ', $specs));

        $audit = $this->structure('audit_logs');
        $specs = [];

        if (in_array('id_pengguna_actor', $audit['indexes'], true)) {
            $specs[] = 'DROP INDEX `id_pengguna_actor`';
        }

        if (in_array('id_pengguna_actor', $audit['columns'], true)) {
            $specs[] = 'DROP COLUMN `id_pengguna_actor`';
        }

        $specs[] = 'MODIFY `nip_actor` VARCHAR(20) COLLATE ' . self::UNICODE . " NULL DEFAULT NULL COMMENT 'NIP pelaku; NULL untuk proses sistem/CLI'";

        $this->exec("ALTER TABLE {$this->t('audit_logs')} " . implode(', ', $specs));
    }

    /**
     * `nip_actor` lama (VARCHAR(20)) satu-satunya kolom pelaku: baris yang pelakunya hanya dikenali lewat
     * `id_pengguna_actor` akan kehilangan pelaku. Tolak rollback sebelum ALTER apa pun.
     */
    private function assertAuditRepresentableInOldSchema(): void
    {
        if (! in_array('id_pengguna_actor', $this->structure('audit_logs')['columns'], true)) {
            return;
        }

        $result = $this->connection()->query(
            "SELECT SUM(`id_pengguna_actor` IS NOT NULL AND `nip_actor` IS NULL) AS tanpa_nip,
                    SUM(CHAR_LENGTH(`nip_actor`) > 20) AS nip_panjang
             FROM {$this->t('audit_logs')}",
        );

        if (! $result instanceof ResultInterface) {
            throw new RuntimeException('DBV-010: audit_logs tidak dapat diperiksa.');
        }

        $row      = $result->getRowArray() ?? [];
        $problems = [];

        if ((int) ($row['tanpa_nip'] ?? 0) > 0) {
            $problems[] = (int) $row['tanpa_nip'] . ' baris audit dengan pelaku akun tanpa NIP';
        }

        if ((int) ($row['nip_panjang'] ?? 0) > 0) {
            $problems[] = (int) $row['nip_panjang'] . ' baris audit dengan NIP pelaku > 20 karakter';
        }

        if ($problems !== []) {
            throw new RuntimeException(
                'DBV-010: rollback ditolak — kolom nip_actor lama tidak bisa menyimpan ' . implode(', ', $problems)
                . '. Pelaku baris tersebut akan hilang; arsipkan/hapus baris itu dulu bila rollback memang diperlukan.',
            );
        }
    }

    /**
     * @return array{columns: list<string>, indexes: list<string>}
     */
    private function structure(string $table): array
    {
        $binds = [$this->connection()->getDatabase(), $this->connection()->prefixTable($table)];

        $columns = $this->connection()->query(
            'SELECT COLUMN_NAME AS n FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
            $binds,
        );
        $indexes = $this->connection()->query(
            'SELECT DISTINCT INDEX_NAME AS n FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
            $binds,
        );

        if (! $columns instanceof ResultInterface || ! $indexes instanceof ResultInterface) {
            throw new RuntimeException('DBV-010: struktur tabel ' . $table . ' tidak dapat dibaca.');
        }

        return [
            'columns' => array_map('strval', array_column($columns->getResultArray(), 'n')),
            'indexes' => array_map('strval', array_column($indexes->getResultArray(), 'n')),
        ];
    }

    /**
     * Nama tabel ber-prefix (DBPrefix, mis. `t_` di database test) yang sudah di-escape untuk SQL mentah.
     */
    private function t(string $table): string
    {
        return $this->connection()->escapeIdentifiers($this->connection()->prefixTable($table));
    }

    private function connection(): BaseConnection
    {
        if (! $this->db instanceof BaseConnection) {
            throw new RuntimeException('DBV-010: koneksi database tidak mendukung prefix tabel.');
        }

        return $this->db;
    }

    private function exec(string $sql): void
    {
        // Dengan DBDebug = false query() mengembalikan false tanpa exception: jangan biarkan ALTER gagal diam-diam.
        if ($this->connection()->query($sql) === false) {
            $error = $this->connection()->error();

            throw new RuntimeException('DBV-010: query gagal (' . $error['code'] . '): ' . $error['message']);
        }
    }
}
