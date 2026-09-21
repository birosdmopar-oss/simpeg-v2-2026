<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * A-10 — Tambah nilai event 'login' dan 'logout' ke audit_logs.event agar audit trail auth
 * bisa dibedakan dari perubahan data biasa (create/update/delete) tanpa membaca JSON.
 */
class AlterAuditLogsEventAddAuth extends Migration
{
    public function up(): void
    {
        $this->forge->modifyColumn('audit_logs', [
            'event' => ['name' => 'event', 'type' => 'ENUM', 'constraint' => ['create', 'update', 'delete', 'login', 'logout'], 'null' => false],
        ]);
    }

    public function down(): void
    {
        // Rollback: baris dengan event auth tidak muat di ENUM lama → hapus dulu (strict mode menolak truncation).
        $this->db->table('audit_logs')->whereIn('event', ['login', 'logout'])->delete();

        $this->forge->modifyColumn('audit_logs', [
            'event' => ['name' => 'event', 'type' => 'ENUM', 'constraint' => ['create', 'update', 'delete'], 'null' => false],
        ]);
    }
}
