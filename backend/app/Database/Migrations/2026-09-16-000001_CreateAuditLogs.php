<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * F0-04 — Tabel audit_logs generik, event-based (ADR-011). Tier 9 skema v2.
 * Kolom mengikuti Tech Spec F0-04 dan simpeg_v2_local_seed.sql (audit_logs).
 * Catatan SOP: migration wajib direview DB Validator sebelum dijalankan di Dev/Prod.
 */
class CreateAuditLogs extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id_log'      => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'nip_actor'   => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true, 'comment' => 'NIP pelaku; NULL untuk proses sistem/CLI'],
            'entity'      => ['type' => 'VARCHAR', 'constraint' => 100, 'comment' => 'nama tabel/model yang diubah'],
            'entity_id'   => ['type' => 'VARCHAR', 'constraint' => 50],
            'event'       => ['type' => 'ENUM', 'constraint' => ['create', 'update', 'delete']],
            'before_json' => ['type' => 'JSON', 'null' => true],
            'after_json'  => ['type' => 'JSON', 'null' => true],
            'created_at'  => ['type' => 'DATETIME', 'null' => false],
        ]);
        $this->forge->addPrimaryKey('id_log');
        $this->forge->addKey(['entity', 'entity_id']);
        $this->forge->addKey('nip_actor');
        $this->forge->addKey('created_at');
        $this->forge->createTable('audit_logs', false, ['ENGINE' => 'InnoDB']);
    }

    public function down(): void
    {
        $this->forge->dropTable('audit_logs', true);
    }
}
