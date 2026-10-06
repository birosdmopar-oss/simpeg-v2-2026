<?php

declare(strict_types=1);

namespace Tests\MasterData;

use App\Database\Migrations\CreateWebConfig;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * DBV-006 — skema G-09 hasil migration 2026-10-01-100000_CreateWebConfig harus sama dengan skema yang diajukan ke DB
 * Validator (backend/docs/db-review/G-09-web-config-schema.md Bagian 2): DDL legacy `web_config` [K]
 * (`simpeg01_struktur_lengkap_20261001.sql:7622-7631`) + deviasi v2 (UNIQUE `config_name`, utf8mb4_unicode_ci, COMMENT
 * `updated_by`), dan bisa di-rollback.
 *
 * @internal
 */
final class WebConfigSchemaTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = null;

    private const FILE = '2026-10-01-100000_CreateWebConfig.php';

    /**
     * Kolom => "COLUMN_TYPE[ null]" urut posisi [K].
     */
    private const COLUMNS = [
        'id_web_config' => 'int',
        'config_name'   => 'varchar(255)',
        'config_value'  => 'text null',
        'remark'        => 'varchar(255) null',
        'updated_at'    => 'datetime',
        'updated_by'    => 'int null',
    ];

    /**
     * Index => [NON_UNIQUE, kolom]. Nama index legacy `config_name` dipertahankan; UNIQUE = deviasi [V2].
     */
    private const INDEXES = [
        'PRIMARY'     => [0, ['id_web_config']],
        'config_name' => [0, ['config_name']],
    ];

    protected function tearDown(): void
    {
        if (! $this->tableExists()) {
            $this->migration()->up();
        }

        parent::tearDown();
    }

    public function testSchemaMatchesProposal(): void
    {
        $this->assertSame(self::COLUMNS, $this->columns());
        $this->assertSame(self::INDEXES, $this->indexes());

        $info = $this->db->query(
            'SELECT TABLE_COLLATION, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
            [$this->db->getDatabase(), $this->db->prefixTable('web_config')],
        )->getRowArray();
        $this->assertSame('utf8mb4_unicode_ci', $info['TABLE_COLLATION']);
        $this->assertSame('InnoDB', $info['ENGINE']);

        foreach (['config_name', 'config_value', 'remark'] as $column) {
            $this->assertSame('utf8mb4_unicode_ci', $this->columnInfo($column)['COLLATION_NAME'], $column);
        }

        $this->assertStringContainsString('auto_increment', strtolower((string) $this->columnInfo('id_web_config')['EXTRA']));
        $this->assertStringContainsString('on update current_timestamp', strtolower((string) $this->columnInfo('updated_at')['EXTRA']));
        $this->assertSame('current_timestamp', strtolower(trim((string) $this->columnInfo('updated_at')['COLUMN_DEFAULT'], "'()")));
        $this->assertSame('id_pengguna yang terakhir mengubah', $this->columnInfo('updated_by')['COLUMN_COMMENT']);

        // Tanpa FK (legacy tidak punya; updated_by → pengguna dijaga aplikasi seperti tabel master lain).
        $fk = $this->db->query(
            'SELECT COUNT(*) AS n FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL',
            [$this->db->getDatabase(), $this->db->prefixTable('web_config')],
        )->getRowArray();
        $this->assertSame(0, (int) $fk['n']);
    }

    /**
     * UNIQUE `config_name` (deviasi [V2]) tidak peka huruf besar/kecil (unicode_ci); key ber-`/` legacy diterima.
     */
    public function testConfigNameIsUniqueCaseInsensitive(): void
    {
        $this->db->table('web_config')->insert(['config_name' => 'TL1/PSW1', 'config_value' => '0.5']);
        $this->db->table('web_config')->insert(['config_name' => 'nama_kementerian', 'config_value' => 'X']);

        foreach (['TL1/PSW1', 'tl1/psw1', 'NAMA_KEMENTERIAN'] as $dup) {
            $code = null;

            try {
                if ($this->db->table('web_config')->insert(['config_name' => $dup, 'config_value' => 'Y']) === false) {
                    $code = (int) $this->db->error()['code'];
                }
            } catch (\Throwable $e) {
                $code = (int) $e->getCode();
            }

            $this->assertSame(1062, $code, $dup);
        }

        $this->assertSame(2, $this->db->table('web_config')->countAllResults());

        // config_value NULL diterima DB [K] (aplikasi tidak pernah menulis NULL); updated_at terisi otomatis.
        $this->db->table('web_config')->insert(['config_name' => 'kosong']);
        $row = $this->db->table('web_config')->where('config_name', 'kosong')->get()->getRowArray();
        $this->assertNull($row['config_value']);
        $this->assertNotNull($row['updated_at']);
    }

    public function testMigrationRollsBackAndUpAgain(): void
    {
        $migration = $this->migration();
        $migration->down();
        $this->assertFalse($this->tableExists());

        $migration->down(); // idempoten (DROP IF EXISTS)

        $migration->up();
        $this->assertTrue($this->tableExists());
        $this->assertSame(self::COLUMNS, $this->columns());
    }

    // ------------------------------------------------------------------

    private function migration(): CreateWebConfig
    {
        require_once APPPATH . 'Database/Migrations/' . self::FILE;

        return new CreateWebConfig();
    }

    private function tableExists(): bool
    {
        return (int) $this->db->query(
            'SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
            [$this->db->getDatabase(), $this->db->prefixTable('web_config')],
        )->getRowArray()['n'] > 0;
    }

    /**
     * @return array<string, string>
     */
    private function columns(): array
    {
        $rows = $this->db->query(
            'SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION',
            [$this->db->getDatabase(), $this->db->prefixTable('web_config')],
        )->getResultArray();

        $columns = [];

        foreach ($rows as $row) {
            $type = (string) preg_replace('/^(tinyint|int)\(\d+\)/', '$1', strtolower((string) $row['COLUMN_TYPE']));

            $columns[(string) $row['COLUMN_NAME']] = $type . ($row['IS_NULLABLE'] === 'YES' ? ' null' : '');
        }

        return $columns;
    }

    /**
     * @return array<string, mixed>
     */
    private function columnInfo(string $column): array
    {
        return $this->db->query(
            'SELECT EXTRA, COLUMN_DEFAULT, COLUMN_COMMENT, COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$this->db->getDatabase(), $this->db->prefixTable('web_config'), $column],
        )->getRowArray() ?? [];
    }

    /**
     * @return array<string, array{0: int, 1: list<string>}>
     */
    private function indexes(): array
    {
        $rows = $this->db->query(
            'SELECT INDEX_NAME, NON_UNIQUE, COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY INDEX_NAME, SEQ_IN_INDEX',
            [$this->db->getDatabase(), $this->db->prefixTable('web_config')],
        )->getResultArray();

        $indexes = [];

        foreach ($rows as $row) {
            $indexes[(string) $row['INDEX_NAME']] ??= [(int) $row['NON_UNIQUE'], []];
            $indexes[(string) $row['INDEX_NAME']][1][] = (string) $row['COLUMN_NAME'];
        }

        $ordered = [];

        foreach (array_keys(self::INDEXES) as $name) {
            if (isset($indexes[$name])) {
                $ordered[$name] = $indexes[$name];
                unset($indexes[$name]);
            }
        }

        return $ordered + $indexes;
    }
}
