<?php

declare(strict_types=1);

namespace Tests\MasterData;

use App\Database\Migrations\CreateLokasiPresensi;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Libraries\TipeKolomSkema;

/**
 * DBV-007 — skema G-03 hasil migration 2026-10-01-000001_CreateLokasiPresensi harus sama dengan dokumen DB Validator
 * (backend/docs/db-review/G-03-lokasi-presensi-schema.md): DDL produksi `lokasi_presensi` (D1:2212-2224) dan
 * `dm_user_lokasi_presensi` (D1:474-493) [K] — kolom, tipe, nullability, default, urutan, komentar, collation — plus
 * deviasi [V2]: kolom `dm_user_lokasi_presensi.status` (paling akhir), KEY `status` di kedua tabel, komentar status
 * 1/2/10 dan kolom *_by. down() hanya men-DROP dua tabel batch ini dan up() bisa dijalankan ulang.
 *
 * @internal
 */
#[Group('db-isolasi-penuh')]
final class LokasiPresensiSchemaTest extends DatabaseTestCase
{
    private const TABLES = ['lokasi_presensi', 'dm_user_lokasi_presensi'];

    private const STATUS_COMMENT = '1: Aktif, 2: Tidak Aktif, 10: Dihapus';

    /**
     * Kolom: tabel => [kolom => [COLUMN_TYPE ternormal (TipeKolomSkema), nullable, default, komentar]] urut posisi.
     * Default `null` = tidak ada default / DEFAULT NULL.
     */
    private const COLUMNS = [
        'lokasi_presensi' => [
            'id_lokasi_presensi' => ['int', false, null, ''],
            'nama_lokasi'        => ['varchar(255)', false, null, ''],
            'latitude'           => ['varchar(50)', false, null, ''],
            'longitude'          => ['varchar(50)', false, null, ''],
            'radius'             => ['double', false, '0', 'Meter'],
            'status'             => ['tinyint', false, '1', self::STATUS_COMMENT],
            'created_at'         => ['datetime', false, 'CURRENT_TIMESTAMP', ''],
            'created_by'         => ['int', true, null, 'id_pengguna pembuat'],
            'updated_at'         => ['datetime', true, null, ''],
            'updated_by'         => ['int', true, null, 'id_pengguna yang terakhir mengubah'],
        ],
        'dm_user_lokasi_presensi' => [
            'id_dm_user_lokasi_presensi' => ['int', false, null, ''],
            'target_lp'                  => ['mediumtext', false, null, ''],
            'target_lp_desc'             => ['mediumtext', false, null, ''],
            'target_uns'                 => ['mediumtext', true, null, ''],
            'target_uns_desc'            => ['mediumtext', true, null, ''],
            'target_jp'                  => ['mediumtext', true, null, ''],
            'target_jp_desc'             => ['mediumtext', true, null, ''],
            'target_peg'                 => ['mediumtext', true, null, ''],
            'target_peg_desc'            => ['mediumtext', true, null, ''],
            'excl_peg'                   => ['mediumtext', true, null, ''],
            'excl_peg_desc'              => ['mediumtext', true, null, ''],
            'keterangan'                 => ['text', true, null, ''],
            'created_at'                 => ['datetime', false, 'CURRENT_TIMESTAMP', ''],
            'created_by'                 => ['int', true, null, 'id_pengguna pembuat'],
            'updated_at'                 => ['datetime', true, null, ''],
            'updated_by'                 => ['int', true, null, 'id_pengguna yang terakhir mengubah'],
            'hari_berlaku'               => ['varchar(20)', true, null, 'Hari berlaku geotagging, ISO: 1=Senin..7=Minggu, dipisah koma. NULL = setiap hari'],
            'status'                     => ['tinyint', false, '1', self::STATUS_COMMENT],
        ],
    ];

    /**
     * Index: tabel => [nama => [NON_UNIQUE, kolom]].
     */
    private const INDEXES = [
        'lokasi_presensi'         => ['PRIMARY' => [0, ['id_lokasi_presensi']], 'status' => [1, ['status']]],
        'dm_user_lokasi_presensi' => ['PRIMARY' => [0, ['id_dm_user_lokasi_presensi']], 'status' => [1, ['status']]],
    ];

    protected function tearDown(): void
    {
        // Bila assertion gagal di tengah siklus down()/up(), pulihkan tabel agar test berikutnya konsisten.
        foreach (self::TABLES as $table) {
            if (! $this->db->tableExists($table, false)) {
                $migration = $this->migration();
                $migration->down();
                $migration->up();

                break;
            }
        }

        parent::tearDown();
    }

    public function testSchemaMatchesProposal(): void
    {
        $this->assertSchema();
    }

    public function testRollbackDropsOnlyBatchTablesAndCanBeReapplied(): void
    {
        $before    = $this->tableNames();
        $migration = $this->migration();

        $migration->down();

        $prefixed = array_map(fn (string $t): string => $this->db->prefixTable($t), self::TABLES);
        $this->assertSame(array_values(array_diff($before, $prefixed)), $this->tableNames(), 'down() hanya men-DROP tabel DBV-007');

        $migration->up();
        $this->assertSame($before, $this->tableNames());
        $this->assertSchema();
    }

    public function testRulesUseDerivedNameAndNoOrder(): void
    {
        $registry = service('masterRegistry');
        $aturan   = $registry->get('aturan-lokasi-presensi');
        $lokasi   = $registry->get('lokasi-presensi');

        $this->assertFalse($aturan->nameRequired);
        $this->assertTrue($lokasi->nameRequired);
        $this->assertFalse($aturan->hasOrder);
        $this->assertFalse($lokasi->hasOrder);
        $this->assertFalse($aturan->toMeta()['name_required']);
    }

    private function assertSchema(): void
    {
        $schema = $this->db->getDatabase();

        foreach (self::COLUMNS as $table => $expected) {
            $table = $this->db->prefixTable($table);

            $info = $this->db->query(
                'SELECT ENGINE, TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
                [$schema, $table],
            )->getRowArray();
            $this->assertSame(['InnoDB', 'utf8mb4_unicode_ci'], [$info['ENGINE'] ?? null, $info['TABLE_COLLATION'] ?? null], $table);

            $rows = $this->db->query(
                'SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, COLUMN_COMMENT, COLLATION_NAME, EXTRA
                 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION',
                [$schema, $table],
            )->getResultArray();

            $this->assertSame(array_keys($expected), array_column($rows, 'COLUMN_NAME'), "{$table}: urutan kolom");

            foreach ($rows as $row) {
                [$type, $nullable, $default, $comment] = $expected[$row['COLUMN_NAME']];
                $label                                 = "{$table}.{$row['COLUMN_NAME']}";

                // MariaDB menulis lebar tampilan (int(11), tinyint(4)); dibuang sebelum dibandingkan (T-1 review DBV-007).
                $this->assertSame($type, TipeKolomSkema::normalisasi((string) $row['COLUMN_TYPE']), "{$label}: tipe");
                $this->assertSame($nullable ? 'YES' : 'NO', $row['IS_NULLABLE'], "{$label}: nullable");
                $this->assertSame($default, $this->normalizeDefault($row['COLUMN_DEFAULT']), "{$label}: default");
                $this->assertSame($comment, $row['COLUMN_COMMENT'], "{$label}: komentar");

                if (preg_match('/char|text/', $type) === 1) {
                    $this->assertSame('utf8mb4_unicode_ci', $row['COLLATION_NAME'], "{$label}: collation");
                }
            }

            $this->assertStringContainsString('auto_increment', strtolower((string) $rows[0]['EXTRA']), "{$table}: PK AUTO_INCREMENT");
            $updatedAt = array_values(array_filter($rows, static fn (array $r): bool => $r['COLUMN_NAME'] === 'updated_at'))[0];
            $this->assertStringContainsString('on update current_timestamp', strtolower((string) $updatedAt['EXTRA']), "{$table}.updated_at");
        }

        foreach (self::INDEXES as $table => $expected) {
            $table = $this->db->prefixTable($table);
            $rows  = $this->db->query(
                'SELECT INDEX_NAME, NON_UNIQUE, COLUMN_NAME FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY INDEX_NAME, SEQ_IN_INDEX',
                [$schema, $table],
            )->getResultArray();

            $actual = [];

            foreach ($rows as $row) {
                $actual[$row['INDEX_NAME']][0]   = (int) $row['NON_UNIQUE'];
                $actual[$row['INDEX_NAME']][1][] = $row['COLUMN_NAME'];
            }

            ksort($actual);
            ksort($expected);
            $this->assertSame($expected, $actual, "{$table}: index");
        }

        // Tidak ada FK di batch ini (user_lokasi_presensi ditunda ke Fase 3).
        $fk = $this->db->query(
            "SELECT COUNT(*) AS n FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = ? AND CONSTRAINT_TYPE = 'FOREIGN KEY' AND TABLE_NAME IN (?, ?)",
            [$schema, $this->db->prefixTable('lokasi_presensi'), $this->db->prefixTable('dm_user_lokasi_presensi')],
        )->getRowArray();
        $this->assertSame(0, (int) $fk['n']);
    }

    /**
     * MySQL 8 / MariaDB menulis default berbeda (`'0'` vs `0`, `current_timestamp()` vs `CURRENT_TIMESTAMP`, `NULL`).
     */
    private function normalizeDefault(mixed $value): ?string
    {
        if ($value === null || strtoupper((string) $value) === 'NULL') {
            return null;
        }

        $value = trim((string) $value, "'");

        return preg_match('/^current_timestamp(\(\))?$/i', $value) === 1 ? 'CURRENT_TIMESTAMP' : $value;
    }

    /**
     * @return list<string>
     */
    private function tableNames(): array
    {
        $names = array_column($this->db->query(
            'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME',
            [$this->db->getDatabase()],
        )->getResultArray(), 'TABLE_NAME');

        return array_map('strval', $names);
    }

    private function migration(): CreateLokasiPresensi
    {
        require_once APPPATH . 'Database/Migrations/2026-10-01-000001_CreateLokasiPresensi.php';

        return new CreateLokasiPresensi();
    }
}
