<?php

declare(strict_types=1);

namespace Tests\Support;

use CodeIgniter\Database\Migration;
use RuntimeException;
use Throwable;

/**
 * Helper schema test B-02 (DBV-013): membaca information_schema dan membandingkannya dengan ekspektasi yang diajukan
 * ke DB Validator (backend/docs/db-review/B-01-B-02-pegawai-riwayat-schema.md), portabel MySQL 8 / MariaDB 10.4:
 *   - tipe kolom dinormalkan (lebar tampilan `int(11)`/`tinyint(4)`/`year(4)` dibuang; `tinyint(1)` dipertahankan);
 *   - default dinormalkan (`'NULL'`/kutip MariaDB, `current_timestamp()`);
 *   - klausa CHECK dinormalkan (huruf kecil, tanpa backtick, spasi, kurung, introducer `_utf8mb4`, maupun backslash).
 *
 * Dipakai bersama DatabaseTestTrait. Kelas pemakai mendefinisikan konstanta ekspektasi dan memanggil assertSkema().
 */
trait SkemaKepegawaianTestTrait
{
    /**
     * Migration yang sedang dilepas test rollback, urut lepas (versi menurun).
     *
     * @var list<Migration>
     */
    private array $skemaDilepas = [];

    /**
     * @param list<string>                                                       $tables
     * @param array<string, array<string, string>>                               $columns    tabel => [kolom => "tipe[ null]"]
     * @param array<string, list<string>>                                        $primary    tabel => kolom PRIMARY
     * @param array<string, array<string, list<string>>>                         $indexes    tabel => [nama index => kolom] (tanpa PRIMARY)
     * @param array<string, list<string>>                                        $uniques    tabel => nama index UNIQUE
     * @param array<string, array{0: string, 1: string, 2: string, 3: string}>   $fks        nama FK => [tabel, kolom, induk, kolom induk]
     * @param list<string>                                                       $laterFks   nama FK dari tabel ini yang dipasang migration lain (boleh ada)
     * @param array<string, array{0: string, 1: string}>                         $checks     nama CHECK => [tabel, klausa ternormal]
     * @param array<string, array<string, string>>                               $defaults   tabel => [kolom => default ternormal]
     */
    protected function assertSkema(
        array $tables,
        array $columns,
        array $primary,
        array $indexes,
        array $uniques,
        array $fks,
        array $laterFks,
        array $checks,
        array $defaults,
    ): void {
        foreach ($tables as $table) {
            $this->assertTrue($this->skemaTableExists($table), "tabel {$table} harus ada");
            $this->assertSame($columns[$table], $this->skemaColumns($table), "kolom {$table}");

            $info = $this->skemaTableInfo($table);
            $this->assertSame('utf8mb4_unicode_ci', $info['TABLE_COLLATION'] ?? null, "collation {$table}");
            $this->assertSame('InnoDB', $info['ENGINE'] ?? null, "engine {$table}");

            foreach ($this->skemaStringCollations($table) as $column => $collation) {
                $this->assertSame('utf8mb4_unicode_ci', $collation, "collation {$table}.{$column}");
            }

            [$pk, $others, $unique] = $this->skemaIndexes($table);
            $expectedIndexes        = $indexes[$table] ?? [];
            ksort($expectedIndexes, SORT_STRING);
            $expectedUniques = $uniques[$table] ?? [];
            sort($expectedUniques, SORT_STRING);

            $this->assertSame($primary[$table], $pk, "PRIMARY {$table}");
            $this->assertSame($expectedIndexes, $others, "index {$table}");
            $this->assertSame($expectedUniques, $unique, "UNIQUE {$table}");
            $this->assertSame($defaults[$table] ?? [], $this->skemaDefaults($table), "default/extra {$table}");
        }

        ksort($fks, SORT_STRING | SORT_FLAG_CASE);
        ksort($checks, SORT_STRING);

        [$actualFks, $later, $notRestrict] = $this->skemaForeignKeys($tables, $laterFks);
        $this->assertSame($fks, $actualFks, 'FK (nama [K-erd]/[V2], kolom, induk)');
        $this->assertSame([], array_values(array_diff($later, $laterFks)), 'FK lain harus terdaftar');
        $this->assertSame([], $notRestrict, 'semua FK RESTRICT/RESTRICT');
        $this->assertSame($checks, $this->skemaChecks($tables), 'CHECK (nama & domain)');
    }

    /**
     * Lepas (down) semua migration App yang sudah jalan sejak $version, urut versi menurun.
     */
    protected function lepasSejak(string $version): void
    {
        $rows = $this->db->table('migrations')->select('version')->where('namespace', 'App')
            ->where('version >=', $version)->orderBy('version', 'DESC')->get()->getResultArray();

        $this->assertNotSame([], $rows, "prasyarat: migration {$version} sudah jalan");

        foreach ($rows as $row) {
            $migration = $this->skemaMigration((string) $row['version']);
            $migration->down();
            $this->skemaDilepas[] = $migration;
        }
    }

    /**
     * Pasang ulang (up) migration yang dilepas, urut versi menaik.
     */
    protected function pasangUlang(): void
    {
        foreach (array_reverse($this->skemaDilepas) as $migration) {
            $migration->up();
        }

        $this->skemaDilepas = [];
    }

    protected function adaYangDilepas(): bool
    {
        return $this->skemaDilepas !== [];
    }

    protected function skemaMigration(string $version): Migration
    {
        $files = glob(APPPATH . 'Database/Migrations/' . $version . '_*.php') ?: [];

        if (count($files) !== 1) {
            throw new RuntimeException("File migration {$version} tidak ditemukan (atau lebih dari satu).");
        }

        require_once $files[0];
        $class = 'App\\Database\\Migrations\\' . substr(basename($files[0], '.php'), strlen($version) + 1);

        /** @var Migration $migration */
        $migration = new $class();

        return $migration;
    }

    /**
     * @param list<int> $codes kode error DB yang diterima (mis. CHECK 3819 MySQL / 4025 MariaDB)
     */
    protected function assertDbError(array $codes, callable $write): void
    {
        $code = null;

        try {
            if ($write() === false) {
                $code = (int) ($this->db->error()['code'] ?? 0);
            }
        } catch (Throwable $e) {
            for ($t = $e; $t !== null; $t = $t->getPrevious()) {
                if (in_array((int) $t->getCode(), $codes, true)) {
                    $code = (int) $t->getCode();

                    break;
                }
            }

            $code ??= (int) $e->getCode();
        }

        $this->assertContains($code, $codes, 'kode error DB yang diharapkan: ' . implode('/', $codes));
    }

    protected function skemaTableExists(string $table): bool
    {
        return (int) $this->db->query(
            'SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
            [$this->db->getDatabase(), $this->db->prefixTable($table)],
        )->getRowArray()['n'] > 0;
    }

    protected function skemaQuoted(string $table): string
    {
        return $this->db->escapeIdentifiers($this->db->prefixTable($table));
    }

    /**
     * @return array<string, string> kolom => "COLUMN_TYPE[ null]", urut posisi
     */
    protected function skemaColumns(string $table): array
    {
        $rows = $this->db->query(
            'SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION',
            [$this->db->getDatabase(), $this->db->prefixTable($table)],
        )->getResultArray();

        $columns = [];

        foreach ($rows as $row) {
            $type = (string) preg_replace('/^(tinyint|smallint|mediumint|int|bigint)\((?!1\))\d+\)/', '$1', strtolower((string) $row['COLUMN_TYPE']));
            $type = $type === 'year(4)' ? 'year' : $type;

            $columns[(string) $row['COLUMN_NAME']] = $type . ($row['IS_NULLABLE'] === 'YES' ? ' null' : '');
        }

        return $columns;
    }

    /**
     * Default & EXTRA per kolom yang bukan "NULL tanpa extra": nilai default ternormal, ditambah " on update" bila ada
     * ON UPDATE CURRENT_TIMESTAMP; `auto_increment` untuk kolom AUTO_INCREMENT.
     *
     * @return array<string, string>
     */
    protected function skemaDefaults(string $table): array
    {
        $rows = $this->db->query(
            'SELECT COLUMN_NAME, COLUMN_DEFAULT, EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION',
            [$this->db->getDatabase(), $this->db->prefixTable($table)],
        )->getResultArray();

        $defaults = [];

        foreach ($rows as $row) {
            $extra   = strtolower((string) $row['EXTRA']);
            $default = $row['COLUMN_DEFAULT'];
            $value   = null;

            if ($default !== null && strtoupper((string) $default) !== 'NULL') {
                $value = trim((string) $default, "'");
                $value = strtolower(trim($value, '()')) === 'current_timestamp' || strtolower($value) === 'current_timestamp()'
                    ? 'CURRENT_TIMESTAMP' : $value;
            }

            if (str_contains($extra, 'on update')) {
                $value = ($value ?? 'NULL') . ' on update';
            }

            if (str_contains($extra, 'auto_increment')) {
                $value = 'auto_increment';
            }

            if ($value !== null) {
                $defaults[(string) $row['COLUMN_NAME']] = $value;
            }
        }

        return $defaults;
    }

    /**
     * @return array<string, mixed>
     */
    protected function skemaTableInfo(string $table): array
    {
        return $this->db->query(
            'SELECT TABLE_COLLATION, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
            [$this->db->getDatabase(), $this->db->prefixTable($table)],
        )->getRowArray() ?? [];
    }

    /**
     * @return array<string, string> kolom string => collation
     */
    protected function skemaStringCollations(string $table): array
    {
        $rows = $this->db->query(
            'SELECT COLUMN_NAME, COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLLATION_NAME IS NOT NULL',
            [$this->db->getDatabase(), $this->db->prefixTable($table)],
        )->getResultArray();

        return array_column($rows, 'COLLATION_NAME', 'COLUMN_NAME');
    }

    /**
     * @return array{0: list<string>, 1: array<string, list<string>>, 2: list<string>} [kolom PRIMARY, index lain urut nama, nama UNIQUE]
     */
    protected function skemaIndexes(string $table): array
    {
        $rows = $this->db->query(
            'SELECT INDEX_NAME, NON_UNIQUE, INDEX_TYPE, COLUMN_NAME FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY INDEX_NAME, SEQ_IN_INDEX',
            [$this->db->getDatabase(), $this->db->prefixTable($table)],
        )->getResultArray();

        $primary = [];
        $others  = [];
        $unique  = [];

        foreach ($rows as $row) {
            $name = (string) $row['INDEX_NAME'];

            if ($name === 'PRIMARY') {
                $primary[] = (string) $row['COLUMN_NAME'];

                continue;
            }

            $this->assertSame('BTREE', (string) $row['INDEX_TYPE'], "{$table}.{$name} BTREE");
            $others[$name][] = (string) $row['COLUMN_NAME'];

            if ((string) $row['NON_UNIQUE'] === '0') {
                $unique[$name] = true;
            }
        }

        ksort($others, SORT_STRING);
        $unique = array_keys($unique);
        sort($unique, SORT_STRING);

        return [$primary, $others, $unique];
    }

    /**
     * FK dari tabel-tabel ini: [FK di luar $laterFks urut nama, nama FK $laterFks yang ada, FK bukan RESTRICT/RESTRICT].
     *
     * @param list<string> $tables
     * @param list<string> $laterFks
     *
     * @return array{0: array<string, array{0: string, 1: string, 2: string, 3: string}>, 1: list<string>, 2: list<string>}
     */
    protected function skemaForeignKeys(array $tables, array $laterFks): array
    {
        $rows = $this->db->query(
            'SELECT k.CONSTRAINT_NAME, k.TABLE_NAME, k.COLUMN_NAME, k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME, r.UPDATE_RULE, r.DELETE_RULE
             FROM information_schema.KEY_COLUMN_USAGE k
             JOIN information_schema.REFERENTIAL_CONSTRAINTS r
               ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME AND r.TABLE_NAME = k.TABLE_NAME
             WHERE k.TABLE_SCHEMA = ? AND k.REFERENCED_TABLE_NAME IS NOT NULL AND k.TABLE_NAME IN ?',
            [$this->db->getDatabase(), array_map(fn (string $t): string => $this->db->prefixTable($t), $tables)],
        )->getResultArray();

        $fks         = [];
        $later       = [];
        $notRestrict = [];

        foreach ($rows as $row) {
            $name = (string) $row['CONSTRAINT_NAME'];

            if ($row['UPDATE_RULE'] !== 'RESTRICT' || $row['DELETE_RULE'] !== 'RESTRICT') {
                $notRestrict[] = "{$name} ({$row['UPDATE_RULE']}/{$row['DELETE_RULE']})";
            }

            if (in_array($name, $laterFks, true)) {
                $later[] = $name;

                continue;
            }

            $fks[$name] = [
                $this->skemaStripPrefix((string) $row['TABLE_NAME']),
                (string) $row['COLUMN_NAME'],
                $this->skemaStripPrefix((string) $row['REFERENCED_TABLE_NAME']),
                (string) $row['REFERENCED_COLUMN_NAME'],
            ];
        }

        ksort($fks, SORT_STRING | SORT_FLAG_CASE);

        return [$fks, $later, $notRestrict];
    }

    /**
     * @param list<string> $tables
     *
     * @return array<string, array{0: string, 1: string}> nama CHECK => [tabel, klausa ternormal], urut nama
     */
    protected function skemaChecks(array $tables): array
    {
        $rows = $this->db->query(
            "SELECT tc.TABLE_NAME, tc.CONSTRAINT_NAME, cc.CHECK_CLAUSE
             FROM information_schema.TABLE_CONSTRAINTS tc
             JOIN information_schema.CHECK_CONSTRAINTS cc
               ON cc.CONSTRAINT_SCHEMA = tc.CONSTRAINT_SCHEMA AND cc.CONSTRAINT_NAME = tc.CONSTRAINT_NAME
             WHERE tc.CONSTRAINT_SCHEMA = ? AND tc.CONSTRAINT_TYPE = 'CHECK' AND tc.TABLE_NAME IN ?",
            [$this->db->getDatabase(), array_map(fn (string $t): string => $this->db->prefixTable($t), $tables)],
        )->getResultArray();

        $checks = [];

        foreach ($rows as $row) {
            $clause = strtolower((string) $row['CHECK_CLAUSE']);
            $clause = str_replace(['`', '_utf8mb4', '\\', '(', ')'], '', $clause);

            $checks[(string) $row['CONSTRAINT_NAME']] = [
                $this->skemaStripPrefix((string) $row['TABLE_NAME']),
                (string) preg_replace('/\s+/', '', $clause),
            ];
        }

        ksort($checks, SORT_STRING);

        return $checks;
    }

    protected function skemaStripPrefix(string $table): string
    {
        $prefix = $this->db->getPrefix();

        return $prefix !== '' && str_starts_with($table, $prefix) ? substr($table, strlen($prefix)) : $table;
    }
}
