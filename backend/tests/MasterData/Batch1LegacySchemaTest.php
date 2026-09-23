<?php

declare(strict_types=1);

namespace Tests\MasterData;

use App\Database\Migrations\AlterBatch1KeSkemaLegacy;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use RuntimeException;

/**
 * DBV-001 — skema G-01 Batch 1 hasil migration 2026-09-23-000000_AlterBatch1KeSkemaLegacy harus sama dengan skema
 * legacy yang diajukan ke DB Validator (backend/docs/db-review/G-01-master-schema.md Bagian 8), bisa di-rollback
 * ke skema Batch 1 yang disetujui 23-09-2026, dan menolak berjalan kalau tabel sudah berisi data.
 *
 * @internal
 */
final class Batch1LegacySchemaTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = null;

    /**
     * Skema legacy yang diharapkan: tabel => [kolom => tipe kolom (information_schema.COLUMN_TYPE)].
     */
    private const LEGACY = [
        'provinsi' => [
            'id_provinsi' => 'char(2)', 'provinsi' => 'varchar(255)', 'order' => 'int unsigned', 'status' => 'tinyint',
            'created_at'  => 'datetime', 'updated_at' => 'datetime', 'updated_by' => 'int',
        ],
        'kabupaten_kota' => [
            'id_kabupaten_kota' => 'char(4)', 'id_provinsi' => 'char(2)', 'kabupaten_kota' => 'varchar(255)', 'kd_area' => 'varchar(4)',
            'order'             => 'int unsigned', 'status' => 'tinyint', 'created_at' => 'datetime', 'updated_at' => 'datetime', 'updated_by' => 'int',
        ],
        'kecamatan' => [
            'id_kecamatan' => 'char(7)', 'id_kabupaten_kota' => 'char(4)', 'kecamatan' => 'varchar(255)', 'order' => 'int unsigned',
            'status'       => 'tinyint', 'created_at' => 'datetime', 'updated_at' => 'datetime', 'updated_by' => 'int',
        ],
        'kelurahan' => [
            'id_kelurahan' => 'char(10)', 'id_kecamatan' => 'char(7)', 'kelurahan' => 'varchar(255)', 'kd_pos' => 'varchar(50)',
            'order'        => 'int unsigned', 'status' => 'tinyint', 'created_at' => 'datetime', 'updated_at' => 'datetime', 'updated_by' => 'int',
        ],
        'agama' => [
            'id_agama'   => 'tinyint', 'agama' => 'varchar(30)', 'order' => 'tinyint', 'status' => 'tinyint', 'created_at' => 'datetime',
            'updated_at' => 'datetime', 'updated_by' => 'int', 'deleted_at' => 'datetime',
        ],
        'jenis_pegawai' => [
            'id_jenis_pegawai' => 'tinyint', 'jenis_pegawai' => 'varchar(50)', 'order' => 'tinyint', 'status' => 'tinyint',
            'created_at'       => 'datetime', 'updated_at' => 'datetime', 'updated_by' => 'int',
        ],
        'jenis_status' => [
            'id_jenis_status' => 'tinyint', 'jenis_status' => 'varchar(50)', 'status_pegawai' => 'tinyint', 'order' => 'tinyint',
            'status'          => 'tinyint', 'created_at' => 'datetime', 'updated_at' => 'datetime', 'updated_by' => 'int',
        ],
    ];

    /**
     * UNIQUE index keunikan nama (ISSUE-009): tabel => [nama index, kolom berurutan].
     */
    private const UNIQUE = [
        'provinsi'       => ['uq_provinsi_nama', ['provinsi']],
        'kabupaten_kota' => ['uq_kabupaten_kota_nama', ['id_provinsi', 'kabupaten_kota']],
        'kecamatan'      => ['uq_kecamatan_nama', ['id_kabupaten_kota', 'kecamatan']],
        'kelurahan'      => ['uq_kelurahan_nama', ['id_kecamatan', 'kelurahan']],
        'agama'          => ['uq_agama_nama', ['agama']],
        'jenis_pegawai'  => ['uq_jenis_pegawai_nama', ['jenis_pegawai']],
        'jenis_status'   => ['uq_jenis_status_nama', ['status_pegawai', 'jenis_status']],
    ];

    /**
     * FK wilayah dengan nama legacy (ERD simpeg01): nama FK => [tabel anak, kolom, tabel induk], urut nama.
     */
    private const LEGACY_FK = [
        'fk_id_kabupaten_kota_kecamatan_to_kabupaten_kota' => ['kecamatan', 'id_kabupaten_kota', 'kabupaten_kota'],
        'fk_id_kecamatan_kelurahan_to_kecamatan'           => ['kelurahan', 'id_kecamatan', 'kecamatan'],
        'fk_id_provinsi_kabupaten_kota_to_provinsi'        => ['kabupaten_kota', 'id_provinsi', 'provinsi'],
    ];

    /**
     * Test di bawah memanggil down() di tengah jalan. Kalau ada assertion yang gagal sebelum up(), skema harus tetap
     * dikembalikan ke bentuk legacy (sesuai tabel migrations) — kalau tidak, migrate:refresh test berikutnya gagal.
     */
    protected function tearDown(): void
    {
        if (array_key_exists('nama_provinsi', $this->columnTypes('provinsi'))) {
            foreach (['kelurahan', 'kecamatan', 'kabupaten_kota', 'provinsi', 'agama', 'jenis_pegawai', 'jenis_status'] as $table) {
                $this->db->table($table)->emptyTable();
            }

            $this->migration()->up();
        }

        parent::tearDown();
    }

    public function testSchemaMatchesLegacyAfterMigration(): void
    {
        foreach (self::LEGACY as $table => $columns) {
            $this->assertSame($columns, $this->columnTypes($table), "kolom {$table}");
            $this->assertSame('utf8mb4_unicode_ci', $this->tableCollation($table), "collation {$table} (ISSUE-010)");
            $this->assertSame(self::UNIQUE[$table][1], $this->uniqueIndexColumns($table, self::UNIQUE[$table][0]), "UNIQUE {$table} (ISSUE-009)");
        }

        foreach (['agama' => 'id_agama', 'jenis_pegawai' => 'id_jenis_pegawai', 'jenis_status' => 'id_jenis_status'] as $table => $pk) {
            $this->assertStringContainsString('auto_increment', $this->columnExtra($table, $pk), "{$table}.{$pk} AUTO_INCREMENT");
        }

        foreach (['provinsi', 'agama'] as $table) {
            $this->assertSame('1', $this->columnDefault($table, 'status'), "{$table}.status default 1 (Aktif)");
        }

        $this->assertSame(self::LEGACY_FK, $this->foreignKeys());
    }

    /**
     * down() mengembalikan skema Batch 1 yang disetujui 23-09-2026 persis; up() kembali ke skema legacy.
     */
    public function testMigrationRollsBackToApprovedBatch1AndUpAgain(): void
    {
        $migration = $this->migration();
        $migration->down();

        $this->assertSame(
            ['id_provinsi' => 'varchar(10)', 'nama_provinsi' => 'varchar(100)', 'order' => 'int unsigned', 'status' => "enum('0','1')"],
            $this->columnTypes('provinsi'),
        );
        $this->assertSame(
            ['id_kelurahan' => 'varchar(10)', 'id_kecamatan' => 'varchar(10)', 'nama_kelurahan' => 'varchar(100)', 'order' => 'int unsigned', 'status' => "enum('0','1')"],
            $this->columnTypes('kelurahan'),
        );
        $this->assertSame(
            ['id_agama' => 'varchar(5)', 'nama_agama' => 'varchar(50)', 'order' => 'int unsigned', 'status' => "enum('0','1')"],
            $this->columnTypes('agama'),
        );
        $this->assertSame(
            ['id_jenis_status' => 'varchar(5)', 'nama_jenis_status' => 'varchar(50)', 'order' => 'int unsigned', 'status' => "enum('0','1')"],
            $this->columnTypes('jenis_status'),
        );
        $this->assertSame('utf8mb4_general_ci', $this->tableCollation('provinsi'));
        $this->assertSame([
            'fk_kabupaten_kota_provinsi'  => ['kabupaten_kota', 'id_provinsi', 'provinsi'],
            'fk_kecamatan_kabupaten_kota' => ['kecamatan', 'id_kabupaten_kota', 'kabupaten_kota'],
            'fk_kelurahan_kecamatan'      => ['kelurahan', 'id_kecamatan', 'kecamatan'],
        ], $this->foreignKeys());

        $migration->up();

        foreach (self::LEGACY as $table => $columns) {
            $this->assertSame($columns, $this->columnTypes($table), "kolom {$table} setelah up() ulang");
        }

        $this->assertSame(self::LEGACY_FK, $this->foreignKeys());
    }

    /**
     * up() hanya boleh jalan selagi tabel Batch 1 kosong: tidak ada ALTER yang dijalankan kalau ada data.
     */
    public function testUpRefusesToRunWhenBatch1TablesHaveData(): void
    {
        $migration = $this->migration();
        $migration->down();
        $this->db->table('provinsi')->insert(['id_provinsi' => '31', 'nama_provinsi' => 'DKI Jakarta', 'status' => '1']);

        try {
            $migration->up();
            $this->fail('Migration DBV-001 harus menolak berjalan saat tabel berisi data.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('provinsi (1 baris)', $e->getMessage());
        }

        // Skema tidak berubah sedikit pun (masih skema Batch 1 yang disetujui).
        $this->assertSame(
            ['id_provinsi' => 'varchar(10)', 'nama_provinsi' => 'varchar(100)', 'order' => 'int unsigned', 'status' => "enum('0','1')"],
            $this->columnTypes('provinsi'),
        );
        $this->assertArrayHasKey('fk_kabupaten_kota_provinsi', $this->foreignKeys());

        $this->db->table('provinsi')->where('id_provinsi', '31')->delete();
        $migration->up();
        $this->assertSame(self::LEGACY['provinsi'], $this->columnTypes('provinsi'));
    }

    /**
     * down() mempertahankan data (dipakai migrate:refresh): status legacy 1 → '1', 2/10 → '0'.
     */
    public function testDownPreservesDataAndMapsLegacyStatus(): void
    {
        $this->db->table('provinsi')->insertBatch([
            ['id_provinsi' => '31', 'provinsi' => 'DKI Jakarta', 'status' => 1],
            ['id_provinsi' => '32', 'provinsi' => 'Jawa Barat', 'status' => 2],
            ['id_provinsi' => '33', 'provinsi' => 'Jawa Tengah', 'status' => 10],
        ]);
        $this->db->table('agama')->insert(['id_agama' => 1, 'agama' => 'Islam', 'status' => 1]);

        $this->migration()->down();

        $rows = $this->db->table('provinsi')->orderBy('id_provinsi')->get()->getResultArray();
        $this->assertSame(
            [['31', 'DKI Jakarta', '1'], ['32', 'Jawa Barat', '0'], ['33', 'Jawa Tengah', '0']],
            array_map(static fn (array $r): array => [$r['id_provinsi'], $r['nama_provinsi'], $r['status']], $rows),
        );
        $this->seeInDatabase('agama', ['id_agama' => '1', 'nama_agama' => 'Islam', 'status' => '1']);

        // Kembalikan ke skema legacy (tercatat sudah dimigrasi) agar migrate:refresh test berikutnya konsisten.
        foreach (['provinsi', 'agama'] as $table) {
            $this->db->table($table)->emptyTable();
        }

        $this->migration()->up();
        $this->assertSame(self::LEGACY['provinsi'], $this->columnTypes('provinsi'));
    }

    private function migration(): AlterBatch1KeSkemaLegacy
    {
        require_once APPPATH . 'Database/Migrations/2026-09-23-000000_AlterBatch1KeSkemaLegacy.php';

        return new AlterBatch1KeSkemaLegacy();
    }

    /**
     * @return array<string, string> kolom => COLUMN_TYPE, urut posisi
     */
    private function columnTypes(string $table): array
    {
        $rows = $this->db->query(
            'SELECT COLUMN_NAME, COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION',
            [$this->db->getDatabase(), $this->db->prefixTable($table)],
        )->getResultArray();

        $types = [];

        foreach ($rows as $row) {
            // MariaDB/MySQL lama menulis lebar tampilan (tinyint(4), int(11)); samakan dengan MySQL 8.
            $types[(string) $row['COLUMN_NAME']] = (string) preg_replace('/^(tinyint|int)\(\d+\)/', '$1', strtolower((string) $row['COLUMN_TYPE']));
        }

        return $types;
    }

    private function columnExtra(string $table, string $column): string
    {
        return strtolower((string) $this->columnInfo($table, $column)['EXTRA']);
    }

    private function columnDefault(string $table, string $column): string
    {
        return trim((string) $this->columnInfo($table, $column)['COLUMN_DEFAULT'], "'");
    }

    /**
     * @return array<string, mixed>
     */
    private function columnInfo(string $table, string $column): array
    {
        return $this->db->query(
            'SELECT EXTRA, COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$this->db->getDatabase(), $this->db->prefixTable($table), $column],
        )->getRowArray() ?? [];
    }

    private function tableCollation(string $table): string
    {
        return (string) $this->db->query(
            'SELECT TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
            [$this->db->getDatabase(), $this->db->prefixTable($table)],
        )->getRowArray()['TABLE_COLLATION'];
    }

    /**
     * @return list<string>
     */
    private function uniqueIndexColumns(string $table, string $index): array
    {
        $rows = $this->db->query(
            'SELECT COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ? AND NON_UNIQUE = 0 ORDER BY SEQ_IN_INDEX',
            [$this->db->getDatabase(), $this->db->prefixTable($table), $index],
        )->getResultArray();

        return array_map(static fn (array $r): string => (string) $r['COLUMN_NAME'], $rows);
    }

    /**
     * FK di antara tabel wilayah: nama => [tabel anak, kolom, tabel induk] (tanpa prefix), urut nama.
     *
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    private function foreignKeys(): array
    {
        $prefix = $this->db->getPrefix();
        $tables = array_map(fn (string $t): string => $this->db->prefixTable($t), ['kabupaten_kota', 'kecamatan', 'kelurahan']);

        $rows = $this->db->query(
            'SELECT CONSTRAINT_NAME, TABLE_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = ? AND REFERENCED_TABLE_NAME IS NOT NULL AND TABLE_NAME IN ? ORDER BY CONSTRAINT_NAME',
            [$this->db->getDatabase(), $tables],
        )->getResultArray();

        $strip = static fn (string $name): string => $prefix !== '' && str_starts_with($name, $prefix) ? substr($name, strlen($prefix)) : $name;
        $fks   = [];

        foreach ($rows as $row) {
            $fks[(string) $row['CONSTRAINT_NAME']] = [$strip((string) $row['TABLE_NAME']), (string) $row['COLUMN_NAME'], $strip((string) $row['REFERENCED_TABLE_NAME'])];
        }

        ksort($fks);

        return $fks;
    }
}
