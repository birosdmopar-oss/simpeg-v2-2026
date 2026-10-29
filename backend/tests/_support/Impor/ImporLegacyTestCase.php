<?php

declare(strict_types=1);

namespace Tests\Support\Impor;

use App\Libraries\Impor\DaftarTabel;
use App\Libraries\Impor\Keputusan;
use App\Libraries\Impor\PengimporLegacy;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use Tests\Support\DatabaseTestCase;

/**
 * Kerangka test alat impor MAKE-056. Skema sumber `<db_test>_lgc` dibuat dari struktur tabel v2 semua tier
 * (`CREATE TABLE … LIKE`, tanpa FK), lalu diubah seperti legacy bila perlu oleh test. Semua data sintetis. Sesi
 * `sql_mode`/`time_zone` koneksi test dipulihkan sesudah tiap test (alat mengatur sesi R4).
 *
 * Subkelas wajib bertanda `#[Group('db-isolasi-penuh')]` (DDL skema kedua, commit per tier).
 */
abstract class ImporLegacyTestCase extends DatabaseTestCase
{
    protected string $lgc;
    protected BaseConnection $src;

    /**
     * @var array{mode: string, tz: string}
     */
    private array $sesiAwal;

    protected function setUp(): void
    {
        parent::setUp();

        $sesi           = $this->db->query('SELECT @@SESSION.sql_mode AS m, @@SESSION.time_zone AS z')->getRowArray();
        $this->sesiAwal = ['mode' => (string) $sesi['m'], 'tz' => (string) $sesi['z']];
        $this->lgc      = $this->db->getDatabase() . '_lgc';

        $this->db->query("DROP DATABASE IF EXISTS `{$this->lgc}`");
        $this->db->query("CREATE DATABASE `{$this->lgc}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

        for ($tier = 1; $tier <= DaftarTabel::TIER_MAKS; $tier++) {
            foreach (DaftarTabel::tier($tier) as $def) {
                $this->db->query("CREATE TABLE `{$this->lgc}`.`{$def->tabel}` LIKE `{$this->db->getDatabase()}`.`{$this->db->prefixTable($def->tabel)}`");
            }
        }

        $grup             = config(Database::class)->tests;
        $grup['database'] = $this->lgc;
        $grup['DBPrefix'] = '';
        $this->src        = Database::connect($grup, false);
        $this->src->query("SET SESSION sql_mode = ''");
        $this->src->query("SET SESSION time_zone = '+00:00'");
    }

    protected function tearDown(): void
    {
        $this->src->close();
        $this->db->query("DROP DATABASE IF EXISTS `{$this->lgc}`");
        $this->db->query('SET SESSION sql_mode = ?', [$this->sesiAwal['mode']]);
        $this->db->query('SET SESSION time_zone = ?', [$this->sesiAwal['tz']]);

        parent::tearDown();
    }

    /**
     * Master minimum yang wajib ada di sumber: salinan seed `jenis_cuti`, satu unit, dua satker.
     */
    protected function isiMasterDasar(): void
    {
        $this->src->query("INSERT INTO jenis_cuti SELECT * FROM `{$this->db->getDatabase()}`.`{$this->db->prefixTable('jenis_cuti')}`");
        $this->src->table('unit')->insert(['id_unit' => 5, 'unit' => "Biro\\'s   Umum ", 'is_upt' => 0, 'order' => 1, 'status' => 1, 'created_at' => '2026-08-16 00:30:00']);
        $this->src->table('satker')->insertBatch([
            ['id_satker' => 9, 'id_unit' => 5, 'satker' => 'Bagian A', 'is_upt' => 0, 'zonasi' => 1, 'order' => 1, 'status' => 1, 'created_at' => '2026-01-01 07:00:00'],
            ['id_satker' => 10, 'id_unit' => 0, 'satker' => 'Bagian B', 'is_upt' => 0, 'zonasi' => 1, 'order' => 2, 'status' => 2, 'created_at' => '2026-01-01 07:00:00'],
        ]);
    }

    /**
     * Buang CHECK tabel sumber (ikut tersalin `CREATE TABLE … LIKE`); legacy tidak punya CHECK v2.
     */
    protected function lepasCheck(string $tabel): void
    {
        $rows = $this->src->query(
            "SELECT CONSTRAINT_NAME AS n FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND CONSTRAINT_TYPE = 'CHECK'",
            [$this->lgc, $tabel],
        )->getResultArray();

        foreach ($rows as $r) {
            $this->src->query("ALTER TABLE `{$tabel}` DROP CONSTRAINT `{$r['n']}`");
        }
    }

    /**
     * @param list<int> $tier
     *
     * @return array<string, array<string, mixed>>
     */
    protected function jalankanImpor(array $tier, string $zona = 'WIB', ?Keputusan $k = null, bool $dryRun = false): array
    {
        return (new PengimporLegacy($this->src, $this->db, $zona, $k ?? Keputusan::kosong(), 2))->jalankan($tier, $dryRun)->semua();
    }
}
