<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * A-01 / A-04 — Tabel login_attempts: log percobaan login (sukses & gagal) untuk lockout.
 * Kolom mengikuti simpeg_v2_local_seed.sql. Data legacy tidak dimigrasi (Mapping Migrasi Tier 2: mulai fresh).
 */
class CreateLoginAttempts extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'           => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'username'     => ['type' => 'VARCHAR', 'constraint' => 30],
            'ip_address'   => ['type' => 'VARCHAR', 'constraint' => 45, 'null' => true],
            'success'      => ['type' => 'TINYINT', 'constraint' => 1, 'unsigned' => true, 'default' => 0],
            'attempted_at' => ['type' => 'DATETIME'],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['username', 'attempted_at']);
        $this->forge->createTable('login_attempts', false, ['ENGINE' => 'InnoDB']);
    }

    public function down(): void
    {
        $this->forge->dropTable('login_attempts', true);
    }
}
