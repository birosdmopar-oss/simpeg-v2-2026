<?php

declare(strict_types=1);

namespace Tests\Auth;

use App\Database\Migrations\AlterAuthKeUnicodeCi;
use App\Database\Migrations\AlterIdentitasAkunIdPengguna;
use App\Database\Migrations\AlterPenggunaAkunNonPegawai;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Database\Migration;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use RuntimeException;
use Throwable;

/**
 * DBV-010 (1/3) — migration 2026-09-25-130000_AlterAuthKeUnicodeCi: tabel auth A-01 + antrean menjadi
 * utf8mb4_unicode_ci tanpa mengubah definisi lain, pengaman tabrakan UNIQUE & tanggal nol dijalankan sebelum ALTER
 * apa pun, dan down() kembali ke utf8mb4_general_ci dengan pengaman yang sama (backend/docs/db-review/A-01-auth-schema.md
 * Bagian 9).
 *
 * Keadaan "sebelum DBV-010" dicapai dengan menjalankan down() ketiga migration DBV-010 dari yang terakhir; tearDown()
 * mengosongkan data uji lalu menjalankan up() semuanya lagi (idempoten) agar migrate:refresh test berikutnya konsisten.
 *
 * @internal
 */
final class AuthCollationMigrationTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = null;

    private const TABLES = ['pengguna', 'token', 'login_attempts', 'forgot_attempts', 'audit_logs', 'queue_jobs', 'queue_jobs_failed'];

    private const UNICODE = 'utf8mb4_unicode_ci';
    private const GENERAL = 'utf8mb4_general_ci';

    /**
     * Tabel scratch untuk uji FK (mewakili `pegawai.nip` VARCHAR(30) unicode_ci, K1).
     */
    private const FK_TABLE = 'dbv010_uji_pegawai';

    private ?string $sqlMode = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sqlMode = (string) $this->db->query('SELECT @@SESSION.sql_mode AS m')->getRowArray()['m'];
    }

    protected function tearDown(): void
    {
        $this->db->query('SET SESSION sql_mode = ?', [$this->sqlMode]);
        $this->db->query('DROP TABLE IF EXISTS ' . $this->t(self::FK_TABLE));

        foreach (self::TABLES as $table) {
            $this->db->table($table)->emptyTable();
        }

        foreach ($this->chain() as $migration) {
            $migration->up();
        }

        parent::tearDown();
    }

    public function testUpConvertsEveryTableAndStringColumnToUnicodeCi(): void
    {
        $this->assertCollation(self::UNICODE);

        foreach (self::TABLES as $table) {
            foreach ($this->snapshot($table)['columns'] as $name => $column) {
                $this->assertStringNotContainsString('_0900_', (string) $column['COLLATION_NAME'], "{$table}.{$name}");
            }
        }

        // Kolom JSON tidak disentuh (MySQL: tipe json tanpa collation).
        foreach ([['token', 'claims_json'], ['audit_logs', 'before_json'], ['audit_logs', 'after_json']] as [$table, $column]) {
            $info = $this->snapshot($table)['columns'][$column];
            $this->assertContains(strtolower((string) $info['DATA_TYPE']), ['json', 'longtext'], "{$table}.{$column}");
        }
    }

    /**
     * down() → general_ci, up() → unicode_ci: tipe, NULL, DEFAULT, COMMENT, EXTRA, urutan kolom, dan index tidak
     * berubah selain collation.
     */
    public function testDownAndUpOnlyChangeCollation(): void
    {
        $full = $this->snapshots();

        // Keadaan tepat sebelum 130000: 130200 dan 130100 di-rollback dulu (urutan regress).
        $chain = $this->chain();
        $chain[2]->down();
        $chain[1]->down();
        $before = $this->snapshots();
        $this->assertCollation(self::UNICODE);

        $this->m1()->down();
        $this->assertCollation(self::GENERAL);
        $this->assertSame($this->withoutCollation($before), $this->withoutCollation($this->snapshots()));

        $this->m1()->up();
        $this->assertSame($before, $this->snapshots());

        $chain[1]->up();
        $chain[2]->up();
        $this->assertSame($full, $this->snapshots());
    }

    /**
     * Data non-ASCII (huruf beraksen, fullwidth, emoji 4 byte) utuh byte per byte setelah down → up.
     */
    public function testNonAsciiDataSurvivesDownAndUp(): void
    {
        $now = date('Y-m-d H:i:s');

        $this->db->table('pengguna')->insert([
            'nip'    => '198501012010011001', 'username' => 'Ñoño_Ｆｕｌｌ', 'password_legacy' => md5('x'), 'user_level' => 1,
            'status' => '1', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->db->table('login_attempts')->insert(['username' => 'Ñoño😀', 'ip_address' => '::1', 'success' => 0, 'attempted_at' => $now]);
        $this->db->table('forgot_attempts')->insert(['username' => 'Ｆｕｌｌ', 'ip_address' => null, 'requested_at' => $now]);
        $this->db->table('audit_logs')->insert(['entity' => 'uji', 'entity_id' => 'x😀y', 'event' => 'create', 'created_at' => $now]);
        $this->db->table('queue_jobs')->insert(['queue' => 'uji', 'payload' => '{"x":"Ñ😀"}', 'priority' => 'default', 'available_at' => time(), 'created_at' => time()]);

        $hex = [
            'pengguna'        => 'SELECT HEX(`username`) AS h FROM %s',
            'login_attempts'  => 'SELECT HEX(`username`) AS h FROM %s',
            'forgot_attempts' => 'SELECT HEX(`username`) AS h FROM %s',
            'audit_logs'      => 'SELECT HEX(`entity_id`) AS h FROM %s',
            'queue_jobs'      => 'SELECT HEX(`payload`) AS h FROM %s',
        ];
        $read = function () use ($hex): array {
            $out = [];

            foreach ($hex as $table => $sql) {
                $out[$table] = array_column($this->db->query(sprintf($sql, $this->t($table)))->getResultArray(), 'h');
            }

            return $out;
        };

        $original = $read();
        $this->assertSame(bin2hex('x😀y'), strtolower($original['audit_logs'][0]));

        $this->downAll();
        $this->assertSame($original, $read(), 'data harus utuh di general_ci');

        $this->upAll();
        $this->assertSame($original, $read(), 'data harus utuh setelah kembali ke unicode_ci');
    }

    /**
     * 'strasse' dan 'straße' berbeda di general_ci tetapi sama di unicode_ci: up() ditolak SEBELUM ALTER apa pun
     * (forgot_attempts sengaja dipakai: tanpa pra-cek, pengguna/token/login_attempts sudah terlanjur dikonversi).
     */
    public function testUpRejectsUnicodeCollisionBeforeAnyAlter(): void
    {
        $this->downAll();

        foreach (['strasse', 'straße'] as $value) {
            $this->db->table('forgot_attempts')->insert(['username' => 'x', 'token_hash' => $value, 'requested_at' => date('Y-m-d H:i:s')]);
        }

        $error = $this->catchError(fn () => $this->m1()->up());

        $this->assertInstanceOf(RuntimeException::class, $error);
        $this->assertStringContainsString('DBV-010', $error->getMessage());
        $this->assertStringContainsString('forgot_attempts.token_hash', $error->getMessage());
        $this->assertCollation(self::GENERAL);
    }

    /**
     * Tanggal nol di login_attempts: ALTER COPY di koneksi strict akan gagal 1292 di tengah jalan → ditolak sebelum
     * ALTER apa pun (pengguna dan token, yang dikonversi lebih dulu, tetap general_ci).
     */
    public function testUpRejectsZeroDateBeforeAnyAlter(): void
    {
        $this->downAll();

        $this->db->query("SET SESSION sql_mode = ''");
        $this->db->table('login_attempts')->insert(['username' => 'nol', 'success' => 0, 'attempted_at' => '0000-00-00 00:00:00']);
        $this->db->query('SET SESSION sql_mode = ?', [$this->sqlMode]);

        $error = $this->catchError(fn () => $this->m1()->up());

        $this->assertInstanceOf(RuntimeException::class, $error);
        $this->assertStringContainsString('login_attempts.attempted_at berisi 1 tanggal nol', $error->getMessage());
        $this->assertCollation(self::GENERAL);
    }

    /**
     * 'admin' dan 'admın' (i tanpa titik) berbeda di unicode_ci tetapi sama di general_ci: down() ditolak sebelum
     * ALTER apa pun.
     */
    public function testDownRejectsGeneralCollisionBeforeAnyAlter(): void
    {
        foreach (['admin', 'admın'] as $value) {
            $this->db->table('forgot_attempts')->insert(['username' => 'x', 'token_hash' => $value, 'requested_at' => date('Y-m-d H:i:s')]);
        }

        $error = $this->catchError(fn () => $this->downAll());

        $this->assertInstanceOf(RuntimeException::class, $error);
        $this->assertStringContainsString('forgot_attempts.token_hash', $error->getMessage());
        $this->assertStringContainsString(self::GENERAL, $error->getMessage());
        $this->assertCollation(self::UNICODE);
    }

    /**
     * Collation yang berbeda membuat JOIN pengguna × faq_rate (unicode_ci sejak DBV-002) gagal 1267; setelah DBV-010
     * JOIN berhasil.
     */
    public function testJoinPenggunaWithFaqRateNeedsSameCollation(): void
    {
        $sql = "SELECT COUNT(*) AS n FROM {$this->t('pengguna')} p JOIN {$this->t('faq_rate')} r ON r.nip = p.nip";

        $this->assertSame('0', (string) $this->db->query($sql)->getRowArray()['n']);

        $this->downAll();

        $error = $this->catchError(fn () => $this->db->query($sql));
        $this->assertInstanceOf(DatabaseException::class, $error);
        $this->assertStringContainsString('Illegal mix of collations', $error->getMessage());
    }

    /**
     * FK dari pengguna.nip ke kolom NIP unicode_ci (calon `pegawai.nip`, K1) hanya bisa dibuat bila collation sama.
     */
    public function testForeignKeyToUnicodeNipColumnRequiresUnicodePengguna(): void
    {
        $nip = '198501012010011001';

        $this->db->table('pengguna')->insert([
            'nip' => $nip, 'username' => $nip, 'password_legacy' => md5('x'), 'user_level' => 2, 'status' => '1',
        ]);
        $this->db->query('CREATE TABLE ' . $this->t(self::FK_TABLE) . ' (`nip` VARCHAR(30) COLLATE utf8mb4_unicode_ci NOT NULL, PRIMARY KEY (`nip`))
            ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $this->db->table(self::FK_TABLE)->insert(['nip' => $nip]);

        $addFk = 'ALTER TABLE ' . $this->t('pengguna') . ' ADD CONSTRAINT `fk_uji_dbv010_nip` FOREIGN KEY (`nip`) REFERENCES '
            . $this->t(self::FK_TABLE) . ' (`nip`) ON DELETE RESTRICT ON UPDATE RESTRICT';
        $dropFk = 'ALTER TABLE ' . $this->t('pengguna') . ' DROP FOREIGN KEY `fk_uji_dbv010_nip`';

        $this->db->query($addFk);
        $this->db->query($dropFk);

        $this->downAll();

        $this->assertInstanceOf(DatabaseException::class, $this->catchError(fn () => $this->db->query($addFk)));
    }

    /**
     * Daftar pra-cek tabrakan di migration = seluruh index UNIQUE berkolom string di tabel cakupan.
     */
    public function testCollisionCheckCoversEveryUniqueStringColumn(): void
    {
        $rows = $this->db->query(
            'SELECT s.TABLE_NAME, s.COLUMN_NAME FROM information_schema.STATISTICS s
             JOIN information_schema.COLUMNS c
               ON c.TABLE_SCHEMA = s.TABLE_SCHEMA AND c.TABLE_NAME = s.TABLE_NAME AND c.COLUMN_NAME = s.COLUMN_NAME
             WHERE s.TABLE_SCHEMA = ? AND s.TABLE_NAME IN ? AND s.NON_UNIQUE = 0 AND c.COLLATION_NAME IS NOT NULL
             ORDER BY s.TABLE_NAME, s.COLUMN_NAME',
            [$this->db->getDatabase(), array_map(fn (string $t): string => $this->db->prefixTable($t), self::TABLES)],
        )->getResultArray();

        $actual = array_map(fn (array $r): array => [substr((string) $r['TABLE_NAME'], strlen($this->db->getPrefix())), (string) $r['COLUMN_NAME']], $rows);

        $expected = AlterAuthKeUnicodeCi::UNIQUE_STRING_COLUMNS;
        sort($expected);
        sort($actual);

        $this->assertSame($expected, $actual);
    }

    /**
     * up() di atas tabel yang sudah unicode_ci tidak menjalankan ALTER apa pun (idempoten).
     */
    public function testUpIsIdempotent(): void
    {
        $before = $this->snapshots();

        $this->m1()->up();

        $this->assertStringNotContainsString('ALTER', (string) $this->db->getLastQuery());
        $this->assertSame($before, $this->snapshots());
    }

    // ------------------------------------------------------------------

    /**
     * Migration DBV-010 urut naik.
     *
     * @return list<Migration>
     */
    private function chain(): array
    {
        require_once APPPATH . 'Database/Migrations/2026-09-25-130100_AlterPenggunaAkunNonPegawai.php';
        require_once APPPATH . 'Database/Migrations/2026-09-25-130200_AlterIdentitasAkunIdPengguna.php';

        return [$this->m1(), new AlterPenggunaAkunNonPegawai(), new AlterIdentitasAkunIdPengguna()];
    }

    private function m1(): AlterAuthKeUnicodeCi
    {
        require_once APPPATH . 'Database/Migrations/2026-09-25-130000_AlterAuthKeUnicodeCi.php';

        return new AlterAuthKeUnicodeCi();
    }

    private function downAll(): void
    {
        foreach (array_reverse($this->chain()) as $migration) {
            $migration->down();
        }
    }

    private function upAll(): void
    {
        foreach ($this->chain() as $migration) {
            $migration->up();
        }
    }

    private function catchError(callable $work): ?Throwable
    {
        try {
            $work();
        } catch (Throwable $e) {
            return $e;
        }

        return null;
    }

    private function assertCollation(string $expected): void
    {
        foreach (self::TABLES as $table) {
            $snapshot = $this->snapshot($table);
            $this->assertSame($expected, $snapshot['collation'], "collation tabel {$table}");

            foreach ($snapshot['columns'] as $name => $column) {
                if ($column['COLLATION_NAME'] === null || in_array(strtolower((string) $column['DATA_TYPE']), ['json', 'longtext'], true)) {
                    continue;
                }

                $this->assertSame($expected, $column['COLLATION_NAME'], "collation {$table}.{$name}");
            }
        }
    }

    /**
     * @return array<string, array{collation: string, columns: array<string, array<string, mixed>>, indexes: list<array<string, mixed>>}>
     */
    private function snapshots(): array
    {
        $out = [];

        foreach (self::TABLES as $table) {
            $out[$table] = $this->snapshot($table);
        }

        return $out;
    }

    /**
     * @return array{collation: string, columns: array<string, array<string, mixed>>, indexes: list<array<string, mixed>>}
     */
    private function snapshot(string $table): array
    {
        $binds = [$this->db->getDatabase(), $this->db->prefixTable($table)];

        $columns = [];

        foreach ($this->db->query(
            'SELECT COLUMN_NAME, ORDINAL_POSITION, COLUMN_TYPE, DATA_TYPE, IS_NULLABLE, COLUMN_DEFAULT, COLUMN_COMMENT, EXTRA,
                    CHARACTER_SET_NAME, COLLATION_NAME
             FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION',
            $binds,
        )->getResultArray() as $row) {
            $columns[(string) $row['COLUMN_NAME']] = $row;
        }

        return [
            'collation' => (string) ($this->db->query(
                'SELECT TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
                $binds,
            )->getRowArray()['TABLE_COLLATION'] ?? ''),
            'columns' => $columns,
            'indexes' => $this->db->query(
                'SELECT INDEX_NAME, SEQ_IN_INDEX, COLUMN_NAME, NON_UNIQUE, INDEX_TYPE, SUB_PART
                 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY INDEX_NAME, SEQ_IN_INDEX',
                $binds,
            )->getResultArray(),
        ];
    }

    /**
     * @param array<string, array{collation: string, columns: array<string, array<string, mixed>>, indexes: list<array<string, mixed>>}> $snapshots
     *
     * @return array<string, mixed>
     */
    private function withoutCollation(array $snapshots): array
    {
        foreach ($snapshots as $table => $snapshot) {
            unset($snapshots[$table]['collation']);

            foreach (array_keys($snapshot['columns']) as $column) {
                unset($snapshots[$table]['columns'][$column]['COLLATION_NAME']);
            }
        }

        return $snapshots;
    }

    private function t(string $table): string
    {
        return $this->db->escapeIdentifiers($this->db->prefixTable($table));
    }
}
