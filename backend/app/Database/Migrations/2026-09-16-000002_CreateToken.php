<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * F0-06 — Tabel token: penyimpanan HASH refresh token (ADR-004).
 * FK token.nip -> pegawai.nip belum bisa dipasang di Fase 0 karena tabel pegawai baru dibuat di Fase 3 (B-01);
 * tambahkan FK lewat migration terpisah setelah pegawai tersedia (dicatat sebagai tindak lanjut).
 */
class CreateToken extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'          => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'nip'         => ['type' => 'VARCHAR', 'constraint' => 20],
            'token_hash'  => ['type' => 'CHAR', 'constraint' => 64, 'comment' => 'SHA-256 hex dari refresh token; plaintext tidak disimpan'],
            'claims_json' => ['type' => 'JSON', 'null' => true, 'comment' => 'claims (role, id_unit, id_satker) untuk re-issue saat refresh'],
            'expires_at'  => ['type' => 'DATETIME'],
            'revoked'     => ['type' => 'TINYINT', 'constraint' => 1, 'unsigned' => true, 'default' => 0],
            'revoked_at'  => ['type' => 'DATETIME', 'null' => true],
            'created_at'  => ['type' => 'DATETIME'],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('token_hash');
        $this->forge->addKey('nip');
        $this->forge->addKey('expires_at');
        $this->forge->createTable('token', false, ['ENGINE' => 'InnoDB']);
    }

    public function down(): void
    {
        $this->forge->dropTable('token', true);
    }
}
