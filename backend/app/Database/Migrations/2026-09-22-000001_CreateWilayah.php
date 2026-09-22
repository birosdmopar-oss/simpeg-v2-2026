<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * G-01 (bagian 1) — Referensi wilayah 4 level. Tier 0 Mapping Migrasi ("Copy langsung").
 *
 * Kolom mengikuti simpeg_v2_local_seed.sql; hierarki FK sesuai ERD legacy simpeg01
 * (provinsi → kabupaten_kota → kecamatan → kelurahan). Legacy tidak punya FK fisik — di v2 FK aktif
 * dengan ON DELETE RESTRICT: master yang direlasikan tidak bisa di-hard-delete (G-TC soft-delete only).
 * Pola master standar: `order` (urutan tampil, per induk) + `status` ('1' aktif, '0' non-aktif/soft delete).
 *
 * Review DB Validator: backend/docs/db-review/G-01-master-schema.md — JANGAN dijalankan di Dev/Production
 * sebelum disetujui.
 */
class CreateWilayah extends Migration
{
    public function up(): void
    {
        $this->createLevel('provinsi', 'id_provinsi', 'nama_provinsi');
        $this->createLevel('kabupaten_kota', 'id_kabupaten_kota', 'nama_kabupaten_kota', ['id_provinsi', 'provinsi']);
        $this->createLevel('kecamatan', 'id_kecamatan', 'nama_kecamatan', ['id_kabupaten_kota', 'kabupaten_kota']);
        $this->createLevel('kelurahan', 'id_kelurahan', 'nama_kelurahan', ['id_kecamatan', 'kecamatan']);
    }

    public function down(): void
    {
        foreach (['kelurahan', 'kecamatan', 'kabupaten_kota', 'provinsi'] as $table) {
            $this->forge->dropTable($table, true);
        }
    }

    /**
     * @param array{0: string, 1: string}|null $parent [kolom FK, tabel induk]
     */
    private function createLevel(string $table, string $pk, string $name, ?array $parent = null): void
    {
        $fields = [
            $pk => ['type' => 'VARCHAR', 'constraint' => 10, 'comment' => 'kode wilayah (BPS/Kemendagri), tanpa titik'],
        ];

        if ($parent !== null) {
            $fields[$parent[0]] = ['type' => 'VARCHAR', 'constraint' => 10];
        }

        $fields += [
            $name    => ['type' => 'VARCHAR', 'constraint' => 100],
            'order'  => ['type' => 'INT', 'unsigned' => true, 'default' => 0, 'comment' => 'urutan tampil, per induk'],
            'status' => ['type' => 'ENUM', 'constraint' => ['0', '1'], 'default' => '1', 'comment' => '1 aktif, 0 non-aktif/soft delete'],
        ];

        $this->forge->addField($fields);
        $this->forge->addPrimaryKey($pk);

        if ($parent !== null) {
            $this->forge->addKey([$parent[0], 'order']);
            $this->forge->addForeignKey($parent[0], $parent[1], $parent[0], 'RESTRICT', 'RESTRICT', "fk_{$table}_{$parent[1]}");
        } else {
            $this->forge->addKey('order');
        }

        $this->forge->addKey('status');
        $this->forge->createTable($table, false, ['ENGINE' => 'InnoDB']);
    }
}
