<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * G-01 (bagian 1) — Referensi wilayah 4 level. Tier 0 Mapping Migrasi ("Copy langsung").
 *
 * Kolom mengikuti DDL legacy `simpeg01` (dump 2024-11): PK `char(2|4|7|10)`, kolom nama sama dengan nama tabel
 * (`provinsi`, `kabupaten_kota`, …), plus `kd_area` (kabupaten/kota) dan `kd_pos` (kelurahan) — Mapping Prinsip #1
 * (nama tabel & kolom tetap sama).
 *
 * Beda yang disengaja dari legacy (lihat backend/docs/db-review/G-01-master-schema.md):
 * - `status` memakai ENUM('0','1') sesuai 02-MasterData.md & G-TC (legacy: tinyint 1/2/10 → dipetakan saat migrasi data).
 * - FK ON DELETE RESTRICT (legacy SET NULL): master yang direlasikan tidak boleh lepas/hilang diam-diam.
 * - Tanpa `updated_at`/`updated_by`: jejak perubahan ada di `audit_logs` (ADR-011).
 *
 * JANGAN dijalankan di Dev/Production sebelum review DB Validator disetujui.
 */
class CreateWilayah extends Migration
{
    public function up(): void
    {
        $this->createLevel('provinsi', 'id_provinsi', 2, 'provinsi');
        $this->createLevel('kabupaten_kota', 'id_kabupaten_kota', 4, 'kabupaten_kota', ['id_provinsi', 'provinsi', 2], [
            'kd_area' => ['type' => 'VARCHAR', 'constraint' => 4, 'null' => true],
        ]);
        $this->createLevel('kecamatan', 'id_kecamatan', 7, 'kecamatan', ['id_kabupaten_kota', 'kabupaten_kota', 4]);
        $this->createLevel('kelurahan', 'id_kelurahan', 10, 'kelurahan', ['id_kecamatan', 'kecamatan', 7], [
            'kd_pos' => ['type' => 'VARCHAR', 'constraint' => 50, 'null' => true],
        ]);
    }

    public function down(): void
    {
        foreach (['kelurahan', 'kecamatan', 'kabupaten_kota', 'provinsi'] as $table) {
            $this->forge->dropTable($table, true);
        }
    }

    /**
     * @param array{0: string, 1: string, 2: int}|null       $parent [kolom FK, tabel induk, panjang kode induk]
     * @param array<string, array<string, mixed>>            $extra  kolom tambahan sesuai legacy
     */
    private function createLevel(string $table, string $pk, int $idLength, string $nameColumn, ?array $parent = null, array $extra = []): void
    {
        $fields = [
            $pk => ['type' => 'CHAR', 'constraint' => $idLength, 'comment' => 'kode wilayah (BPS/Kemendagri)'],
        ];

        if ($parent !== null) {
            $fields[$parent[0]] = ['type' => 'CHAR', 'constraint' => $parent[2]];
        }

        $fields[$nameColumn] = ['type' => 'VARCHAR', 'constraint' => 255];
        $fields += $extra;
        $fields += [
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
