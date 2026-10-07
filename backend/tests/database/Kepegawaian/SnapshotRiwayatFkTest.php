<?php

declare(strict_types=1);

namespace Tests\Database\Kepegawaian;

use App\Database\Migrations\AddFkSnapshotKeRiwayat;
use CodeIgniter\Database\Migration;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\SkemaD1;
use Throwable;

/**
 * DBV-013 — migration 2026-09-30-131000_AddFkSnapshotKeRiwayat: 14 FK snapshot `pegawai_*` → tabel riwayat asalnya
 * (nama, kolom, induk = D1 [K]; aksi [V2] RESTRICT/RESTRICT), memakai index yang sudah ada di DDL snapshot (DBV-012),
 * pra-cek orphan fail-closed tanpa FK terpasang sebagian, dan up()/down() aman diulang. Daftar FK dicocokkan dengan
 * ekspektasi D1 (Tests\Support\Kepegawaian\SkemaD1::B01, jenis `RWY`).
 *
 * File test ini ikut PR DBV-013 (bergantung pada tabel riwayat kelompok 2-4).
 *
 * @internal
 */
final class SnapshotRiwayatFkTest extends DatabaseTestCase
{
    private const VERSION = '2026-09-30-131000';

    /**
     * nama FK => [tabel snapshot, kolom, tabel riwayat, kolom PK riwayat], urut nama.
     */
    private const FOREIGN_KEYS = [
        'fk_id_riwayat_ak_cak_to_rak'                    => ['pegawai_ak', 'id_riwayat_ak', 'riwayat_ak', 'id_riwayat_ak'],
        'fk_id_riwayat_ak_siasn_peg_ak_siasn_02'         => ['pegawai_ak_siasn', 'id_riwayat_ak_siasn', 'riwayat_ak_siasn', 'id_riwayat_ak_siasn'],
        'fk_id_riwayat_alamat_pegalamat_riwalamat'       => ['pegawai_alamat', 'id_riwayat_alamat', 'riwayat_alamat', 'id_riwayat_alamat'],
        'fk_id_riwayat_diklat_pegdiklat_to_rwydiklat'    => ['pegawai_diklat', 'id_riwayat_diklat', 'riwayat_diklat', 'id_riwayat_diklat'],
        'fk_id_riwayat_hukdis_peg_hukdis_to_rwy_hukdis'  => ['pegawai_hukdis', 'id_riwayat_hukdis', 'riwayat_hukdis', 'id_riwayat_hukdis'],
        'fk_id_riwayat_keluarga_pegkeluarga_rwykeluarga' => ['pegawai_keluarga', 'id_riwayat_keluarga', 'riwayat_keluarga', 'id_riwayat_keluarga'],
        'fk_id_riwayat_kgb_pkgb_to_rkgb'                 => ['pegawai_kgb', 'id_riwayat_kgb', 'riwayat_kgb', 'id_riwayat_kgb'],
        'fk_id_riwayat_kp_pcpns_to_rwy_kp'               => ['pegawai_cpns', 'id_riwayat_kp', 'riwayat_kp', 'id_riwayat_kp'],
        'fk_id_riwayat_kp_peg_kp_to_rwy_kp'              => ['pegawai_kp', 'id_riwayat_kp', 'riwayat_kp', 'id_riwayat_kp'],
        'fk_id_riwayat_kp_ppns_to_rwy_kp'                => ['pegawai_pns', 'id_riwayat_kp', 'riwayat_kp', 'id_riwayat_kp'],
        'fk_id_riwayat_mutasi_jabatan_pmj_to_rmj'        => ['pegawai_mutasi_jabatan', 'id_riwayat_mutasi_jabatan', 'riwayat_mutasi_jabatan', 'id_riwayat_mutasi_jabatan'],
        'fk_id_riwayat_pendidikan_pegpend_to_rpend'      => ['pegawai_pendidikan', 'id_riwayat_pendidikan', 'riwayat_pendidikan', 'id_riwayat_pendidikan'],
        'fk_id_riwayat_tanda_jasa_ptj_to_rtj'            => ['pegawai_tanda_jasa', 'id_riwayat_tanda_jasa', 'riwayat_tanda_jasa', 'id_riwayat_tanda_jasa'],
        'pegawai_alamat_kantor_ibfk_5'                   => ['pegawai_alamat_kantor', 'id_riwayat_alamat', 'riwayat_alamat', 'id_riwayat_alamat'],
    ];

    private const NIP = '198501012010011001';

    /**
     * Migration yang dilepas test pra-cek; dipasang ulang di tearDown bila test gagal di tengah.
     */
    private ?Migration $detached = null;

    protected function tearDown(): void
    {
        if ($this->detached !== null) {
            $this->db->table('pegawai_kgb')->where('nip', self::NIP)->delete();
            $this->detached->up();
            $this->detached = null;
        }

        parent::tearDown();
    }

    public function testForeignKeysMatchProposal(): void
    {
        $this->assertSame(self::FOREIGN_KEYS, $this->riwayatForeignKeys(true));

        // Daftar = FK snapshot → riwayat di D1 dan = konstanta migration.
        $d1 = [];

        foreach (SkemaD1::B01 as $spec) {
            foreach ($spec['later'] as $name => [$jenis, $table, $column, $parent, $parentColumn]) {
                if ($jenis === 'RWY') {
                    $d1[$name] = [$table, $column, $parent, $parentColumn];
                }
            }
        }

        ksort($d1, SORT_STRING | SORT_FLAG_CASE);
        $this->assertSame(self::FOREIGN_KEYS, $d1, 'FK snapshot → riwayat = D1');
        $migration = AddFkSnapshotKeRiwayat::FOREIGN_KEYS;
        ksort($migration, SORT_STRING | SORT_FLAG_CASE);
        $this->assertSame(self::FOREIGN_KEYS, $migration, 'FK snapshot → riwayat = konstanta migration 131000');

        // Tipe kolom snapshot = PK riwayat (INT signed).
        foreach (self::FOREIGN_KEYS as $name => [$table, $column, $parent, $parentColumn]) {
            $this->assertSame(
                $this->columnType($parent, $parentColumn),
                $this->columnType($table, $column),
                "{$name}: tipe {$table}.{$column} = {$parent}.{$parentColumn}",
            );
        }
    }

    public function testSnapshotRejectsUnknownRiwayat(): void
    {
        $this->db->table('pegawai')->insert(['nip' => self::NIP, 'nama' => 'Ahmad Wijaya', 'tgl_lahir' => '1985-01-01']);

        $this->assertDbError([1452], fn () => $this->db->table('pegawai_kgb')->insert(['nip' => self::NIP, 'tmtsk' => '2024-04-01', 'tgl_sk' => '2024-03-01', 'id_riwayat_kgb' => 424242]));
        $this->assertDbError([1452], fn () => $this->db->table('pegawai_alamat_kantor')->insert(['nip' => self::NIP, 'id_riwayat_alamat' => 424242]));
        $this->db->table('pegawai_kgb')->insert(['nip' => self::NIP, 'tmtsk' => '2024-04-01', 'tgl_sk' => '2024-03-01', 'id_riwayat_kgb' => null]);
        $this->seeInDatabase('pegawai_kgb', ['nip' => self::NIP, 'id_riwayat_kgb' => null]);
    }

    /**
     * up() menolak jalan bila snapshot merujuk riwayat yang tidak ada (jumlah per FK), tanpa FK terpasang sebagian;
     * setelah dibereskan, ke-14 FK terpasang; up()/down() aman diulang dan KEY milik migration snapshot tetap.
     */
    #[Group('db-isolasi-penuh')]
    public function testRefusesOrphansBeforeAnyAlter(): void
    {
        $migration = $this->migrationInstance();
        $migration->down();
        $this->detached = $migration;
        $this->assertSame([], $this->riwayatForeignKeys(false), 'prasyarat: FK snapshot → riwayat terlepas');

        $this->db->table('pegawai')->insert(['nip' => self::NIP, 'nama' => 'Ahmad Wijaya', 'tgl_lahir' => '1985-01-01']);
        $this->db->table('pegawai_kgb')->insert(['nip' => self::NIP, 'tmtsk' => '2024-04-01', 'tgl_sk' => '2024-03-01', 'id_riwayat_kgb' => 424242]);

        $error = null;

        try {
            $migration->up();
        } catch (Throwable $e) {
            $error = $e;
        }

        $this->assertInstanceOf(RuntimeException::class, $error, 'up() harus fail-closed');
        $this->assertStringContainsString('fk_id_riwayat_kgb_pkgb_to_rkgb', $error->getMessage());
        $this->assertStringContainsString('1 baris', $error->getMessage());
        $this->assertSame([], $this->riwayatForeignKeys(false), 'tidak ada FK yang terpasang sebagian');

        $this->db->table('pegawai_kgb')->update(['id_riwayat_kgb' => null], ['nip' => self::NIP]);
        $migration->up();
        $migration->up();
        $this->detached = null;
        $this->assertSame(array_keys(self::FOREIGN_KEYS), array_keys($this->riwayatForeignKeys(false)));

        $migration->down();
        $migration->down();
        $this->assertSame([], $this->riwayatForeignKeys(false));

        foreach (self::FOREIGN_KEYS as $name => [$table, $column]) {
            $this->assertTrue($this->indexExists($table, $column), "index D1 untuk {$table}.{$column} ({$name}) tetap setelah down()");
        }

        $migration->up();
    }

    /**
     * FK dari tabel snapshot ke tabel riwayat_*, urut nama.
     *
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private function riwayatForeignKeys(bool $assertRestrict): array
    {
        $tables = array_map(fn (string $t): string => $this->db->prefixTable($t), array_unique(array_column(self::FOREIGN_KEYS, 0)));

        $rows = $this->db->query(
            'SELECT k.CONSTRAINT_NAME, k.TABLE_NAME, k.COLUMN_NAME, k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME, r.UPDATE_RULE, r.DELETE_RULE
             FROM information_schema.KEY_COLUMN_USAGE k
             JOIN information_schema.REFERENTIAL_CONSTRAINTS r
               ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME AND r.TABLE_NAME = k.TABLE_NAME
             WHERE k.TABLE_SCHEMA = ? AND k.TABLE_NAME IN ? AND k.REFERENCED_TABLE_NAME LIKE ?
             ORDER BY k.CONSTRAINT_NAME',
            [$this->db->getDatabase(), array_values($tables), str_replace('_', '\_', $this->db->prefixTable('riwayat_')) . '%'],
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

        ksort($fks);

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

    private function migrationInstance(): Migration
    {
        $files = glob(APPPATH . 'Database/Migrations/' . self::VERSION . '_*.php') ?: [];

        if (count($files) !== 1) {
            throw new RuntimeException('File migration ' . self::VERSION . ' tidak ditemukan (atau lebih dari satu).');
        }

        require_once $files[0];
        $class = 'App\\Database\\Migrations\\' . substr(basename($files[0], '.php'), strlen(self::VERSION) + 1);

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
