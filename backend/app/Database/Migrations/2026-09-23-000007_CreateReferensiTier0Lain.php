<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * G-01 — Sisa referensi Tier 0 Mapping Migrasi yang dipakai modul lain (bukan CRUD Modul G):
 * `jenis_layanan` (Modul C, Fase 4) dan `question_category` (Halo Simpeg, Fase 8).
 *
 * Kolom mengikuti DDL legacy `simpeg01` (DDL 2020-05). `status` di question_category legacy sudah 0/1,
 * jenis_layanan legacy tidak punya status/order — dipertahankan apa adanya (tabel konfigurasi, bukan master
 * dropdown). CRUD-nya bukan bagian DoD Modul G, jadi tabel ini hanya dimigrasikan.
 *
 * BELUM dibuat (Tier 0, DDL legacy belum tersedia — Trello ISSUE-003): `bidang_kursem`, `instansi_kursem`.
 */
class CreateReferensiTier0Lain extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id_jenis_layanan' => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'jenis_layanan'    => ['type' => 'VARCHAR', 'constraint' => 50],
            'table'            => ['type' => 'VARCHAR', 'constraint' => 50, 'comment' => 'tabel riwayat tujuan layanan (legacy)'],
            'module_name'      => ['type' => 'VARCHAR', 'constraint' => 50],
            'url_process'      => ['type' => 'VARCHAR', 'constraint' => 100],
        ]);
        $this->forge->addPrimaryKey('id_jenis_layanan');
        $this->forge->createTable('jenis_layanan', false, ['ENGINE' => 'InnoDB']);

        $this->forge->addField([
            'id_question_category' => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'question_category'    => ['type' => 'VARCHAR', 'constraint' => 250],
            'order'                => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'status'               => ['type' => 'ENUM', 'constraint' => ['0', '1'], 'default' => '1'],
        ]);
        $this->forge->addPrimaryKey('id_question_category');
        $this->forge->addKey('question_category');
        $this->forge->addKey('order');
        $this->forge->addKey('status');
        $this->forge->createTable('question_category', false, ['ENGINE' => 'InnoDB']);
    }

    public function down(): void
    {
        $this->forge->dropTable('question_category', true);
        $this->forge->dropTable('jenis_layanan', true);
    }
}
