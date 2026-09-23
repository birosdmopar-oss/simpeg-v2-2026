<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * G-01/G-04/G-05 — Master kenaikan pangkat & pendidikan (Tier 1).
 * Urutan hierarki: bidang_pendidikan → jurusan_pendidikan.
 *
 * Kolom mengikuti DDL legacy `simpeg01` (dump DDL 2020-05) untuk pangkat, jenis_kp, jenjang/bidang/jurusan
 * pendidikan. `gol_pppk` tidak ada di DDL legacy yang tersedia → memakai definisi `simpeg_v2_local_seed.sql`
 * (lihat catatan review: Legacy Audit menyebut gol_pppk memuat nominal uang makan — kolom tsb belum ada sumbernya).
 *
 * Beda yang disengaja dari legacy: `status` ENUM('0','1'), FK RESTRICT, tanpa updated_at/updated_by,
 * `order` ditambahkan di master yang belum punya (bidang/jurusan pendidikan).
 * Flag legacy bernilai 1=Ya / 2=Tidak (`pangkat.cpns`, `jurusan_pendidikan.D_I`..`S_3`) dipertahankan apa adanya
 * agar migrasi data tidak perlu transformasi.
 */
class CreateMasterKpPendidikan extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id_pangkat' => ['type' => 'TINYINT', 'unsigned' => true, 'auto_increment' => true],
            'gol_ruang'  => ['type' => 'VARCHAR', 'constraint' => 10],
            'gol'        => ['type' => 'VARCHAR', 'constraint' => 10],
            'ruang'      => ['type' => 'VARCHAR', 'constraint' => 10],
            'cpns'       => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 2, 'comment' => 'legacy: 1 Ya, 2 Tidak'],
            'pangkat'    => ['type' => 'VARCHAR', 'constraint' => 50],
            'order'      => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'status'     => ['type' => 'ENUM', 'constraint' => ['0', '1'], 'default' => '1'],
        ]);
        $this->forge->addPrimaryKey('id_pangkat');
        $this->forge->addKey('order');
        $this->forge->addKey('status');
        $this->forge->createTable('pangkat', false, ['ENGINE' => 'InnoDB']);

        $this->forge->addField([
            'id_jenis_kp' => ['type' => 'TINYINT', 'unsigned' => true, 'auto_increment' => true],
            'jenis_kp'    => ['type' => 'VARCHAR', 'constraint' => 100],
            'order'       => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'status'      => ['type' => 'ENUM', 'constraint' => ['0', '1'], 'default' => '1'],
        ]);
        $this->forge->addPrimaryKey('id_jenis_kp');
        $this->forge->addKey('order');
        $this->forge->addKey('status');
        $this->forge->createTable('jenis_kp', false, ['ENGINE' => 'InnoDB']);

        $this->forge->addField([
            'id_gol_pppk'   => ['type' => 'VARCHAR', 'constraint' => 5],
            'nama_gol_pppk' => ['type' => 'VARCHAR', 'constraint' => 50],
            'order'         => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'status'        => ['type' => 'ENUM', 'constraint' => ['0', '1'], 'default' => '1'],
        ]);
        $this->forge->addPrimaryKey('id_gol_pppk');
        $this->forge->addKey('order');
        $this->forge->addKey('status');
        $this->forge->createTable('gol_pppk', false, ['ENGINE' => 'InnoDB']);

        $this->forge->addField([
            'id_jenjang_pendidikan'      => ['type' => 'TINYINT', 'unsigned' => true, 'auto_increment' => true],
            'jenjang_pendidikan'         => ['type' => 'VARCHAR', 'constraint' => 60],
            'jenjang_pendidikan_singkat' => ['type' => 'VARCHAR', 'constraint' => 15, 'null' => true],
            'row_jurusan'                => ['type' => 'VARCHAR', 'constraint' => 10, 'null' => true, 'comment' => 'kolom jurusan yang dipakai jenjang ini (D_I..S_3)'],
            'order'                      => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'status'                     => ['type' => 'ENUM', 'constraint' => ['0', '1'], 'default' => '1'],
        ]);
        $this->forge->addPrimaryKey('id_jenjang_pendidikan');
        $this->forge->addKey('order');
        $this->forge->addKey('status');
        $this->forge->createTable('jenjang_pendidikan', false, ['ENGINE' => 'InnoDB']);

        $this->forge->addField([
            'id_bidang_pendidikan'      => ['type' => 'TINYINT', 'unsigned' => true, 'auto_increment' => true],
            'bidang_pendidikan'         => ['type' => 'VARCHAR', 'constraint' => 100],
            'bidang_pendidikan_english' => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'order'                     => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'status'                    => ['type' => 'ENUM', 'constraint' => ['0', '1'], 'default' => '1'],
        ]);
        $this->forge->addPrimaryKey('id_bidang_pendidikan');
        $this->forge->addKey('order');
        $this->forge->addKey('status');
        $this->forge->createTable('bidang_pendidikan', false, ['ENGINE' => 'InnoDB']);

        $fields = [
            'id_jurusan_pendidikan'      => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'id_bidang_pendidikan'       => ['type' => 'TINYINT', 'unsigned' => true],
            'jurusan_pendidikan'         => ['type' => 'VARCHAR', 'constraint' => 100],
            'jurusan_pendidikan_english' => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'gelar'                      => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true],
        ];

        foreach (['D_I', 'D_II', 'D_III', 'D_IV', 'S_1', 'S_2', 'S_3'] as $jenjang) {
            $fields[$jenjang] = ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1, 'comment' => 'legacy: 1 Ya, 2 Tidak'];
        }

        $fields['order']  = ['type' => 'INT', 'unsigned' => true, 'default' => 0];
        $fields['status'] = ['type' => 'ENUM', 'constraint' => ['0', '1'], 'default' => '1'];

        $this->forge->addField($fields);
        $this->forge->addPrimaryKey('id_jurusan_pendidikan');
        $this->forge->addKey(['id_bidang_pendidikan', 'order']);
        $this->forge->addKey('status');
        $this->forge->addForeignKey('id_bidang_pendidikan', 'bidang_pendidikan', 'id_bidang_pendidikan', 'RESTRICT', 'RESTRICT', 'fk_jurusan_bidang_pendidikan');
        $this->forge->createTable('jurusan_pendidikan', false, ['ENGINE' => 'InnoDB']);
    }

    public function down(): void
    {
        foreach (['jurusan_pendidikan', 'bidang_pendidikan', 'jenjang_pendidikan', 'gol_pppk', 'jenis_kp', 'pangkat'] as $table) {
            $this->forge->dropTable($table, true);
        }
    }
}
