<?php

declare(strict_types=1);

namespace Tests\Database\Kepegawaian;

use App\Database\Migrations\AddFkG02Pegawai;
use App\Database\Migrations\AddFkG02Riwayat;
use CodeIgniter\Database\Migration;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use RuntimeException;
use Tests\Support\Kepegawaian\SkemaD1;
use Tests\Support\Kepegawaian\SkemaD1TestTrait;
use Throwable;

/**
 * DBV-019 — migration 2026-10-07-100000_AddFkG02Pegawai (15 FK snapshot) dan 2026-10-07-100100_AddFkG02Riwayat (40 FK
 * riwayat/penugasan) ke master G-02 (`group_jabatan`, `sub_group_jabatan`, `unit`, `satker`, `jabatan`) serta
 * `jabatan_koordinasi`/`rumpun_jabatan` (DBV-018). Mengunci: daftar FK = konstanta migration = ekspektasi D1
 * (Tests\Support\Kepegawaian\SkemaD1, jenis `G02` + `NOV2`); semua RESTRICT/RESTRICT; tipe kolom anak = PK induk di
 * `main` (INT, `rumpun_jabatan` TINYINT); pra-cek nilai yatim fail-closed tanpa FK terpasang sebagian; up()/down() aman
 * diulang dan index milik migration Create tetap.
 *
 * Dokumen: backend/docs/db-review/DBV-019-fk-g02-pegawai-schema.md.
 *
 * @internal
 */
final class FkG02Test extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use SkemaD1TestTrait;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = null;

    private const VERSION_PEGAWAI = '2026-10-07-100000';

    private const VERSION_RIWAYAT = '2026-10-07-100100';

    private const PARENTS = ['group_jabatan', 'sub_group_jabatan', 'unit', 'satker', 'jabatan', 'jabatan_koordinasi', 'rumpun_jabatan'];

    private const NIP = '198501012010011001';

    private const ID_ASING = 424242;

    /**
     * Migration yang dilepas test pra-cek (urut lepas); dipasang ulang di tearDown bila test gagal di tengah.
     *
     * @var list<Migration>
     */
    private array $detached = [];

    protected function tearDown(): void
    {
        if ($this->detached !== []) {
            $this->db->table('konv_ak')->where('nip', self::NIP)->delete();
            $this->db->table('pegawai_mutasi_jabatan')->where('nip', self::NIP)->delete();

            foreach (array_reverse($this->detached) as $migration) {
                $migration->up();
            }

            $this->detached = [];
        }

        parent::tearDown();
    }

    public function testForeignKeysMatchD1(): void
    {
        $expected = $this->expected();
        $this->assertCount(55, $expected, '15 FK snapshot + 40 FK riwayat');
        $this->assertSame($expected, $this->g02ForeignKeys(true), 'FK terpasang = konstanta migration');

        $d1 = [];

        foreach (['G02', 'NOV2'] as $jenis) {
            foreach ($this->fkDitunda(SkemaD1::B01 + SkemaD1::B02, $jenis) as $name => [, $table, $column, $parent, $parentColumn]) {
                $d1[$name] = [$table, $column, $parent, $parentColumn];
            }
        }

        ksort($d1, SORT_STRING);
        $this->assertSame($expected, $d1, 'FK = D1 (jenis G02 + NOV2)');

        // Tipe kolom anak = PK induk di main (C-3 DBV: master v2 INT; rumpun_jabatan TINYINT [K]).
        foreach ($expected as $name => [$table, $column, $parent, $parentColumn]) {
            $this->assertSame(
                $this->columnType($parent, $parentColumn),
                $this->columnType($table, $column),
                "{$name}: tipe {$table}.{$column} = {$parent}.{$parentColumn}",
            );
        }

        $this->assertSame('tinyint', $this->columnType('rumpun_jabatan', 'id_rumpun_jabatan'));
        $this->assertSame('int', $this->columnType('jabatan', 'id_jabatan'));
    }

    public function testRestrictIsEnforced(): void
    {
        $this->db->table('pegawai')->insert(['nip' => self::NIP, 'nama' => 'Ahmad Wijaya', 'tgl_lahir' => '1985-01-01']);
        $row = $this->barisMinimal(SkemaD1::B01['pegawai_mutasi_jabatan'], ['nip' => self::NIP]);

        // Nilai tanpa induk (termasuk 0 legacy) ditolak; NULL diterima.
        $this->assertDbError([1452], fn () => $this->db->table('pegawai_mutasi_jabatan')->insert([...$row, 'id_jabatan' => self::ID_ASING]));
        $this->assertDbError([1452], fn () => $this->db->table('pegawai_mutasi_jabatan')->insert([...$row, 'id_rumpun_jabatan' => 0]));
        $this->assertDbError([1452], fn () => $this->db->table('konv_ak')->insert($this->barisMinimal(SkemaD1::B02['konv_ak'], ['nip' => self::NIP, 'id_unit' => self::ID_ASING])));
        $this->db->table('pegawai_mutasi_jabatan')->insert([...$row, 'id_jabatan' => null]);

        // Induk yang dirujuk tidak bisa dihapus keras (master memakai soft delete status 10).
        $this->db->table('rumpun_jabatan')->insert(['rumpun_jabatan' => 'Rumpun Uji DBV-019']);
        $idRumpun = $this->db->insertID();
        $this->db->table('pegawai_mutasi_jabatan')->update(['id_rumpun_jabatan' => $idRumpun], ['nip' => self::NIP]);
        $this->assertDbError([1451], fn () => $this->db->table('rumpun_jabatan')->where('id_rumpun_jabatan', $idRumpun)->delete());
    }

    /**
     * up() kedua migration menolak jalan bila ada nilai yatim (jumlah per FK), tanpa FK terpasang sebagian; setelah
     * dibereskan, ke-55 FK terpasang; up()/down() aman diulang dan index milik migration Create tetap.
     */
    public function testRefusesOrphansBeforeAnyAlter(): void
    {
        $riwayat = $this->migrationInstance(self::VERSION_RIWAYAT);
        $pegawai = $this->migrationInstance(self::VERSION_PEGAWAI);
        $riwayat->down();
        $this->detached[] = $riwayat;
        $pegawai->down();
        $this->detached[] = $pegawai;
        $this->assertSame([], $this->g02ForeignKeys(false), 'prasyarat: FK G-02 terlepas');

        $this->db->table('pegawai')->insert(['nip' => self::NIP, 'nama' => 'Ahmad Wijaya', 'tgl_lahir' => '1985-01-01']);
        $this->db->table('pegawai_mutasi_jabatan')->insert($this->barisMinimal(SkemaD1::B01['pegawai_mutasi_jabatan'], [
            'nip' => self::NIP, 'id_jabatan' => self::ID_ASING, 'id_atasan_es_1' => 0,
        ]));
        $this->db->table('konv_ak')->insert($this->barisMinimal(SkemaD1::B02['konv_ak'], ['nip' => self::NIP, 'id_unit' => 0]));

        $this->assertFailsClosed($pegawai, ['fk_id_jabatan_pmj_to_jabatan', 'fk_id_atasan_es_1_pmj_to_jabatan'], 'fk_id_unit_pmj_to_unit');
        $this->assertFailsClosed($riwayat, ['fk_id_unit_konvak_to_unit'], 'fk_id_jabatan_konvak_to_jabatan');

        $this->db->table('pegawai_mutasi_jabatan')->update(['id_jabatan' => null, 'id_atasan_es_1' => null], ['nip' => self::NIP]);
        $this->db->table('konv_ak')->update(['id_unit' => null], ['nip' => self::NIP]);

        $pegawai->up();
        $pegawai->up();
        $riwayat->up();
        $riwayat->up();
        $this->detached = [];
        $this->assertSame(array_keys($this->expected()), array_keys($this->g02ForeignKeys(false)));

        $riwayat->down();
        $riwayat->down();
        $pegawai->down();
        $pegawai->down();
        $this->assertSame([], $this->g02ForeignKeys(false));

        foreach ($this->expected() as $name => [$table, $column]) {
            $this->assertTrue($this->indexExists($table, $column), "index D1 untuk {$table}.{$column} ({$name}) tetap setelah down()");
        }

        $pegawai->up();
        $riwayat->up();
    }

    /**
     * @param list<string> $orphanFks nama FK yang wajib disebut pesan (masing-masing 1 baris)
     */
    private function assertFailsClosed(Migration $migration, array $orphanFks, string $cleanFk): void
    {
        $error = null;

        try {
            $migration->up();
        } catch (Throwable $e) {
            $error = $e;
        }

        $this->assertInstanceOf(RuntimeException::class, $error, 'up() harus fail-closed');
        $this->assertStringContainsString('DBV-019', $error->getMessage());

        foreach ($orphanFks as $name) {
            $this->assertMatchesRegularExpression('/' . preg_quote($name, '/') . ' \([^)]*\): 1 baris/', $error->getMessage());
        }

        $this->assertStringNotContainsString($cleanFk, $error->getMessage(), 'FK tanpa yatim tidak disebut');
        $this->assertSame([], $this->g02ForeignKeys(false), 'tidak ada FK yang terpasang sebagian');
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}> nama => [tabel, kolom, induk, kolom induk], urut nama
     */
    private function expected(): array
    {
        $fks = AddFkG02Pegawai::FOREIGN_KEYS + AddFkG02Riwayat::FOREIGN_KEYS;
        ksort($fks, SORT_STRING);

        return $fks;
    }

    /**
     * FK ke tabel induk G-02/koordinasi/rumpun dari tabel B-01/B-02, urut nama.
     *
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private function g02ForeignKeys(bool $assertRestrict): array
    {
        $parents = array_map(fn (string $t): string => $this->db->prefixTable($t), self::PARENTS);
        $tables  = array_map(fn (string $t): string => $this->db->prefixTable($t), array_values(array_unique(array_column($this->expected(), 0))));

        $rows = $this->db->query(
            'SELECT k.CONSTRAINT_NAME, k.TABLE_NAME, k.COLUMN_NAME, k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME, r.UPDATE_RULE, r.DELETE_RULE
             FROM information_schema.KEY_COLUMN_USAGE k
             JOIN information_schema.REFERENTIAL_CONSTRAINTS r
               ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME AND r.TABLE_NAME = k.TABLE_NAME
             WHERE k.TABLE_SCHEMA = ? AND k.TABLE_NAME IN ? AND k.REFERENCED_TABLE_NAME IN ?
             ORDER BY k.CONSTRAINT_NAME',
            [$this->db->getDatabase(), $tables, $parents],
        )->getResultArray();

        $fks = [];

        foreach ($rows as $row) {
            $name = (string) $row['CONSTRAINT_NAME'];

            if ($assertRestrict) {
                $this->assertSame(['RESTRICT', 'RESTRICT'], [$row['UPDATE_RULE'], $row['DELETE_RULE']], "{$name} RESTRICT/RESTRICT");
            }

            $fks[$name] = [
                $this->stripPrefix((string) $row['TABLE_NAME']),
                (string) $row['COLUMN_NAME'],
                $this->stripPrefix((string) $row['REFERENCED_TABLE_NAME']),
                (string) $row['REFERENCED_COLUMN_NAME'],
            ];
        }

        ksort($fks, SORT_STRING);

        return $fks;
    }

    private function columnType(string $table, string $column): string
    {
        $type = (string) ($this->db->query(
            'SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$this->db->getDatabase(), $this->db->prefixTable($table), $column],
        )->getRowArray()['COLUMN_TYPE'] ?? '');

        return (string) preg_replace('/^(tinyint|smallint|mediumint|int|bigint)\((?!1\))\d+\)/', '$1', strtolower($type));
    }

    /**
     * Ada index (dari DDL D1) dengan kolom terdepan = $column; nama index D1 tidak selalu = nama FK.
     */
    private function indexExists(string $table, string $column): bool
    {
        return (int) $this->db->query(
            'SELECT COUNT(*) AS n FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ? AND SEQ_IN_INDEX = 1',
            [$this->db->getDatabase(), $this->db->prefixTable($table), $column],
        )->getRowArray()['n'] > 0;
    }

    private function migrationInstance(string $version): Migration
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

    private function assertDbError(array $codes, callable $write): void
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

    private function stripPrefix(string $table): string
    {
        $prefix = $this->db->getPrefix();

        return $prefix !== '' && str_starts_with($table, $prefix) ? substr($table, strlen($prefix)) : $table;
    }
}
