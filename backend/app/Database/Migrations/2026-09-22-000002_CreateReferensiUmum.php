<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * G-01 (bagian 2) — Referensi umum: agama, jenis_pegawai, jenis_status. Tier 0 Mapping Migrasi ("Copy langsung").
 *
 * Kolom mengikuti simpeg_v2_local_seed.sql (ERD legacy: tabel independen, tanpa FK keluar).
 * `kantor` sengaja BELUM dibuat: legacy punya FK ke provinsi/kabupaten_kota/kecamatan/kelurahan yang tidak ada
 * di seed — menunggu DDL legacy (Trello ISSUE-003).
 *
 * Review DB Validator: backend/docs/db-review/G-01-master-schema.md.
 */
class CreateReferensiUmum extends Migration
{
    /**
     * @var array<string, array{0: string, 1: string, 2: int}> tabel => [pk, kolom nama, panjang nama]
     */
    private const TABLES = [
        'agama'         => ['id_agama', 'nama_agama', 50],
        'jenis_pegawai' => ['id_jenis_pegawai', 'nama_jenis_pegawai', 50],
        'jenis_status'  => ['id_jenis_status', 'nama_jenis_status', 50],
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table => [$pk, $name, $length]) {
            $this->forge->addField([
                $pk      => ['type' => 'VARCHAR', 'constraint' => 5],
                $name    => ['type' => 'VARCHAR', 'constraint' => $length],
                'order'  => ['type' => 'INT', 'unsigned' => true, 'default' => 0, 'comment' => 'urutan tampil'],
                'status' => ['type' => 'ENUM', 'constraint' => ['0', '1'], 'default' => '1', 'comment' => '1 aktif, 0 non-aktif/soft delete'],
            ]);
            $this->forge->addPrimaryKey($pk);
            $this->forge->addKey('order');
            $this->forge->addKey('status');
            $this->forge->createTable($table, false, ['ENGINE' => 'InnoDB']);
        }
    }

    public function down(): void
    {
        foreach (array_reverse(array_keys(self::TABLES)) as $table) {
            $this->forge->dropTable($table, true);
        }
    }
}
