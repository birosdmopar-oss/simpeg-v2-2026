<?php

declare(strict_types=1);

namespace Tests\Support\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Tabel dummy KHUSUS test (namespace Tests\Support) — tidak pernah dijalankan di Dev/Prod.
 * - dummy_items    : untuk BaseAuditableModel (F0-04)
 * - riwayat_dummy  : riwayat_* dummy untuk BaseSnapshotModel (F0-05)
 * - pegawai_dummy  : snapshot aktif pegawai_* dummy (F0-05)
 */
class CreateDummyTables extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'         => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'name'       => ['type' => 'VARCHAR', 'constraint' => 100],
            'qty'        => ['type' => 'INT', 'default' => 0],
            'deleted_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->createTable('dummy_items', true);

        $this->forge->addField([
            'id'         => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'nip'        => ['type' => 'VARCHAR', 'constraint' => 20],
            'id_jabatan' => ['type' => 'INT', 'null' => true],
            'tmt'        => ['type' => 'DATE', 'null' => true],
            'status'     => ['type' => 'TINYINT', 'default' => 0],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey('nip');
        $this->forge->createTable('riwayat_dummy', true);

        $this->forge->addField([
            'nip'         => ['type' => 'VARCHAR', 'constraint' => 20],
            'id_jabatan'  => ['type' => 'INT', 'null' => true],
            'tmt_jabatan' => ['type' => 'DATE', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('nip');
        $this->forge->createTable('pegawai_dummy', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('dummy_items', true);
        $this->forge->dropTable('riwayat_dummy', true);
        $this->forge->dropTable('pegawai_dummy', true);
    }
}
