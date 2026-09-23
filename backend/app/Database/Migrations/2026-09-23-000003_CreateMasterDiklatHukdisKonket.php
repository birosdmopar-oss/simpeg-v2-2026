<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * G-01/G-06 — Master diklat, hukuman disiplin, kondisi kerja, tanda jasa (Tier 1).
 * Urutan hierarki: tingkat_hukdis → jenis_hukdis.
 *
 * Kolom mengikuti DDL legacy `simpeg01` (DDL 2020-05) untuk diklat, tingkat_hukdis, jenis_hukdis, tanda_jasa.
 * `jenis_konket` tidak ada di DDL legacy → memakai `simpeg_v2_local_seed.sql`, termasuk flag `affect_tukin`
 * yang diminta eksplisit oleh DoD G-06 (dipakai kalkulasi Tukin Fase 5).
 */
class CreateMasterDiklatHukdisKonket extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id_diklat'    => ['type' => 'TINYINT', 'unsigned' => true, 'auto_increment' => true],
            'jenis_diklat' => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 1, 'comment' => 'legacy: 1 Struktural, 2 Teknis'],
            'nama_diklat'  => ['type' => 'VARCHAR', 'constraint' => 255],
            'order'        => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'status'       => ['type' => 'ENUM', 'constraint' => ['0', '1'], 'default' => '1'],
        ]);
        $this->forge->addPrimaryKey('id_diklat');
        $this->forge->addKey('order');
        $this->forge->addKey('status');
        $this->forge->createTable('diklat', false, ['ENGINE' => 'InnoDB']);

        $this->forge->addField([
            'id_tingkat_hukdis' => ['type' => 'TINYINT', 'unsigned' => true, 'auto_increment' => true],
            'tingkat_hukdis'    => ['type' => 'VARCHAR', 'constraint' => 100],
            'order'             => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'status'            => ['type' => 'ENUM', 'constraint' => ['0', '1'], 'default' => '1'],
        ]);
        $this->forge->addPrimaryKey('id_tingkat_hukdis');
        $this->forge->addKey('order');
        $this->forge->addKey('status');
        $this->forge->createTable('tingkat_hukdis', false, ['ENGINE' => 'InnoDB']);

        $this->forge->addField([
            'id_jenis_hukdis'   => ['type' => 'TINYINT', 'unsigned' => true, 'auto_increment' => true],
            'id_tingkat_hukdis' => ['type' => 'TINYINT', 'unsigned' => true],
            'jenis_hukdis'      => ['type' => 'VARCHAR', 'constraint' => 255],
            'order'             => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'status'            => ['type' => 'ENUM', 'constraint' => ['0', '1'], 'default' => '1'],
        ]);
        $this->forge->addPrimaryKey('id_jenis_hukdis');
        $this->forge->addKey(['id_tingkat_hukdis', 'order']);
        $this->forge->addKey('status');
        $this->forge->addForeignKey('id_tingkat_hukdis', 'tingkat_hukdis', 'id_tingkat_hukdis', 'RESTRICT', 'RESTRICT', 'fk_jenis_hukdis_tingkat_hukdis');
        $this->forge->createTable('jenis_hukdis', false, ['ENGINE' => 'InnoDB']);

        $this->forge->addField([
            'id_jenis_konket'   => ['type' => 'VARCHAR', 'constraint' => 5],
            'nama_jenis_konket' => ['type' => 'VARCHAR', 'constraint' => 100],
            'affect_tukin'      => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0, 'comment' => '1 = memotong/mempengaruhi tukin (dipakai kalkulasi Fase 5)'],
            'order'             => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'status'            => ['type' => 'ENUM', 'constraint' => ['0', '1'], 'default' => '1'],
        ]);
        $this->forge->addPrimaryKey('id_jenis_konket');
        $this->forge->addKey('order');
        $this->forge->addKey('status');
        $this->forge->createTable('jenis_konket', false, ['ENGINE' => 'InnoDB']);

        $this->forge->addField([
            'id_tanda_jasa' => ['type' => 'TINYINT', 'unsigned' => true, 'auto_increment' => true],
            'tanda_jasa'    => ['type' => 'VARCHAR', 'constraint' => 255],
            'order'         => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'status'        => ['type' => 'ENUM', 'constraint' => ['0', '1'], 'default' => '1'],
        ]);
        $this->forge->addPrimaryKey('id_tanda_jasa');
        $this->forge->addKey('order');
        $this->forge->addKey('status');
        $this->forge->createTable('tanda_jasa', false, ['ENGINE' => 'InnoDB']);
    }

    public function down(): void
    {
        foreach (['tanda_jasa', 'jenis_konket', 'jenis_hukdis', 'tingkat_hukdis', 'diklat'] as $table) {
            $this->forge->dropTable($table, true);
        }
    }
}
