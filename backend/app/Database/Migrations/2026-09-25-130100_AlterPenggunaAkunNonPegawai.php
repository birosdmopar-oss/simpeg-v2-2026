<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Migration;
use CodeIgniter\Database\ResultInterface;
use RuntimeException;

/**
 * DBV-010 (2/3) — `pengguna` mengikuti legacy untuk akun non-pegawai (keputusan K1/K2 dan B, 25-09-2026):
 *
 *   - `nip` VARCHAR(30) NULL (legacy `pengguna.id_pegawai` VARCHAR(30) NULL [K]; lebar = `pegawai.nip`, K1). Akun role
 *     1/3/4/5/8 boleh tanpa NIP, role 2/6/7 wajib NIP — aturan ditegakkan aplikasi, bukan CHECK (D-4). UNIQUE `nip`
 *     tetap (MySQL/MariaDB mengizinkan banyak NULL).
 *   - `username` VARCHAR(30) → VARCHAR(100) (legacy [K], D-5); ikut `login_attempts.username`, `forgot_attempts.username`.
 *   - Kolom legacy baru: `name` VARCHAR(150) NULL, `email` VARCHAR(150) NULL (tanpa UNIQUE, D-6), `expired_at` DATETIME
 *     NULL (batas berlaku password legacy; disimpan untuk impor, perilaku paksa-ganti belum dibuat).
 *
 * Semua kolom string utf8mb4_unicode_ci eksplisit (setelah 2026-09-25-130000). Satu ALTER per tabel; bagian ADD
 * dilewati bila kolomnya sudah ada (up() bisa diulang).
 *
 * down() menolak (sebelum ALTER apa pun) data yang tidak bisa disimpan di skema lama: akun tanpa NIP (termasuk yang
 * soft-deleted), username > 30 karakter, NIP > 20 karakter. Baris `login_attempts`/`forgot_attempts` dengan username
 * > 30 karakter DIHAPUS (log rate limit/lockout, tidak dimigrasi — A-01 Bagian 3). Isi kolom name/email/expired_at hilang.
 *
 * Review DB Validator: backend/docs/db-review/A-01-auth-schema.md Bagian 9 (DBV-010).
 */
class AlterPenggunaAkunNonPegawai extends Migration
{
    private const UNICODE = 'utf8mb4_unicode_ci';

    public const NIP_COMMENT = 'NIP pegawai (legacy id_pegawai); NULL untuk akun non-pegawai (role 1/3/4/5/8)';

    /**
     * Kolom tambahan: nama => [definisi, posisi AFTER].
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const ADDED_COLUMNS = [
        'name'       => ["VARCHAR(150) COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL COMMENT 'nama akun (legacy name); wajib diisi aplikasi untuk akun tanpa NIP'", 'username'],
        'email'      => ["VARCHAR(150) COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL COMMENT 'email akun (legacy); tujuan tautan reset password (K3)'", 'name'],
        'expired_at' => ["DATETIME NULL DEFAULT NULL COMMENT 'legacy: batas berlaku password (paksa ganti); belum dipakai aplikasi v2'", 'password_changed_at'],
    ];

    public function up(): void
    {
        $existing = $this->columns('pengguna');
        $specs    = [
            'MODIFY `nip` VARCHAR(30) COLLATE ' . self::UNICODE . " NULL DEFAULT NULL COMMENT '" . self::NIP_COMMENT . "'",
            'MODIFY `username` VARCHAR(100) COLLATE ' . self::UNICODE . ' NOT NULL',
        ];

        foreach (self::ADDED_COLUMNS as $column => [$definition, $after]) {
            if (! in_array($column, $existing, true)) {
                $specs[] = "ADD `{$column}` {$definition} AFTER `{$after}`";
            }
        }

        $this->exec("ALTER TABLE {$this->t('pengguna')} " . implode(', ', $specs));

        foreach (['login_attempts', 'forgot_attempts'] as $table) {
            $this->exec("ALTER TABLE {$this->t($table)} MODIFY `username` VARCHAR(100) COLLATE " . self::UNICODE . ' NOT NULL');
        }
    }

    public function down(): void
    {
        $this->assertRepresentableInOldSchema();

        foreach (['login_attempts', 'forgot_attempts'] as $table) {
            $this->exec("DELETE FROM {$this->t($table)} WHERE CHAR_LENGTH(`username`) > 30");
        }

        $existing = $this->columns('pengguna');
        $specs    = [
            'MODIFY `nip` VARCHAR(20) COLLATE ' . self::UNICODE . ' NOT NULL',
            'MODIFY `username` VARCHAR(30) COLLATE ' . self::UNICODE . ' NOT NULL',
        ];

        foreach (array_keys(self::ADDED_COLUMNS) as $column) {
            if (in_array($column, $existing, true)) {
                $specs[] = "DROP COLUMN `{$column}`";
            }
        }

        $this->exec("ALTER TABLE {$this->t('pengguna')} " . implode(', ', $specs));

        foreach (['login_attempts', 'forgot_attempts'] as $table) {
            $this->exec("ALTER TABLE {$this->t($table)} MODIFY `username` VARCHAR(30) COLLATE " . self::UNICODE . ' NOT NULL');
        }
    }

    /**
     * Skema lama (nip VARCHAR(20) NOT NULL, username VARCHAR(30)) tidak bisa menyimpan akun non-pegawai: tolak rollback
     * sebelum ALTER apa pun (mode strict gagal di tengah, non-strict memotong/mengisi '' diam-diam).
     */
    private function assertRepresentableInOldSchema(): void
    {
        $row = $this->first(
            "SELECT SUM(`nip` IS NULL) AS tanpa_nip, SUM(CHAR_LENGTH(`username`) > 30) AS username_panjang,
                    SUM(CHAR_LENGTH(`nip`) > 20) AS nip_panjang
             FROM {$this->t('pengguna')}",
        );

        $problems = [];

        foreach ([
            'tanpa_nip'        => '%d akun tanpa NIP (termasuk yang dihapus)',
            'username_panjang' => '%d akun dengan username > 30 karakter',
            'nip_panjang'      => '%d akun dengan NIP > 20 karakter',
        ] as $key => $message) {
            if ((int) ($row[$key] ?? 0) > 0) {
                $problems[] = sprintf($message, (int) $row[$key]);
            }
        }

        if ($problems !== []) {
            throw new RuntimeException(
                'DBV-010: rollback ditolak — skema pengguna lama tidak bisa menyimpan ' . implode(', ', $problems)
                . '. Tautkan NIP / perpendek username atau hapus akun tersebut dulu, lalu jalankan ulang.',
            );
        }
    }

    /**
     * @return list<string>
     */
    private function columns(string $table): array
    {
        $result = $this->connection()->query(
            'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
            [$this->connection()->getDatabase(), $this->connection()->prefixTable($table)],
        );

        if (! $result instanceof ResultInterface) {
            throw new RuntimeException('DBV-010: kolom tabel ' . $table . ' tidak dapat dibaca.');
        }

        return array_map('strval', array_column($result->getResultArray(), 'COLUMN_NAME'));
    }

    /**
     * @return array<string, mixed>
     */
    private function first(string $sql): array
    {
        $result = $this->connection()->query($sql);

        if (! $result instanceof ResultInterface) {
            $error = $this->connection()->error();

            throw new RuntimeException('DBV-010: query gagal (' . $error['code'] . '): ' . $error['message']);
        }

        return $result->getRowArray() ?? [];
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

            throw new RuntimeException('DBV-010: ALTER gagal (' . $error['code'] . '): ' . $error['message']);
        }
    }
}
