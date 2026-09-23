<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * G-01/G-09/G-10 — web_config (key-value bertipe) + FAQ (topic → sub_topic → article), Tier 1.
 *
 * `web_config` mengikuti DDL legacy (`id_web_config`, `config_name`, `config_value`), ditambah dua kolom v2 yang
 * diminta DoD G-09 ("tipe data per key distandarkan, bukan free-text semua"): `tipe_data` dan `keterangan`.
 * `config_name` dibuat UNIQUE (legacy tidak punya unique index — perlu dedup saat migrasi data).
 *
 * FAQ: DDL legacy belum tersedia (ISSUE-003) → kolom mengikuti `simpeg_v2_local_seed.sql`, ditambah `order`
 * (pola master 02-MasterData.md). `faq_rate` (rating artikel oleh pegawai, DoD G-10) BELUM dibuat:
 * tidak ada DDL legacy dan FK `nip` butuh tabel `pegawai` (Fase 3).
 */
class CreateWebConfigFaq extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id_web_config' => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'config_name'   => ['type' => 'VARCHAR', 'constraint' => 100],
            'config_value'  => ['type' => 'TEXT', 'null' => true],
            'tipe_data'     => [
                'type'       => 'ENUM',
                'constraint' => ['string', 'text', 'integer', 'decimal', 'time', 'boolean'],
                'default'    => 'string',
                'comment'    => 'tipe nilai config_value; divalidasi WebConfigService (G-09)',
            ],
            'keterangan' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id_web_config');
        $this->forge->addUniqueKey('config_name');
        $this->forge->createTable('web_config', false, ['ENGINE' => 'InnoDB']);

        $this->forge->addField([
            'id_topic'   => ['type' => 'VARCHAR', 'constraint' => 5],
            'nama_topic' => ['type' => 'VARCHAR', 'constraint' => 150],
            'order'      => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'status'     => ['type' => 'ENUM', 'constraint' => ['0', '1'], 'default' => '1'],
        ]);
        $this->forge->addPrimaryKey('id_topic');
        $this->forge->addKey('order');
        $this->forge->addKey('status');
        $this->forge->createTable('faq_topic', false, ['ENGINE' => 'InnoDB']);

        $this->forge->addField([
            'id_sub_topic'   => ['type' => 'VARCHAR', 'constraint' => 5],
            'id_topic'       => ['type' => 'VARCHAR', 'constraint' => 5],
            'nama_sub_topic' => ['type' => 'VARCHAR', 'constraint' => 150],
            'order'          => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'status'         => ['type' => 'ENUM', 'constraint' => ['0', '1'], 'default' => '1'],
        ]);
        $this->forge->addPrimaryKey('id_sub_topic');
        $this->forge->addKey(['id_topic', 'order']);
        $this->forge->addKey('status');
        $this->forge->addForeignKey('id_topic', 'faq_topic', 'id_topic', 'RESTRICT', 'RESTRICT', 'fk_faq_sub_topic_topic');
        $this->forge->createTable('faq_sub_topic', false, ['ENGINE' => 'InnoDB']);

        $this->forge->addField([
            'id_article'   => ['type' => 'VARCHAR', 'constraint' => 5],
            'id_sub_topic' => ['type' => 'VARCHAR', 'constraint' => 5],
            'judul'        => ['type' => 'VARCHAR', 'constraint' => 255],
            'isi'          => ['type' => 'TEXT', 'null' => true],
            'order'        => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'status'       => ['type' => 'ENUM', 'constraint' => ['0', '1'], 'default' => '1', 'comment' => '1 = published (terlihat pegawai)'],
        ]);
        $this->forge->addPrimaryKey('id_article');
        $this->forge->addKey(['id_sub_topic', 'order']);
        $this->forge->addKey('status');
        $this->forge->addForeignKey('id_sub_topic', 'faq_sub_topic', 'id_sub_topic', 'RESTRICT', 'RESTRICT', 'fk_faq_article_sub_topic');
        $this->forge->createTable('faq_article', false, ['ENGINE' => 'InnoDB']);
    }

    public function down(): void
    {
        foreach (['faq_article', 'faq_sub_topic', 'faq_topic', 'web_config'] as $table) {
            $this->forge->dropTable($table, true);
        }
    }
}
