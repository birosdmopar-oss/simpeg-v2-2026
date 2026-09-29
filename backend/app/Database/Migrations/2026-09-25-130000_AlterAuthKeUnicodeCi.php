<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Migration;
use CodeIgniter\Database\ResultInterface;
use RuntimeException;

/**
 * DBV-010 (1/3) — collation tabel auth A-01 dan antrean ke utf8mb4_unicode_ci (ISSUE-010, lanjutan DBV-001).
 *
 * Cakupan: pengguna, token, login_attempts, forgot_attempts, audit_logs (wajib ada) + queue_jobs, queue_jobs_failed
 * (milik migration vendor CodeIgniter\Queue; dilewati bila tabelnya tidak ada, mis. `migrate` hanya namespace App).
 * Tabel `migrations` milik CI4 sengaja tidak disentuh.
 *
 * Metode:
 *   - Tabel tanpa kolom JSON: CONVERT TO CHARACTER SET utf8mb4 COLLATE ... (tabel + setiap kolom string).
 *   - token, audit_logs: DEFAULT CHARACTER SET + MODIFY eksplisit setiap kolom string dengan definisi yang sama persis
 *     dengan skema A-01 (tipe, NULL/DEFAULT, COMMENT). Kolom JSON tidak disentuh: di MariaDB 10.4 JSON adalah LONGTEXT
 *     utf8mb4_bin + CHECK json_valid, dan CONVERT akan ikut mengubah collation-nya.
 *   - Kolom token_hash (hex) ikut unicode_ci (keputusan D-2).
 *
 * Pengaman (dijalankan SEBELUM ALTER apa pun; temuan dikumpulkan dalam satu exception, tidak ada ALTER yang jalan):
 *   - Tabrakan UNIQUE di collation tujuan: nilai yang berbeda di collation lama tetapi sama di collation baru
 *     (mis. 'strasse'/'straße' di unicode_ci, 'admin'/'admın' di general_ci) membuat ALTER gagal 1062 di tengah jalan.
 *   - Tanggal nol / nol-di-tanggal di kolom DATE/DATETIME/TIMESTAMP: ALTER COPY di koneksi strict gagal 1292.
 *
 * Idempoten: tabel yang tabel dan kolom stringnya sudah ber-collation tujuan dilewati (lingkungan baru membuat tabel
 * dengan DBCollat unicode_ci sehingga up() tidak mengubah apa pun), jadi migrate yang gagal di tengah bisa diulang.
 *
 * down() kembali ke utf8mb4_general_ci secara eksplisit (bukan lewat DBCollat) dengan pengecekan yang sama. Di
 * lingkungan yang sejak awal unicode_ci, down() menghasilkan general_ci yang tidak pernah ada di sana (kosmetik,
 * seperti DBV-001).
 *
 * Review DB Validator: backend/docs/db-review/A-01-auth-schema.md Bagian 9 (DBV-010) — JANGAN dijalankan di
 * Dev/Production sebelum disetujui.
 */
class AlterAuthKeUnicodeCi extends Migration
{
    public const COLLATION_UNICODE = 'utf8mb4_unicode_ci';
    public const COLLATION_GENERAL = 'utf8mb4_general_ci';

    /**
     * Tabel auth A-01 (wajib ada).
     */
    public const AUTH_TABLES = ['pengguna', 'token', 'login_attempts', 'forgot_attempts', 'audit_logs'];

    /**
     * Tabel antrean ADR-013 (opsional).
     */
    public const QUEUE_TABLES = ['queue_jobs', 'queue_jobs_failed'];

    /**
     * Seluruh index UNIQUE berkolom string di tabel cakupan: [tabel, kolom].
     *
     * @var list<array{0: string, 1: string}>
     */
    public const UNIQUE_STRING_COLUMNS = [
        ['pengguna', 'nip'],
        ['pengguna', 'username'],
        ['token', 'token_hash'],
        ['forgot_attempts', 'token_hash'],
    ];

    /**
     * Tabel ber-kolom JSON: definisi kolom string (skema A-01 sebelum DBV-010), {c} = collation tujuan.
     *
     * @var array<string, array<string, string>>
     */
    private const MODIFY_COLUMNS = [
        'token' => [
            'nip'        => 'VARCHAR(20) COLLATE {c} NOT NULL',
            'token_hash' => "CHAR(64) COLLATE {c} NOT NULL COMMENT 'SHA-256 hex dari refresh token; plaintext tidak disimpan'",
        ],
        'audit_logs' => [
            'nip_actor' => "VARCHAR(20) COLLATE {c} NULL DEFAULT NULL COMMENT 'NIP pelaku; NULL untuk proses sistem/CLI'",
            'entity'    => "VARCHAR(100) COLLATE {c} NOT NULL COMMENT 'nama tabel/model yang diubah'",
            'entity_id' => 'VARCHAR(50) COLLATE {c} NOT NULL',
            'event'     => "ENUM('create','update','delete','login','logout') COLLATE {c} NOT NULL",
        ],
    ];

    public function up(): void
    {
        $this->convert(self::COLLATION_UNICODE);
    }

    public function down(): void
    {
        $this->convert(self::COLLATION_GENERAL);
    }

    private function convert(string $collation): void
    {
        $tables = $this->tablesToConvert($collation);

        $this->assertSafeToConvert($tables, $collation);

        foreach ($tables as $table) {
            if (isset(self::MODIFY_COLUMNS[$table])) {
                $specs = ["DEFAULT CHARACTER SET utf8mb4 COLLATE {$collation}"];

                foreach (self::MODIFY_COLUMNS[$table] as $column => $definition) {
                    $specs[] = "MODIFY `{$column}` " . str_replace('{c}', $collation, $definition);
                }

                $this->exec("ALTER TABLE {$this->t($table)} " . implode(', ', $specs));

                continue;
            }

            $this->exec("ALTER TABLE {$this->t($table)} CONVERT TO CHARACTER SET utf8mb4 COLLATE {$collation}");
        }
    }

    /**
     * Tabel cakupan yang tabel atau salah satu kolom stringnya belum ber-collation tujuan.
     *
     * @return list<string>
     */
    private function tablesToConvert(string $collation): array
    {
        $existing = [];

        foreach ($this->query(
            'SELECT TABLE_NAME, TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME IN ?',
            [$this->connection()->getDatabase(), $this->prefixed([...self::AUTH_TABLES, ...self::QUEUE_TABLES])],
        ) as $row) {
            $existing[(string) $row['TABLE_NAME']] = (string) $row['TABLE_COLLATION'];
        }

        $missing = array_values(array_filter(self::AUTH_TABLES, fn (string $t): bool => ! isset($existing[$this->prefix($t)])));

        if ($missing !== []) {
            throw new RuntimeException('DBV-010: tabel auth tidak ditemukan: ' . implode(', ', $missing) . '.');
        }

        $columns = [];

        foreach ($this->query(
            'SELECT TABLE_NAME, COLUMN_NAME, COLLATION_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME IN ? AND COLLATION_NAME IS NOT NULL',
            [$this->connection()->getDatabase(), array_keys($existing)],
        ) as $row) {
            $columns[(string) $row['TABLE_NAME']][(string) $row['COLUMN_NAME']] = (string) $row['COLLATION_NAME'];
        }

        $tables = [];

        foreach ([...self::AUTH_TABLES, ...self::QUEUE_TABLES] as $table) {
            $name = $this->prefix($table);

            if (! isset($existing[$name])) {
                continue;
            }

            // Tabel ber-JSON: hanya kolom yang di-MODIFY yang dinilai (JSON MariaDB ber-collation utf8mb4_bin).
            $stringColumns = $columns[$name] ?? [];

            if (isset(self::MODIFY_COLUMNS[$table])) {
                $stringColumns = array_intersect_key($stringColumns, self::MODIFY_COLUMNS[$table]);
            }

            $pending = $existing[$name] !== $collation
                || array_filter($stringColumns, static fn (string $c): bool => $c !== $collation) !== [];

            if ($pending) {
                $tables[] = $table;
            }
        }

        return $tables;
    }

    /**
     * @param list<string> $tables
     */
    private function assertSafeToConvert(array $tables, string $collation): void
    {
        $problems = [];

        foreach (self::UNIQUE_STRING_COLUMNS as [$table, $column]) {
            if (! in_array($table, $tables, true)) {
                continue;
            }

            $duplicates = $this->query(
                "SELECT `{$column}` COLLATE {$collation} AS nilai, COUNT(*) AS jumlah FROM {$this->t($table)}
                 WHERE `{$column}` IS NOT NULL GROUP BY nilai HAVING COUNT(*) > 1 LIMIT 5",
            );

            foreach ($duplicates as $row) {
                $problems[] = sprintf("%s.%s '%s' (%d baris sama di %s)", $table, $column, (string) $row['nilai'], (int) $row['jumlah'], $collation);
            }
        }

        if ($tables !== []) {
            $dateColumns = $this->query(
                "SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = ? AND TABLE_NAME IN ? AND DATA_TYPE IN ('date', 'datetime', 'timestamp')
                 ORDER BY TABLE_NAME, ORDINAL_POSITION",
                [$this->connection()->getDatabase(), $this->prefixed($tables)],
            );

            foreach ($dateColumns as $row) {
                $table  = $this->connection()->escapeIdentifiers((string) $row['TABLE_NAME']);
                $column = $this->connection()->escapeIdentifiers((string) $row['COLUMN_NAME']);
                $count  = $this->query(
                    "SELECT COUNT(*) AS jumlah FROM {$table} WHERE {$column} IS NOT NULL AND (MONTH({$column}) = 0 OR DAYOFMONTH({$column}) = 0)",
                )[0]['jumlah'] ?? 0;

                if ((int) $count > 0) {
                    $problems[] = sprintf('%s.%s berisi %d tanggal nol', $this->unprefix((string) $row['TABLE_NAME']), (string) $row['COLUMN_NAME'], (int) $count);
                }
            }
        }

        if ($problems !== []) {
            throw new RuntimeException(
                "DBV-010: konversi collation ke {$collation} dibatalkan sebelum ALTER apa pun: " . implode('; ', $problems)
                . '. Bereskan data tersebut (ganti nilai yang bertabrakan, isi/NULL-kan tanggal nol), lalu jalankan ulang.',
            );
        }
    }

    /**
     * @param list<mixed> $binds
     *
     * @return list<array<string, mixed>>
     */
    private function query(string $sql, array $binds = []): array
    {
        $result = $this->connection()->query($sql, $binds === [] ? null : $binds);

        if (! $result instanceof ResultInterface) {
            $error = $this->connection()->error();

            throw new RuntimeException('DBV-010: query gagal (' . $error['code'] . '): ' . $error['message']);
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = $result->getResultArray();

        return $rows;
    }

    /**
     * @param list<string> $tables
     *
     * @return list<string>
     */
    private function prefixed(array $tables): array
    {
        return array_map(fn (string $t): string => $this->prefix($t), $tables);
    }

    private function prefix(string $table): string
    {
        return $this->connection()->prefixTable($table);
    }

    private function unprefix(string $table): string
    {
        $prefix = $this->connection()->getPrefix();

        return $prefix !== '' && str_starts_with($table, $prefix) ? substr($table, strlen($prefix)) : $table;
    }

    /**
     * Nama tabel ber-prefix (DBPrefix, mis. `t_` di database test) yang sudah di-escape untuk SQL mentah.
     */
    private function t(string $table): string
    {
        return $this->connection()->escapeIdentifiers($this->prefix($table));
    }

    private function connection(): BaseConnection
    {
        if (! $this->db instanceof BaseConnection) {
            throw new RuntimeException('DBV-010: koneksi database tidak mendukung prefix tabel.');
        }

        return $this->db;
    }

    private function exec(string $sql): void
    {
        // Dengan DBDebug = false query() mengembalikan false tanpa exception: jangan biarkan ALTER gagal diam-diam.
        if ($this->connection()->query($sql) === false) {
            $error = $this->connection()->error();

            throw new RuntimeException('DBV-010: ALTER gagal (' . $error['code'] . '): ' . $error['message']);
        }
    }
}
