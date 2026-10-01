<?php

declare(strict_types=1);

namespace Tests\Support\Kepegawaian;

use Tests\Support\SkemaKepegawaianTestTrait;

/**
 * Helper schema test B-01/B-02 berbasis ekspektasi D1 (SkemaD1): menerjemahkan ekspektasi per tabel ke
 * SkemaKepegawaianTestTrait::assertSkema(), memeriksa COMMENT kolom = D1, dan menyusun baris uji minimal (kolom NOT NULL
 * tanpa default diisi nilai dummy sesuai tipe).
 *
 * Dipakai bersama DatabaseTestTrait.
 */
trait SkemaD1TestTrait
{
    use SkemaKepegawaianTestTrait;

    /**
     * @param array<string, array<string, mixed>> $spec  tabel => ekspektasi SkemaD1
     * @param array<string, array<string, mixed>> $extra tabel non-D1 ([V2], mis. `jenis_rwy`) dalam format yang sama
     */
    protected function assertSkemaD1(array $spec, array $extra = []): void
    {
        $all     = $spec + $extra;
        $tables  = array_keys($all);
        $columns = $primary = $indexes = $uniques = $defaults = [];
        $fks     = $checks = [];
        $later   = [];

        foreach ($all as $table => $s) {
            $columns[$table]  = $s['columns'];
            $primary[$table]  = $s['primary'];
            $indexes[$table]  = $s['indexes'];
            $uniques[$table]  = $s['uniques'];
            $defaults[$table] = $s['defaults'];
            $fks += $s['fks'];
            $checks += $s['checks'];
            $later = [...$later, ...array_keys($s['later'])];
        }

        $this->assertSkema($tables, $columns, $primary, $indexes, $uniques, $fks, $later, $checks, $defaults);

        foreach ($all as $table => $s) {
            $this->assertSame($s['comments'], $this->skemaComments($table), "COMMENT kolom {$table} = D1");
        }
    }

    /**
     * Nama FK D1 yang dipasang migration lain, per jenis (`RWY`, `G02`, `NOV2`).
     *
     * @param array<string, array<string, mixed>> $spec
     *
     * @return array<string, array{0: string, 1: string, 2: string, 3: string, 4: string}> nama => [jenis, tabel, kolom, induk, kolom induk]
     */
    protected function fkDitunda(array $spec, string $jenis): array
    {
        $out = [];

        foreach ($spec as $s) {
            foreach ($s['later'] as $name => $fk) {
                if ($fk[0] === $jenis) {
                    $out[$name] = $fk;
                }
            }
        }

        ksort($out, SORT_STRING | SORT_FLAG_CASE);

        return $out;
    }

    /**
     * Baris uji minimal: kolom NOT NULL tanpa default (bukan AUTO_INCREMENT) diisi nilai dummy sesuai tipe, lalu
     * ditimpa $overrides.
     *
     * @param array<string, mixed> $s         ekspektasi satu tabel
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    protected function barisMinimal(array $s, array $overrides = []): array
    {
        $row = [];

        foreach ($s['columns'] as $column => $type) {
            if (str_ends_with($type, ' null') || array_key_exists($column, $s['defaults'])) {
                continue;
            }

            $numeric = preg_match('/^(tinyint|smallint|mediumint|int|bigint|decimal|double|float)/', $type) === 1;

            $row[$column] = match (true) {
                str_starts_with($type, 'datetime') => '2024-01-02 03:04:05',
                str_starts_with($type, 'date')     => '2024-01-02',
                str_starts_with($type, 'year')     => 2024,
                $numeric                           => 1,
                default                            => 'x',
            };
        }

        return [...$row, ...$overrides];
    }

    /**
     * @return array<string, string> kolom => COMMENT (yang tidak kosong), urut posisi
     */
    protected function skemaComments(string $table): array
    {
        $rows = $this->db->query(
            "SELECT COLUMN_NAME, COLUMN_COMMENT FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_COMMENT <> '' ORDER BY ORDINAL_POSITION",
            [$this->db->getDatabase(), $this->db->prefixTable($table)],
        )->getResultArray();

        return array_column($rows, 'COLUMN_COMMENT', 'COLUMN_NAME');
    }
}
