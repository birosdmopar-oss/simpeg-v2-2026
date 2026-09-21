<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * A-01 / A-07 — Tabel forgot_attempts: permintaan lupa password (rate limit) sekaligus token reset (hash).
 *
 * Legacy menyimpan token reset di tabel `token`; di v2 tabel `token` khusus refresh token JWT (F0-06),
 * sehingga token reset disatukan di sini (satu baris = satu permintaan = satu token single-use).
 * Skema forgot_attempts TIDAK ADA di simpeg_v2_local_seed.sql — kolom di bawah adalah usulan yang
 * perlu review DB Validator (lihat backend/docs/db-review/A-01-auth-schema.md).
 */
class CreateForgotAttempts extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'           => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'username'     => ['type' => 'VARCHAR', 'constraint' => 30],
            'ip_address'   => ['type' => 'VARCHAR', 'constraint' => 45, 'null' => true],
            'token_hash'   => ['type' => 'CHAR', 'constraint' => 64, 'null' => true, 'comment' => 'SHA-256 token reset; NULL kalau username tidak dikenal (tetap dicatat untuk rate limit)'],
            'expires_at'   => ['type' => 'DATETIME', 'null' => true],
            'used_at'      => ['type' => 'DATETIME', 'null' => true, 'comment' => 'terisi saat token dipakai; token single-use'],
            'requested_at' => ['type' => 'DATETIME'],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('token_hash');
        $this->forge->addKey(['username', 'requested_at']);
        $this->forge->createTable('forgot_attempts', false, ['ENGINE' => 'InnoDB']);
    }

    public function down(): void
    {
        $this->forge->dropTable('forgot_attempts', true);
    }
}
