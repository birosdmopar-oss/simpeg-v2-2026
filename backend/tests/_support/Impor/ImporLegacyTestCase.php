<?php

declare(strict_types=1);

namespace Tests\Support\Impor;

use App\Libraries\Impor\DaftarTabel;
use App\Libraries\Impor\Keputusan;
use App\Libraries\Impor\PengimporLegacy;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use Tests\Support\DatabaseTestCase;
use Throwable;

/**
 * Kerangka test alat impor MAKE-056. Skema sumber `<db_test>_lgc` dibuat dari struktur tabel v2 semua tier
 * (`CREATE TABLE … LIKE`, tanpa FK), lalu diubah seperti legacy bila perlu oleh test. Semua data sintetis. Sesi
 * `sql_mode`/`time_zone` koneksi test dipulihkan sesudah tiap test (alat mengatur sesi R4). Definisi `skemaSiasn` tidak
 * dibuat di skema itu; test SIASN membuat skema kedua `<db_test>_sia` lewat {@see siapkanSumberSiasn()}.
 *
 * Subkelas wajib bertanda `#[Group('db-isolasi-penuh')]` (DDL skema kedua, commit per tier).
 */
abstract class ImporLegacyTestCase extends DatabaseTestCase
{
    protected string $lgc;
    protected ?BaseConnection $src = null;
    protected ?BaseConnection $sia = null;

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

        try {
            $this->siapkanSumber();
        } catch (Throwable $e) {
            // setUp gagal → tearDown tidak dipanggil PHPUnit; skema sisa dibuang di sini (R-3 review PR #32).
            $this->buangSumber();

            throw $e;
        }
    }

    private function siapkanSumber(): void
    {
        $dibuat = [];

        for ($tier = 1; $tier <= DaftarTabel::TIER_MAKS; $tier++) {
            foreach (DaftarTabel::tier($tier) as $def) {
                if ($def->skemaSiasn || isset($dibuat[$def->tabel])) {
                    continue;
                }

                $this->db->query("CREATE TABLE `{$this->lgc}`.`{$def->tabel}` LIKE `{$this->db->getDatabase()}`.`{$this->db->prefixTable($def->tabel)}`");
                $dibuat[$def->tabel] = true;
            }
        }

        $grup             = config(Database::class)->tests;
        $grup['database'] = $this->lgc;
        $grup['DBPrefix'] = '';
        $this->src        = Database::connect($grup, false);
        $this->src->query("SET SESSION sql_mode = ''");
        $this->src->query("SET SESSION time_zone = '+00:00'");
    }

    /**
     * Dipanggil juga saat test gagal (PHPUnit selalu menjalankan tearDown sesudah setUp berhasil).
     */
    protected function tearDown(): void
    {
        try {
            $this->buangSumber();
            $this->db->query('SET SESSION sql_mode = ?', [$this->sesiAwal['mode']]);
            $this->db->query('SET SESSION time_zone = ?', [$this->sesiAwal['tz']]);
        } finally {
            parent::tearDown();
        }
    }

    private function buangSumber(): void
    {
        $this->src?->close();
        $this->src = null;
        $this->sia?->close();
        $this->sia = null;
        $this->db->query("DROP DATABASE IF EXISTS `{$this->lgc}`");
        $this->db->query("DROP DATABASE IF EXISTS `{$this->lgc}_sia`");
    }

    /**
     * Skema kedua tiruan `siasn_simpeg` (`<db_test>_sia`): `REF_UNOR` berkolom sama dengan v2 dan tujuh tabel referensi
     * berbentuk legacy (`ID`, `NAMA`, kolom pemetaan lokal). Nama huruf besar sengaja dipakai (K-9).
     *
     * @param array<string, string|null> $referensi nama tabel → kolom pemetaan lokal
     */
    protected function siapkanSumberSiasn(array $referensi): void
    {
        $sia = "{$this->lgc}_sia";
        $this->db->query("DROP DATABASE IF EXISTS `{$sia}`");
        $this->db->query("CREATE DATABASE `{$sia}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $this->db->query("CREATE TABLE `{$sia}`.`REF_UNOR` LIKE `{$this->db->getDatabase()}`.`{$this->db->prefixTable('siasn_ref_unor')}`");

        foreach ($referensi as $tabel => $lokal) {
            $this->db->query("CREATE TABLE `{$sia}`.`{$tabel}` (`ID` VARCHAR(100) NOT NULL, `NAMA` VARCHAR(255) NULL"
                . ($lokal === null ? '' : ", `{$lokal}` INT NULL") . ', PRIMARY KEY (`ID`)) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        }

        $grup             = config(Database::class)->tests;
        $grup['database'] = $sia;
        $grup['DBPrefix'] = '';
        $this->sia        = Database::connect($grup, false);
        $this->sia->query("SET SESSION sql_mode = ''");
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
        return (new PengimporLegacy($this->src, $this->db, $zona, $k ?? Keputusan::kosong(), 2, $this->sia))->jalankan($tier, $dryRun)->semua();
    }
}
