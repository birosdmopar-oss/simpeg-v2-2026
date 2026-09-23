<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * G-01/G-02 — Master jabatan, unit & satker (Tier 1 Mapping Migrasi).
 * Urutan sesuai hierarki: group_jabatan → sub_group_jabatan → unit → satker → jabatan.
 *
 * Kolom mengikuti DDL legacy `simpeg01` (unit/satker/jabatan dump 2023-11; group/sub_group DDL 2020-05).
 * `satker.zonasi` = selisih menit terhadap WIB, dipakai presensi (G-02 DoD "zonasi offset waktu presensi").
 *
 * Beda yang disengaja dari legacy: `status` ENUM('0','1'), FK RESTRICT, tanpa created_at/updated_at/updated_by
 * (jejak di audit_logs), `order` ditambahkan di master yang belum punya (pola 02-MasterData.md).
 *
 * BELUM di-FK-kan (tabel induk belum ada DDL-nya — Trello ISSUE-003):
 * - `jabatan.kelas_jabatan` → `kelas_jabatan`
 * - `jabatan.id_jenjang_jf` → `jenjang_jf`
 * FK `pengguna.id_unit`/`id_satker` (A-01) juga belum bisa dipasang: tipe di `pengguna` VARCHAR(10) vs INT di sini.
 */
class CreateMasterJabatan extends Migration
{
    public function up(): void
    {
        // group_jabatan (Struktural, JFT, JFU/Pelaksana)
        $this->forge->addField([
            'id_group_jabatan'      => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'kode_group_jabatan'    => ['type' => 'VARCHAR', 'constraint' => 10, 'null' => true],
            'group_jabatan'         => ['type' => 'VARCHAR', 'constraint' => 45],
            'group_jabatan_singkat' => ['type' => 'VARCHAR', 'constraint' => 45, 'null' => true],
            'order'                 => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'status'                => ['type' => 'ENUM', 'constraint' => ['0', '1'], 'default' => '1'],
        ]);
        $this->forge->addPrimaryKey('id_group_jabatan');
        $this->forge->addKey('order');
        $this->forge->addKey('status');
        $this->forge->createTable('group_jabatan', false, ['ENGINE' => 'InnoDB']);

        $this->forge->addField([
            'id_sub_group_jabatan'      => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'id_group_jabatan'          => ['type' => 'INT', 'unsigned' => true],
            'kode_sub_group_jabatan'    => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true],
            'sub_group_jabatan'         => ['type' => 'VARCHAR', 'constraint' => 100],
            'sub_group_jabatan_singkat' => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'order'                     => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'status'                    => ['type' => 'ENUM', 'constraint' => ['0', '1'], 'default' => '1'],
        ]);
        $this->forge->addPrimaryKey('id_sub_group_jabatan');
        $this->forge->addKey(['id_group_jabatan', 'order']);
        $this->forge->addKey('status');
        $this->forge->addForeignKey('id_group_jabatan', 'group_jabatan', 'id_group_jabatan', 'RESTRICT', 'RESTRICT', 'fk_sub_group_jabatan_group_jabatan');
        $this->forge->createTable('sub_group_jabatan', false, ['ENGINE' => 'InnoDB']);

        // unit → satker (satker membawa zonasi presensi & header PDF)
        $this->forge->addField([
            'id_unit'           => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'unit'              => ['type' => 'VARCHAR', 'constraint' => 150],
            'is_upt'            => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0, 'comment' => '0 bukan UPT, 1 UPT'],
            'alamat_pdf_header' => ['type' => 'TEXT', 'null' => true],
            'tembusan_kppn'     => ['type' => 'VARCHAR', 'constraint' => 256, 'null' => true],
            'lokasi_kppn'       => ['type' => 'VARCHAR', 'constraint' => 50, 'null' => true],
            'order'             => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'status'            => ['type' => 'ENUM', 'constraint' => ['0', '1'], 'default' => '1'],
        ]);
        $this->forge->addPrimaryKey('id_unit');
        $this->forge->addKey('order');
        $this->forge->addKey('status');
        $this->forge->createTable('unit', false, ['ENGINE' => 'InnoDB']);

        $this->forge->addField([
            'id_satker'         => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'id_unit'           => ['type' => 'INT', 'unsigned' => true],
            'satker'            => ['type' => 'VARCHAR', 'constraint' => 150],
            'is_upt'            => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0, 'comment' => '0 bukan UPT, 1 UPT'],
            'alamat_pdf_header' => ['type' => 'TEXT', 'null' => true],
            'tembusan_kppn'     => ['type' => 'VARCHAR', 'constraint' => 256, 'null' => true],
            'lokasi_kppn'       => ['type' => 'VARCHAR', 'constraint' => 50, 'null' => true],
            'logo_uns'          => ['type' => 'VARCHAR', 'constraint' => 256, 'null' => true],
            'zonasi'            => ['type' => 'INT', 'default' => 0, 'comment' => 'offset menit terhadap WIB untuk presensi'],
            'order'             => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'status'            => ['type' => 'ENUM', 'constraint' => ['0', '1'], 'default' => '1'],
        ]);
        $this->forge->addPrimaryKey('id_satker');
        $this->forge->addKey(['id_unit', 'order']);
        $this->forge->addKey('status');
        $this->forge->addForeignKey('id_unit', 'unit', 'id_unit', 'RESTRICT', 'RESTRICT', 'fk_satker_unit');
        $this->forge->createTable('satker', false, ['ENGINE' => 'InnoDB']);

        $this->forge->addField([
            'id_jabatan'           => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'id_group_jabatan'     => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'id_sub_group_jabatan' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'id_satker'            => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'id_jenjang_jf'        => ['type' => 'TINYINT', 'unsigned' => true, 'null' => true, 'comment' => 'FK ke jenjang_jf menyusul (DDL legacy belum ada)'],
            'kelas_jabatan'        => ['type' => 'TINYINT', 'unsigned' => true, 'null' => true, 'comment' => 'FK ke kelas_jabatan menyusul (DDL legacy belum ada)'],
            'jabatan'              => ['type' => 'VARCHAR', 'constraint' => 250],
            'umur_pensiun'         => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'order'                => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'status'               => ['type' => 'ENUM', 'constraint' => ['0', '1'], 'default' => '1'],
        ]);
        $this->forge->addPrimaryKey('id_jabatan');
        $this->forge->addKey('jabatan');
        $this->forge->addKey(['id_sub_group_jabatan', 'order']);
        $this->forge->addKey('id_group_jabatan');
        $this->forge->addKey('id_satker');
        $this->forge->addKey('kelas_jabatan');
        $this->forge->addKey('status');
        $this->forge->addForeignKey('id_group_jabatan', 'group_jabatan', 'id_group_jabatan', 'RESTRICT', 'RESTRICT', 'fk_jabatan_group_jabatan');
        $this->forge->addForeignKey('id_sub_group_jabatan', 'sub_group_jabatan', 'id_sub_group_jabatan', 'RESTRICT', 'RESTRICT', 'fk_jabatan_sub_group_jabatan');
        $this->forge->addForeignKey('id_satker', 'satker', 'id_satker', 'RESTRICT', 'RESTRICT', 'fk_jabatan_satker');
        $this->forge->createTable('jabatan', false, ['ENGINE' => 'InnoDB']);

        // Periode & struktur jabatan (Tier 1; migrasi saja — CRUD-nya tidak termasuk DoD G-02)
        $this->forge->addField([
            'id_periode_struktur_jabatan' => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'periode_struktur_jabatan'    => ['type' => 'VARCHAR', 'constraint' => 45],
            'status'                      => ['type' => 'ENUM', 'constraint' => ['0', '1'], 'default' => '1'],
        ]);
        $this->forge->addPrimaryKey('id_periode_struktur_jabatan');
        $this->forge->addKey('status');
        $this->forge->createTable('periode_struktur_jabatan', false, ['ENGINE' => 'InnoDB']);

        $this->forge->addField([
            'id_struktur_jabatan'         => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'id_periode_struktur_jabatan' => ['type' => 'INT', 'unsigned' => true],
            'id_jabatan'                  => ['type' => 'INT', 'unsigned' => true],
            'id_jabatan_atasan'           => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'level_struktur'              => ['type' => 'INT', 'unsigned' => true, 'default' => 1],
            'urutan'                      => ['type' => 'INT', 'unsigned' => true, 'default' => 1],
        ]);
        $this->forge->addPrimaryKey('id_struktur_jabatan');
        $this->forge->addKey(['id_periode_struktur_jabatan', 'level_struktur', 'urutan']);
        $this->forge->addForeignKey('id_periode_struktur_jabatan', 'periode_struktur_jabatan', 'id_periode_struktur_jabatan', 'RESTRICT', 'RESTRICT', 'fk_struktur_jabatan_periode');
        $this->forge->addForeignKey('id_jabatan', 'jabatan', 'id_jabatan', 'RESTRICT', 'RESTRICT', 'fk_struktur_jabatan_jabatan');
        $this->forge->addForeignKey('id_jabatan_atasan', 'jabatan', 'id_jabatan', 'RESTRICT', 'RESTRICT', 'fk_struktur_jabatan_atasan');
        $this->forge->createTable('struktur_jabatan', false, ['ENGINE' => 'InnoDB']);
    }

    public function down(): void
    {
        foreach (['struktur_jabatan', 'periode_struktur_jabatan', 'jabatan', 'satker', 'unit', 'sub_group_jabatan', 'group_jabatan'] as $table) {
            $this->forge->dropTable($table, true);
        }
    }
}
