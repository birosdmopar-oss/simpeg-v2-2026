<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * A-01 — Tabel pengguna (akun login). Tier 2 Mapping Migrasi.
 *
 * Keputusan skema (lihat backend/docs/db-review/A-01-auth-schema.md untuk checklist DB Validator):
 * - TIDAK ADA kolom password_decode (temuan PENTING legacy) — diverifikasi test Tests\Auth\SchemaTest.
 * - password VARCHAR(255) NULL: Argon2id; NULL sampai login pertama sukses (lazy rehash A-02b).
 * - password_legacy VARCHAR(32) NULL: hash MD5 legacy, DEPRECATED, dikosongkan setelah rehash.
 * - FK pengguna.nip -> pegawai.nip DI-DEFER ke Fase 3 (tabel pegawai belum ada); nip tetap UNIQUE NOT NULL.
 *   FK id_unit/id_satker juga ditunda sampai master unit/satker ada (Fase 2).
 */
class CreatePengguna extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id_pengguna'         => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'nip'                 => ['type' => 'VARCHAR', 'constraint' => 20],
            'username'            => ['type' => 'VARCHAR', 'constraint' => 30],
            'password'            => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true, 'comment' => 'Argon2id; NULL sampai login pertama sukses (lazy rehash)'],
            'password_legacy'     => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => true, 'comment' => 'DEPRECATED - hash MD5 legacy, dikosongkan setelah rehash'],
            'user_level'          => ['type' => 'TINYINT', 'unsigned' => true, 'comment' => '1-8 sesuai App\Constants\Role / Matriks Role x Endpoint'],
            'id_unit'             => ['type' => 'VARCHAR', 'constraint' => 10, 'null' => true],
            'id_satker'           => ['type' => 'VARCHAR', 'constraint' => 10, 'null' => true],
            'status'              => ['type' => 'ENUM', 'constraint' => ['0', '1'], 'default' => '1', 'comment' => '1 aktif, 0 nonaktif (tidak bisa login)'],
            'last_login_at'       => ['type' => 'DATETIME', 'null' => true],
            'password_changed_at' => ['type' => 'DATETIME', 'null' => true],
            'created_at'          => ['type' => 'DATETIME', 'null' => true],
            'updated_at'          => ['type' => 'DATETIME', 'null' => true],
            'deleted_at'          => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id_pengguna');
        $this->forge->addUniqueKey('nip');
        $this->forge->addUniqueKey('username');
        $this->forge->addKey('user_level');
        $this->forge->addKey('id_satker');
        $this->forge->addKey('status');
        $this->forge->createTable('pengguna', false, ['ENGINE' => 'InnoDB']);
    }

    public function down(): void
    {
        $this->forge->dropTable('pengguna', true);
    }
}
