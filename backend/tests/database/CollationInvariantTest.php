<?php

declare(strict_types=1);

namespace Tests\Database;

use Tests\Support\DatabaseTestCase;

/**
 * DBV-010 (ISSUE-010) — setelah seluruh migration (semua namespace) dijalankan, setiap tabel ber-prefix di database test
 * (kecuali `migrations` milik CI4) dan setiap kolom stringnya memakai utf8mb4_unicode_ci; tidak ada collation
 * `*_0900_*` (default MySQL 8, tidak ada di MariaDB 10.4).
 *
 * Catatan: tabel Forge mengikuti DBCollat, jadi jebakan utamanya SQL mentah tanpa COLLATE eksplisit. Jebakan itu hanya
 * efektif bila default database test bukan unicode_ci (mis. `simpeg_v2_testing` general_ci). Kolom JSON MariaDB
 * (LONGTEXT utf8mb4_bin + CHECK json_valid) dikecualikan.
 *
 * @internal
 */
final class CollationInvariantTest extends DatabaseTestCase
{
    public function testEveryTableAndStringColumnIsUnicodeCi(): void
    {
        $prefix = $this->db->getPrefix();
        $like   = str_replace('_', '\_', $prefix) . '%';
        $skip   = $this->db->prefixTable('migrations');

        $tables = $this->db->query(
            'SELECT TABLE_NAME, TABLE_COLLATION FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = ? AND TABLE_NAME LIKE ? AND TABLE_NAME <> ? ORDER BY TABLE_NAME',
            [$this->db->getDatabase(), 'BASE TABLE', $like, $skip],
        )->getResultArray();

        $this->assertGreaterThan(20, count($tables), 'prasyarat: seluruh tabel hasil migrate terbaca');

        $wrong = [];

        foreach ($tables as $row) {
            if ($row['TABLE_COLLATION'] !== 'utf8mb4_unicode_ci') {
                $wrong[] = "{$row['TABLE_NAME']} ({$row['TABLE_COLLATION']})";
            }
        }

        $columns = $this->db->query(
            'SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, COLLATION_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME LIKE ? AND TABLE_NAME <> ? AND COLLATION_NAME IS NOT NULL
             ORDER BY TABLE_NAME, ORDINAL_POSITION',
            [$this->db->getDatabase(), $like, $skip],
        )->getResultArray();

        foreach ($columns as $row) {
            $isMariaDbJson = strtolower((string) $row['COLUMN_TYPE']) === 'longtext' && $row['COLLATION_NAME'] === 'utf8mb4_bin';

            if ($row['COLLATION_NAME'] !== 'utf8mb4_unicode_ci' && ! $isMariaDbJson) {
                $wrong[] = "{$row['TABLE_NAME']}.{$row['COLUMN_NAME']} ({$row['COLLATION_NAME']})";
            }
        }

        $this->assertSame([], $wrong, 'tabel/kolom yang tidak utf8mb4_unicode_ci');
    }
}
