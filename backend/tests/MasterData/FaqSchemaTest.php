<?php

declare(strict_types=1);

namespace Tests\MasterData;

use App\Database\Migrations\CreateFaq;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * DBV-002 — skema G-10 FAQ hasil migration 2026-09-24-000001_CreateFaq harus sama dengan skema yang diajukan ke DB
 * Validator (backend/docs/db-review/G-10-faq-schema.md): DDL legacy simpeg_prod.sql:949-1030 [K] + deviasi v2
 * (FK RESTRICT, `faq_article.order`, UNIQUE nama, status 10, tanpa FK faq_rate.nip), dan bisa di-rollback.
 *
 * @internal
 */
final class FaqSchemaTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = null;

    private const TABLES = ['faq_topic', 'faq_sub_topic', 'faq_article', 'faq_rate', 'faq_related_article'];

    /**
     * Kolom yang diharapkan: tabel => [kolom => "COLUMN_TYPE[ null]"] urut posisi (" null" = boleh NULL).
     */
    private const COLUMNS = [
        'faq_topic' => [
            'id_faq_topic' => 'int', 'faq_topic' => 'varchar(255)', 'icon' => 'varchar(255) null', 'order' => 'int',
            'status'       => 'int', 'remark' => 'tinytext null', 'created_at' => 'datetime', 'created_by' => 'int null',
            'updated_at'   => 'datetime null', 'updated_by' => 'int null',
        ],
        'faq_sub_topic' => [
            'id_faq_sub_topic' => 'int', 'id_faq_topic' => 'int', 'faq_sub_topic' => 'varchar(255)', 'order' => 'int',
            'status'           => 'tinyint', 'remark' => 'tinytext null', 'created_at' => 'datetime', 'created_by' => 'int null',
            'updated_at'       => 'datetime null', 'updated_by' => 'int null',
        ],
        'faq_article' => [
            'id_faq_article'   => 'int', 'id_faq_sub_topic' => 'int', 'title' => 'varchar(255)', 'content' => 'longtext',
            'content_stripped' => 'longtext', 'order' => 'int', 'status' => 'tinyint', 'created_at' => 'datetime',
            'created_by'       => 'int null', 'updated_at' => 'datetime null', 'updated_by' => 'int null',
        ],
        'faq_rate' => [
            'id_faq_article' => 'int', 'nip' => 'varchar(30)', 'rate' => 'tinyint', 'reason' => 'tinytext null',
            'created_at'     => 'datetime', 'created_by' => 'int null',
        ],
        'faq_related_article' => [
            'id_article_main' => 'int', 'id_article_related' => 'int',
        ],
    ];

    /**
     * Index non-FK: tabel => [nama index => [NON_UNIQUE, INDEX_TYPE, kolom berurutan]].
     */
    private const INDEXES = [
        'faq_topic' => [
            'PRIMARY'           => [0, 'BTREE', ['id_faq_topic']],
            'uq_faq_topic_nama' => [0, 'BTREE', ['faq_topic']],
        ],
        'faq_sub_topic' => [
            'PRIMARY'                        => [0, 'BTREE', ['id_faq_sub_topic']],
            'uq_faq_sub_topic_nama'          => [0, 'BTREE', ['id_faq_topic', 'faq_sub_topic']],
            'fk_id_faq_topic_faqst_to_faqto' => [1, 'BTREE', ['id_faq_topic']],
        ],
        'faq_article' => [
            'PRIMARY'                            => [0, 'BTREE', ['id_faq_article']],
            'uq_faq_article_nama'                => [0, 'BTREE', ['id_faq_sub_topic', 'title']],
            'fk_id_faq_sub_topic_faqar_to_faqst' => [1, 'BTREE', ['id_faq_sub_topic']],
            'status'                             => [1, 'BTREE', ['status']],
            'title_content_stripped'             => [1, 'FULLTEXT', ['title', 'content_stripped']],
        ],
        'faq_rate' => [
            'PRIMARY'               => [0, 'BTREE', ['id_faq_article', 'nip']],
            'fk_nip_faqrate_to_peg' => [1, 'BTREE', ['nip']],
        ],
        'faq_related_article' => [
            'PRIMARY'                               => [0, 'BTREE', ['id_article_main', 'id_article_related']],
            'fk_id_article_related_faqrel_to_faqar' => [1, 'BTREE', ['id_article_related']],
        ],
    ];

    /**
     * FK nama legacy (ERD simpeg01), urut nama: [tabel anak, kolom, tabel induk, kolom induk, UPDATE_RULE, DELETE_RULE].
     * Kolom induk ikut dicek: MySQL/MariaDB menerima FK ke kolom induk yang sekadar ber-index (non-unik), jadi REFERENCES
     * ke kolom yang salah tetap berhasil dibuat. Aksi RESTRICT/RESTRICT = deviasi dari legacy CASCADE (keputusan U4).
     * Tidak ada FK dari faq_rate.nip (D2).
     */
    private const FOREIGN_KEYS = [
        'fk_id_article_main_faqrel_to_faqar'    => ['faq_related_article', 'id_article_main', 'faq_article', 'id_faq_article', 'RESTRICT', 'RESTRICT'],
        'fk_id_article_related_faqrel_to_faqar' => ['faq_related_article', 'id_article_related', 'faq_article', 'id_faq_article', 'RESTRICT', 'RESTRICT'],
        'fk_id_faq_article_faqrate_to_faqar'    => ['faq_rate', 'id_faq_article', 'faq_article', 'id_faq_article', 'RESTRICT', 'RESTRICT'],
        'fk_id_faq_sub_topic_faqar_to_faqst'    => ['faq_article', 'id_faq_sub_topic', 'faq_sub_topic', 'id_faq_sub_topic', 'RESTRICT', 'RESTRICT'],
        'fk_id_faq_topic_faqst_to_faqto'        => ['faq_sub_topic', 'id_faq_topic', 'faq_topic', 'id_faq_topic', 'RESTRICT', 'RESTRICT'],
    ];

    private const STATUS_COMMENT = '1: Aktif, 2: Tidak Aktif, 10: Dihapus';

    /**
     * Test rollback/kegagalan memanggil down()/up() di tengah jalan; kalau assertion gagal sebelum skema pulih, tabel
     * FAQ harus dibuat lagi (termasuk membuang tabel penghalang simulasi) agar migrate:refresh test berikutnya
     * konsisten dengan tabel migrations.
     */
    protected function tearDown(): void
    {
        foreach (self::TABLES as $table) {
            if (! $this->tableExists($table)) {
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

    public function testAuditAndStatusColumnDetails(): void
    {
        foreach (['faq_topic', 'faq_sub_topic', 'faq_article'] as $table) {
            $status = $this->columnInfo($table, 'status');
            $this->assertSame('1', $this->unquote($status['COLUMN_DEFAULT']), "{$table}.status default 1 (Aktif)");
            $this->assertSame(self::STATUS_COMMENT, $status['COLUMN_COMMENT'], "{$table}.status COMMENT");
            $this->assertSame('1', $this->unquote($this->columnInfo($table, 'order')['COLUMN_DEFAULT']), "{$table}.order default 1");

            $pk = str_replace('faq_', 'id_faq_', $table);
            $this->assertStringContainsString('auto_increment', strtolower((string) $this->columnInfo($table, $pk)['EXTRA']), "{$table}.{$pk}");

            $this->assertStringContainsString('on update current_timestamp', strtolower((string) $this->columnInfo($table, 'updated_at')['EXTRA']), "{$table}.updated_at");
            $this->assertSame('id_pengguna pembuat', $this->columnInfo($table, 'created_by')['COLUMN_COMMENT']);
            $this->assertSame('id_pengguna yang terakhir mengubah', $this->columnInfo($table, 'updated_by')['COLUMN_COMMENT']);
        }

        foreach (['faq_topic', 'faq_sub_topic', 'faq_article', 'faq_rate'] as $table) {
            $this->assertSame('current_timestamp', strtolower(trim((string) $this->columnInfo($table, 'created_at')['COLUMN_DEFAULT'], "'()")), "{$table}.created_at");
        }

        // faq_rate tanpa updated_* (rating tidak bisa diubah), tetapi created_by ber-COMMENT sama [V2].
        $this->assertSame('id_pengguna pembuat', $this->columnInfo('faq_rate', 'created_by')['COLUMN_COMMENT']);

        $this->assertSame('1: Membantu, 2: Kurang Membantu', $this->columnInfo('faq_rate', 'rate')['COLUMN_COMMENT']);
    }

    /**
     * Lapis DB: FK RESTRICT menolak hard delete induk yang masih punya anak; UNIQUE menolak nama ganda case-insensitive.
     */
    public function testConstraintsAreEnforced(): void
    {
        $this->db->table('faq_topic')->insert(['id_faq_topic' => 1, 'faq_topic' => 'Akun']);
        $this->db->table('faq_sub_topic')->insert(['id_faq_sub_topic' => 1, 'id_faq_topic' => 1, 'faq_sub_topic' => 'Kata Sandi']);

        $this->assertDbWriteFails(fn () => $this->db->table('faq_topic')->where('id_faq_topic', 1)->delete());
        $this->assertDbWriteFails(fn () => $this->db->table('faq_topic')->insert(['faq_topic' => 'AKUN']));
        $this->assertDbWriteFails(fn () => $this->db->table('faq_sub_topic')->insert(['id_faq_topic' => 1, 'faq_sub_topic' => 'kata sandi']));
        $this->assertDbWriteFails(fn () => $this->db->table('faq_rate')->insert(['id_faq_article' => 99, 'nip' => '1', 'rate' => 1]));

        // nip belum ber-FK (tabel pegawai menyusul di B-01): nip sembarang diterima.
        $this->db->table('faq_article')->insert(['id_faq_article' => 1, 'id_faq_sub_topic' => 1, 'title' => 'Judul', 'content' => '<p>x</p>', 'content_stripped' => 'x']);
        $this->db->table('faq_rate')->insert(['id_faq_article' => 1, 'nip' => 'TIDAK-ADA-DI-PEGAWAI', 'rate' => 1]);
        $this->seeInDatabase('faq_rate', ['id_faq_article' => 1, 'nip' => 'TIDAK-ADA-DI-PEGAWAI']);
        $this->assertDbWriteFails(fn () => $this->db->table('faq_rate')->insert(['id_faq_article' => 1, 'nip' => 'TIDAK-ADA-DI-PEGAWAI', 'rate' => 2]));

        $this->seeInDatabase('faq_topic', ['id_faq_topic' => 1]);
    }

    /**
     * down() menghapus kelima tabel (urutan anak → induk), up() membuatnya kembali persis.
     */
    public function testMigrationRollsBackAndUpAgain(): void
    {
        $migration = $this->migration();
        $migration->down();

        foreach (self::TABLES as $table) {
            $this->assertFalse($this->tableExists($table), "{$table} harus terhapus oleh down()");
        }

        // Tabel lain (Batch 1) tidak tersentuh.
        $this->assertTrue($this->tableExists('provinsi'));

        $migration->up();
        $this->assertSchema();
    }

    /**
     * CR-003: DDL MySQL/MariaDB ter-commit per statement dan migration yang gagal tidak tercatat. Bila salah satu CREATE
     * gagal di tengah up(), tabel yang sempat dibuat PADA RUN ITU di-drop (urutan terbalik) lalu error dilempar ulang,
     * sehingga `migrate` bisa langsung diulang tanpa DDL manual. Tabel yang sudah ada sebelum run tidak disentuh.
     * Kegagalan disimulasikan dengan tabel penghalang bernama `faq_rate` (CREATE ke-4 gagal, error 1050).
     */
    public function testFailedUpDropsOnlyTablesCreatedInThatRun(): void
    {
        $migration = $this->migration();
        $migration->down();

        $blocker = $this->db->escapeIdentifiers($this->db->prefixTable('faq_rate'));
        $this->db->query("CREATE TABLE {$blocker} (`penghalang` INT NOT NULL) ENGINE=InnoDB");

        $error = null;

        try {
            $migration->up();
        } catch (\Throwable $e) {
            $error = $e;
        }

        $this->assertNotNull($error, 'up() harus gagal karena faq_rate sudah ada.');
        $this->assertStringContainsString('faq_rate', $error->getMessage());

        foreach (['faq_topic', 'faq_sub_topic', 'faq_article', 'faq_related_article'] as $table) {
            $this->assertFalse($this->tableExists($table), "{$table} tidak boleh tertinggal setelah up() gagal");
        }

        // Tabel yang sudah ada sebelum run tidak ikut di-drop.
        $this->assertSame(['penghalang' => 'int'], $this->columns('faq_rate'));

        // Setelah penyebabnya dibereskan, up() bisa langsung diulang.
        $this->db->query("DROP TABLE {$blocker}");
        $migration->up();
        $this->assertSchema();
    }

    private function assertSchema(): void
    {
        foreach (self::TABLES as $table) {
            $this->assertSame(self::COLUMNS[$table], $this->columns($table), "kolom {$table}");
            $this->assertSame('utf8mb4_unicode_ci', $this->tableCollation($table), "collation {$table}");
            $this->assertSame('InnoDB', $this->tableEngine($table), "engine {$table}");
            $this->assertSame(self::INDEXES[$table], $this->indexes($table), "index {$table}");

            foreach ($this->stringColumnCollations($table) as $column => $collation) {
                $this->assertSame('utf8mb4_unicode_ci', $collation, "collation {$table}.{$column}");
            }
        }

        $this->assertSame(self::FOREIGN_KEYS, $this->foreignKeys());
        $this->assertSame([], $this->foreignKeysOn('faq_rate', 'nip'), 'faq_rate.nip belum ber-FK (D2, menyusul di B-01)');
    }

    private function assertDbWriteFails(callable $write): void
    {
        $failed = false;

        try {
            $failed = $write() === false;
        } catch (\Throwable) {
            $failed = true;
        }

        $this->assertTrue($failed, 'Constraint DB harus menolak penulisan ini.');
    }

    private function migration(): CreateFaq
    {
        require_once APPPATH . 'Database/Migrations/2026-09-24-000001_CreateFaq.php';

        return new CreateFaq();
    }

    private function unquote(mixed $value): string
    {
        return trim((string) $value, "'");
    }

    private function tableExists(string $table): bool
    {
        return $this->db->query(
            'SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
            [$this->db->getDatabase(), $this->db->prefixTable($table)],
        )->getRowArray()['n'] > 0;
    }

    /**
     * @return array<string, string> kolom => "COLUMN_TYPE[ null]", urut posisi
     */
    private function columns(string $table): array
    {
        $rows = $this->db->query(
            'SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION',
            [$this->db->getDatabase(), $this->db->prefixTable($table)],
        )->getResultArray();

        $columns = [];

        foreach ($rows as $row) {
            // MariaDB/MySQL lama menulis lebar tampilan (tinyint(4), int(11)); samakan dengan MySQL 8.
            $type = (string) preg_replace('/^(tinyint|int)\(\d+\)/', '$1', strtolower((string) $row['COLUMN_TYPE']));

            $columns[(string) $row['COLUMN_NAME']] = $type . ($row['IS_NULLABLE'] === 'YES' ? ' null' : '');
        }

        return $columns;
    }

    /**
     * @return array<string, mixed>
     */
    private function columnInfo(string $table, string $column): array
    {
        return $this->db->query(
            'SELECT EXTRA, COLUMN_DEFAULT, COLUMN_COMMENT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$this->db->getDatabase(), $this->db->prefixTable($table), $column],
        )->getRowArray() ?? [];
    }

    /**
     * @return array<string, string> kolom string => collation
     */
    private function stringColumnCollations(string $table): array
    {
        $rows = $this->db->query(
            'SELECT COLUMN_NAME, COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLLATION_NAME IS NOT NULL',
            [$this->db->getDatabase(), $this->db->prefixTable($table)],
        )->getResultArray();

        return array_column($rows, 'COLLATION_NAME', 'COLUMN_NAME');
    }

    private function tableCollation(string $table): string
    {
        return (string) $this->tableInfo($table)['TABLE_COLLATION'];
    }

    private function tableEngine(string $table): string
    {
        return (string) $this->tableInfo($table)['ENGINE'];
    }

    /**
     * @return array<string, mixed>
     */
    private function tableInfo(string $table): array
    {
        return $this->db->query(
            'SELECT TABLE_COLLATION, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
            [$this->db->getDatabase(), $this->db->prefixTable($table)],
        )->getRowArray() ?? [];
    }

    /**
     * @return array<string, array{0: int, 1: string, 2: list<string>}> nama index => [NON_UNIQUE, INDEX_TYPE, kolom], urut
     *                                                                  seperti konstanta INDEXES (PRIMARY, UNIQUE, lainnya)
     */
    private function indexes(string $table): array
    {
        $rows = $this->db->query(
            'SELECT INDEX_NAME, NON_UNIQUE, INDEX_TYPE, COLUMN_NAME FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY INDEX_NAME, SEQ_IN_INDEX',
            [$this->db->getDatabase(), $this->db->prefixTable($table)],
        )->getResultArray();

        $indexes = [];

        foreach ($rows as $row) {
            $name = (string) $row['INDEX_NAME'];
            $indexes[$name] ??= [(int) $row['NON_UNIQUE'], (string) $row['INDEX_TYPE'], []];
            $indexes[$name][2][] = (string) $row['COLUMN_NAME'];
        }

        // Bandingkan tanpa bergantung urutan kembalian information_schema: ikuti urutan konstanta.
        $ordered = [];

        foreach (array_keys(self::INDEXES[$table]) as $name) {
            if (isset($indexes[$name])) {
                $ordered[$name] = $indexes[$name];
                unset($indexes[$name]);
            }
        }

        return $ordered + $indexes;
    }

    /**
     * Seluruh FK dari/ke tabel FAQ: nama => [tabel anak, kolom, tabel induk, kolom induk, UPDATE_RULE, DELETE_RULE],
     * urut nama.
     *
     * @return array<string, array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string}>
     */
    private function foreignKeys(): array
    {
        $tables = array_map(fn (string $t): string => $this->db->prefixTable($t), self::TABLES);

        $rows = $this->db->query(
            'SELECT k.CONSTRAINT_NAME, k.TABLE_NAME, k.COLUMN_NAME, k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME, r.UPDATE_RULE, r.DELETE_RULE
             FROM information_schema.KEY_COLUMN_USAGE k
             JOIN information_schema.REFERENTIAL_CONSTRAINTS r
               ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME AND r.TABLE_NAME = k.TABLE_NAME
             WHERE k.TABLE_SCHEMA = ? AND k.REFERENCED_TABLE_NAME IS NOT NULL AND (k.TABLE_NAME IN ? OR k.REFERENCED_TABLE_NAME IN ?)
             ORDER BY k.CONSTRAINT_NAME',
            [$this->db->getDatabase(), $tables, $tables],
        )->getResultArray();

        $fks = [];

        foreach ($rows as $row) {
            $fks[(string) $row['CONSTRAINT_NAME']] = [
                $this->stripPrefix((string) $row['TABLE_NAME']),
                (string) $row['COLUMN_NAME'],
                $this->stripPrefix((string) $row['REFERENCED_TABLE_NAME']),
                (string) $row['REFERENCED_COLUMN_NAME'],
                (string) $row['UPDATE_RULE'],
                (string) $row['DELETE_RULE'],
            ];
        }

        ksort($fks);

        return $fks;
    }

    /**
     * @return list<string> nama FK yang memakai kolom ini
     */
    private function foreignKeysOn(string $table, string $column): array
    {
        $rows = $this->db->query(
            'SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL',
            [$this->db->getDatabase(), $this->db->prefixTable($table), $column],
        )->getResultArray();

        return array_map(static fn (array $r): string => (string) $r['CONSTRAINT_NAME'], $rows);
    }

    private function stripPrefix(string $table): string
    {
        $prefix = $this->db->getPrefix();

        return $prefix !== '' && str_starts_with($table, $prefix) ? substr($table, strlen($prefix)) : $table;
    }
}
