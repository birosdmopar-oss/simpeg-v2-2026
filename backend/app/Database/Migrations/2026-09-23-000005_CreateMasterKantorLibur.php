<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * G-01/G-07/G-08 — Kantor (Tier 0) + jenis libur & hari libur (Tier 0/1).
 *
 * DDL legacy untuk `kantor` dan `jenis_libur` tidak tersedia; kolom mengikuti `simpeg_v2_local_seed.sql`,
 * ditambah FK wilayah pada `kantor` sesuai relasi di ERD legacy `simpeg01.erd`
 * (kantor → provinsi, kabupaten_kota, kecamatan, kelurahan).
 *
 * `hari_libur` legacy bernama `libur` dengan kolom CamelCase (Tahun, KdJnsLibur, NamaLibur, TglMulai, TglAkhir,
 * Keterangan). Di v2 memakai nama `hari_libur` + snake_case sesuai Mapping Migrasi Tier 1, Tech Spec G-08, dan
 * seed lokal (butuh transformasi saat migrasi data — dicatat di review DB Validator).
 * Validasi tgl_mulai <= tgl_akhir dan larangan overlap ditegakkan aplikasi (HariLiburService, G-08 DoD).
 */
class CreateMasterKantorLibur extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id_kantor'         => ['type' => 'VARCHAR', 'constraint' => 5],
            'nama_kantor'       => ['type' => 'VARCHAR', 'constraint' => 150],
            'alamat'            => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'id_provinsi'       => ['type' => 'CHAR', 'constraint' => 2, 'null' => true],
            'id_kabupaten_kota' => ['type' => 'CHAR', 'constraint' => 4, 'null' => true],
            'id_kecamatan'      => ['type' => 'CHAR', 'constraint' => 7, 'null' => true],
            'id_kelurahan'      => ['type' => 'CHAR', 'constraint' => 10, 'null' => true],
            'order'             => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'status'            => ['type' => 'ENUM', 'constraint' => ['0', '1'], 'default' => '1'],
        ]);
        $this->forge->addPrimaryKey('id_kantor');
        $this->forge->addKey('order');
        $this->forge->addKey('status');
        $this->forge->addForeignKey('id_provinsi', 'provinsi', 'id_provinsi', 'RESTRICT', 'RESTRICT', 'fk_kantor_provinsi');
        $this->forge->addForeignKey('id_kabupaten_kota', 'kabupaten_kota', 'id_kabupaten_kota', 'RESTRICT', 'RESTRICT', 'fk_kantor_kabupaten_kota');
        $this->forge->addForeignKey('id_kecamatan', 'kecamatan', 'id_kecamatan', 'RESTRICT', 'RESTRICT', 'fk_kantor_kecamatan');
        $this->forge->addForeignKey('id_kelurahan', 'kelurahan', 'id_kelurahan', 'RESTRICT', 'RESTRICT', 'fk_kantor_kelurahan');
        $this->forge->createTable('kantor', false, ['ENGINE' => 'InnoDB']);

        $this->forge->addField([
            'id_jenis_libur'   => ['type' => 'VARCHAR', 'constraint' => 5],
            'nama_jenis_libur' => ['type' => 'VARCHAR', 'constraint' => 100],
            'order'            => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'status'           => ['type' => 'ENUM', 'constraint' => ['0', '1'], 'default' => '1'],
        ]);
        $this->forge->addPrimaryKey('id_jenis_libur');
        $this->forge->addKey('order');
        $this->forge->addKey('status');
        $this->forge->createTable('jenis_libur', false, ['ENGINE' => 'InnoDB']);

        $this->forge->addField([
            'id_hari_libur'  => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'id_jenis_libur' => ['type' => 'VARCHAR', 'constraint' => 5],
            'nama'           => ['type' => 'VARCHAR', 'constraint' => 150],
            'tgl_mulai'      => ['type' => 'DATE'],
            'tgl_akhir'      => ['type' => 'DATE'],
            'keterangan'     => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'status'         => ['type' => 'ENUM', 'constraint' => ['0', '1'], 'default' => '1'],
        ]);
        $this->forge->addPrimaryKey('id_hari_libur');
        $this->forge->addKey(['tgl_mulai', 'tgl_akhir']);
        $this->forge->addKey('id_jenis_libur');
        $this->forge->addKey('status');
        $this->forge->addForeignKey('id_jenis_libur', 'jenis_libur', 'id_jenis_libur', 'RESTRICT', 'RESTRICT', 'fk_hari_libur_jenis_libur');
        $this->forge->createTable('hari_libur', false, ['ENGINE' => 'InnoDB']);
    }

    public function down(): void
    {
        foreach (['hari_libur', 'jenis_libur', 'kantor'] as $table) {
            $this->forge->dropTable($table, true);
        }
    }
}
