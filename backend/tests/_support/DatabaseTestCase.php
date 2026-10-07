<?php

declare(strict_types=1);

namespace Tests\Support;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\Fabricator;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\Group;
use ReflectionClass;
use ReflectionMethod;
use Tests\Support\Database\Isolasi\Connection as KoneksiIsolasi;

/**
 * Base case SEMUA test yang memakai database (MAKE-001). Pengganti `extends CIUnitTestCase` + `use DatabaseTestTrait`
 * + `$refresh = true`.
 *
 * Cara kerja:
 * - Skema dimigrasi (regress + latest, semua namespace — sama dengan `$refresh = true` lama) SEKALI per proses
 *   PHPUnit/shard, bukan per test.
 * - Seed `$seed` ditulis (commit) sekali dan dipakai ulang selama test berikutnya meminta seed yang sama dan database
 *   masih bersih; bila seed berbeda, data dikosongkan dulu lalu seed baru ditulis.
 * - Setiap test berjalan di dalam "bingkai uji" (transaksi luar driver Tests\Support\Database\Isolasi) yang di-ROLLBACK
 *   di tearDown. Counter AUTO_INCREMENT yang bergeser dipulihkan ke nilai sesudah seed, sehingga id hasil insert sama
 *   dengan saat setiap test memakai migrate:refresh + seed.
 * - Test yang TIDAK bisa berjalan di dalam satu transaksi ditandai `#[Group('db-isolasi-penuh')]` (di kelas atau di
 *   method): DDL (down()/up() migration, CREATE/ALTER/DROP/TRUNCATE/RENAME, trigger, ALTER ... AUTO_INCREMENT),
 *   koneksi/proses worker lain yang harus melihat, menunggu, atau menulis data. Test itu menulis dan commit sungguhan
 *   seperti dulu. Sebelum test berikutnya database dipulihkan: bila skema (SHOW CREATE TABLE semua tabel, trigger,
 *   routine, isi tabel migrations) sama dengan sesudah migrate, cukup semua tabel berisi dikosongkan + baris bawaan
 *   migration dipasang ulang + AUTO_INCREMENT dipulihkan; bila berbeda, refresh penuh (regress + latest).
 * - Bingkai yang putus (implicit commit karena DDL di test tanpa tanda) menggagalkan test itu dengan pesan jelas dan
 *   database dipulihkan sebelum test berikutnya — data tidak bocor ke test lain secara diam-diam.
 *
 * Properti DatabaseTestTrait yang tetap berlaku: `$seed`, `$basePath`, `$migrate`, `$namespace`, `$DBGroup`.
 * `$refresh` / `$migrateOnce` / `$seedOnce` tidak dipakai lagi.
 */
abstract class DatabaseTestCase extends CIUnitTestCase
{
    use DatabaseTestTrait;

    public const GRUP_ISOLASI_PENUH = 'db-isolasi-penuh';

    private const BELUM_SIAP = 0;
    private const BERSIH     = 1;
    private const KOTOR      = 2;

    protected $migrate   = true;
    protected $namespace = null;

    /**
     * BELUM_SIAP: perlu refresh penuh. BERSIH: skema & data = sesudah migrate + seed self::$seedTerpasang.
     * KOTOR: test isolasi penuh / bingkai putus sudah meng-commit tulisan — skema dicek ulang, data dipulihkan.
     */
    private static int $keadaan = self::BELUM_SIAP;

    /**
     * Seed yang sudah ter-commit di atas keadaan sesudah migrate ('' = tanpa seed).
     */
    private static string $seedTerpasang = '';

    /**
     * AUTO_INCREMENT tiap tabel sesudah migrate: nama tabel (ber-prefix) => nilai.
     *
     * @var array<string, int>
     */
    private static array $autoIncrementMigrate = [];

    /**
     * AUTO_INCREMENT tiap tabel sesudah migrate + seed terpasang — target pemulihan sesudah tiap test.
     *
     * @var array<string, int>
     */
    private static array $autoIncrementSeed = [];

    /**
     * Baris yang sudah ada sesudah migrate (mis. sentinel wilayah, jenis_rwy): tabel => [kolom, baris].
     *
     * @var array<string, array{0: list<string>, 1: list<array<string, mixed>>}>
     */
    private static array $dataMigrate = [];

    private static string $sidikSkema      = '';
    private static string $sidikMigrations = '';

    private static ?bool $adaStatsExpiry = null;

    private bool $isolasiTransaksi = false;

    protected function setUpDatabase()
    {
        $this->loadDependencies();

        $this->koneksi();

        $this->siapkanDatabase();
        $this->siapkanSeed();

        // Fabricator::resetCounts() dulu dijalankan bersama refresh di setiap test.
        Fabricator::resetCounts();

        $this->isolasiTransaksi = ! $this->butuhIsolasiPenuh();

        if ($this->isolasiTransaksi) {
            $this->koneksi()->mulaiBingkaiUji();
        } else {
            // Test ini menulis & commit sungguhan: database kotor sampai dipulihkan sebelum test berikutnya.
            self::$keadaan = self::KOTOR;
        }
    }

    protected function tearDownDatabase()
    {
        if (! $this->isolasiTransaksi) {
            $this->clearInsertCache();

            return;
        }

        $this->isolasiTransaksi = false;

        if (! $this->koneksi()->akhiriBingkaiUji()) {
            self::$keadaan = self::KOTOR;

            throw new AssertionFailedError(
                static::class . '::' . $this->name() . ' memutus bingkai transaksi uji (implicit commit: DDL seperti '
                . 'CREATE/ALTER/DROP/TRUNCATE/RENAME/trigger/down()-up() migration, atau koneksi tersambung ulang). '
                . "Tandai test/kelasnya #[Group('" . self::GRUP_ISOLASI_PENUH . "')] (lihat AGENTS.md §3).",
            );
        }

        $this->pulihkanAutoIncrement(self::$autoIncrementSeed);
    }

    /**
     * Pastikan skema & data = keadaan sesudah migrate (+ seed terpasang) sebelum test dimulai.
     */
    private function siapkanDatabase(): void
    {
        if (self::$keadaan === self::BERSIH && $this->sidikMigrations() !== self::$sidikMigrations) {
            // Sesuatu di luar base case ini (mis. kelas yang masih memakai DatabaseTestTrait langsung) me-refresh skema.
            self::$keadaan = self::BELUM_SIAP;
        }

        if (self::$keadaan === self::KOTOR) {
            if ($this->sidikSkema() === self::$sidikSkema) {
                $this->pulihkanData();
                self::$keadaan = self::BERSIH;
            } else {
                self::$keadaan = self::BELUM_SIAP;
            }
        }

        if (self::$keadaan === self::BELUM_SIAP) {
            $this->regressDatabase();
            $this->migrateDatabase();

            self::$autoIncrementMigrate = $this->bacaAutoIncrement();
            self::$autoIncrementSeed    = self::$autoIncrementMigrate;
            self::$dataMigrate          = $this->bacaDataMigrate();
            self::$sidikSkema           = $this->sidikSkema();
            self::$sidikMigrations      = $this->sidikMigrations();
            self::$seedTerpasang        = '';
            self::$keadaan              = self::BERSIH;
        }
    }

    /**
     * Tulis (commit) seed kelas ini bila belum terpasang; seed lain yang terpasang dibuang dulu.
     */
    private function siapkanSeed(): void
    {
        $seeds = $this->seed === '' ? [] : (array) $this->seed;
        $kunci = $seeds === [] ? '' : json_encode([$this->basePath, $seeds], JSON_THROW_ON_ERROR);

        if ($kunci === self::$seedTerpasang) {
            return;
        }

        if (self::$seedTerpasang !== '') {
            $this->pulihkanData();
        }

        $this->setUpSeed();

        self::$autoIncrementSeed = $this->bacaAutoIncrement();
        self::$seedTerpasang     = $kunci;
    }

    /**
     * Kosongkan semua tabel yang berisi (kecuali migrations), pasang ulang baris bawaan migration, pulihkan
     * AUTO_INCREMENT sesudah migrate. Hanya dipanggil bila skema terbukti sama dengan sesudah migrate.
     */
    private function pulihkanData(): void
    {
        $migrations = $this->db->prefixTable('migrations');

        $this->db->query('SET FOREIGN_KEY_CHECKS = 0');

        try {
            foreach ($this->tabelDasar() as $table) {
                if ($table === $migrations) {
                    continue;
                }

                $quoted = $this->kutip($table);

                if (isset(self::$dataMigrate[$table]) || $this->db->query("SELECT 1 FROM {$quoted} LIMIT 1")->getRowArray() !== null) {
                    $this->db->query("TRUNCATE TABLE {$quoted}");
                }

                if (isset(self::$dataMigrate[$table])) {
                    [$columns, $rows] = self::$dataMigrate[$table];
                    $values           = array_map(
                        fn (array $row): string => '(' . implode(', ', array_map(fn (string $c) => $this->db->escape($row[$c]), $columns)) . ')',
                        $rows,
                    );
                    $this->db->query(
                        "INSERT INTO {$quoted} (" . implode(', ', array_map($this->kutip(...), $columns)) . ') VALUES ' . implode(', ', $values),
                    );
                }
            }
        } finally {
            $this->db->query('SET FOREIGN_KEY_CHECKS = 1');
        }

        $this->pulihkanAutoIncrement(self::$autoIncrementMigrate);

        self::$autoIncrementSeed = self::$autoIncrementMigrate;
        self::$seedTerpasang     = '';
    }

    /**
     * ROLLBACK/TRUNCATE tidak selalu mengembalikan counter AUTO_INCREMENT InnoDB ke nilai awal; set ulang tabel yang
     * bergeser (InnoDB memakai MAX(id)+1 bila lebih besar).
     *
     * @param array<string, int> $target
     */
    private function pulihkanAutoIncrement(array $target): void
    {
        foreach ($this->bacaAutoIncrement() as $table => $value) {
            $awal = $target[$table] ?? 1;

            if ($value !== $awal) {
                $this->db->query('ALTER TABLE ' . $this->kutip($table) . ' AUTO_INCREMENT = ' . $awal);
            }
        }
    }

    /**
     * @return array<string, int>
     */
    private function bacaAutoIncrement(): array
    {
        // MySQL 8: tanpa ini information_schema.TABLES menyajikan statistik tersimpan (cache 24 jam), bukan counter
        // terkini. MariaDB tidak punya variabel ini (dan selalu membaca counter terkini).
        self::$adaStatsExpiry ??= $this->db->query("SHOW VARIABLES LIKE 'information_schema_stats_expiry'")->getRowArray() !== null;

        if (self::$adaStatsExpiry) {
            $this->db->query('SET SESSION information_schema_stats_expiry = 0');
        }

        $rows = $this->db->query(
            'SELECT TABLE_NAME AS t, AUTO_INCREMENT AS ai FROM information_schema.TABLES '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND AUTO_INCREMENT IS NOT NULL',
        )->getResultArray();

        $result = [];

        foreach ($rows as $row) {
            $result[(string) $row['t']] = (int) $row['ai'];
        }

        return $result;
    }

    /**
     * @return array<string, array{0: list<string>, 1: list<array<string, mixed>>}>
     */
    private function bacaDataMigrate(): array
    {
        $migrations = $this->db->prefixTable('migrations');
        $result     = [];

        foreach ($this->tabelDasar() as $table) {
            if ($table === $migrations) {
                continue;
            }

            $rows = $this->db->query('SELECT * FROM ' . $this->kutip($table))->getResultArray();

            if ($rows === []) {
                continue;
            }

            $columns = array_map(
                static fn (array $r): string => (string) $r['c'],
                $this->db->query(
                    "SELECT COLUMN_NAME AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND EXTRA NOT LIKE '%GENERATED%' ORDER BY ORDINAL_POSITION",
                    [$table],
                )->getResultArray(),
            );
            $result[$table] = [$columns, $rows];
        }

        return $result;
    }

    /**
     * Sidik skema: SHOW CREATE TABLE/VIEW semua tabel (tanpa nilai AUTO_INCREMENT), trigger, routine, isi migrations.
     */
    private function sidikSkema(): string
    {
        $parts  = [];
        $tables = $this->db->query(
            'SELECT TABLE_NAME AS t, TABLE_TYPE AS jenis FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME',
        )->getResultArray();

        foreach ($tables as $row) {
            $isView  = $row['jenis'] === 'VIEW';
            $create  = $this->db->query(($isView ? 'SHOW CREATE VIEW ' : 'SHOW CREATE TABLE ') . $this->kutip((string) $row['t']))->getRowArray();
            $parts[] = preg_replace('/ AUTO_INCREMENT=\d+/', '', (string) ($create[$isView ? 'Create View' : 'Create Table'] ?? ''));
        }

        $parts[] = $this->db->query(
            'SELECT TRIGGER_NAME, EVENT_OBJECT_TABLE, ACTION_TIMING, EVENT_MANIPULATION, ACTION_STATEMENT FROM information_schema.TRIGGERS '
            . 'WHERE TRIGGER_SCHEMA = DATABASE() ORDER BY TRIGGER_NAME',
        )->getResultArray();
        $parts[] = $this->db->query(
            'SELECT ROUTINE_NAME, ROUTINE_TYPE FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE() ORDER BY ROUTINE_NAME',
        )->getResultArray();

        if ($this->db->tableExists('migrations', false)) {
            $parts[] = $this->db->table('migrations')->orderBy('id')->get()->getResultArray();
        }

        return md5(json_encode($parts, JSON_THROW_ON_ERROR));
    }

    private function sidikMigrations(): string
    {
        if (! $this->db->tableExists('migrations', false)) {
            return '';
        }

        $row = $this->db->table('migrations')->select('COUNT(*) AS n, COALESCE(MAX(id), 0) AS maks')->get()->getRowArray();

        return ($row['n'] ?? '0') . ':' . ($row['maks'] ?? '0');
    }

    /**
     * @return list<string> nama tabel dasar (ber-prefix) di database test
     */
    private function tabelDasar(): array
    {
        return array_map(
            static fn (array $r): string => (string) $r['t'],
            $this->db->query(
                "SELECT TABLE_NAME AS t FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME",
            )->getResultArray(),
        );
    }

    private function koneksi(): KoneksiIsolasi
    {
        if (! $this->db instanceof KoneksiIsolasi) {
            throw new AssertionFailedError(
                'Grup database `tests` harus memakai driver Tests\Support\Database\Isolasi (phpunit.dist.xml '
                . '`database.tests.DBDriver`); didapat ' . $this->db::class . '.',
            );
        }

        return $this->db;
    }

    private function kutip(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    private function butuhIsolasiPenuh(): bool
    {
        $reflections = [new ReflectionClass($this)];

        if (method_exists($this, $this->name())) {
            $reflections[] = new ReflectionMethod($this, $this->name());
        }

        foreach ($reflections as $reflection) {
            foreach ($reflection->getAttributes(Group::class) as $attribute) {
                if ($attribute->newInstance()->name() === self::GRUP_ISOLASI_PENUH) {
                    return true;
                }
            }
        }

        return false;
    }
}
