<?php

declare(strict_types=1);

namespace Tests\Database\Integrasi;

use App\Database\Migrations\CreateKlienLayananSimapi;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\Support\DatabaseTestCase;
use Tests\Support\SkemaKepegawaianTestTrait;

require_once APPPATH . 'Database/MigrationsDitahan/CreateKlienLayananSimapi.php';

/**
 * DBV-026 — test skema draf klien layanan & SIMAPI (DBV-026 §7/§8): struktur & collation, UNIQUE username (1062), CHECK
 * jenis/status/action (3819 MySQL / 4025 MariaDB), SET hak, FK RESTRICT (1451/1452), siklus down()/up(), kueri audit
 * berjalan. Masih memuat draf di `MigrationsDitahan/`; saat dipindah (setelah approve DBV) `require_once` dihapus.
 *
 * @internal
 */
#[Group('db-isolasi-penuh')]
final class SkemaKlienSimapiTest extends DatabaseTestCase
{
    use SkemaKepegawaianTestTrait;

    private const CHECK = [3819, 4025];

    private CreateKlienLayananSimapi $m;

    private bool $naik = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->m = new CreateKlienLayananSimapi();
    }

    protected function tearDown(): void
    {
        if ($this->naik) {
            $this->m->down();
        }

        parent::tearDown();
    }

    public function testStrukturDanCollation(): void
    {
        $this->naik();

        foreach (CreateKlienLayananSimapi::TABEL as $tabel) {
            $info = $this->skemaTableInfo($tabel);
            $this->assertSame(['InnoDB', 'utf8mb4_unicode_ci'], [$info['ENGINE'] ?? null, $info['TABLE_COLLATION'] ?? null], $tabel);

            foreach ($this->skemaStringCollations($tabel) as $kolom => $collation) {
                $this->assertSame('utf8mb4_unicode_ci', $collation, "{$tabel}.{$kolom}");
            }
        }

        $k = $this->skemaColumns('klien_layanan');
        $this->assertSame(['varchar(255)', 'tinyint', 'tinyint'], [$k['rahasia_hash'], $k['jenis'], $k['status']]);
        $this->assertStringStartsWith('set(', $k['hak']);
        $this->assertArrayNotHasKey('password', $k, 'tanpa kolom rahasia mentah');

        $t = $this->skemaColumns('simapi_token');
        $this->assertSame('char(64)', $t['token_hash']);
        $this->assertArrayNotHasKey('token', $t, 'tanpa token mentah');

        $c = $this->skemaColumns('simapi_ch');
        $this->assertSame(['bigint unsigned', 'varchar(30) null', 'text null', 'text null'], [$c['id_simapi_ch'], $c['nip'], $c['field_name'], $c['field_value']]);
    }

    public function testConstraint(): void
    {
        $this->naik();
        $klien = fn (string $u, array $lebih = []) => $this->db->table('klien_layanan')->insert($lebih + ['nama' => 'Uji', 'username' => $u, 'rahasia_hash' => password_hash('x', PASSWORD_DEFAULT), 'jenis' => 2, 'hak' => 'simapi']);

        $klien('simanta');
        $id = (int) $this->db->insertID();
        $this->assertDbError([1062], fn () => $klien('simanta'));
        $this->assertDbError(self::CHECK, fn () => $klien('k-jenis', ['jenis' => 4]));
        $this->assertDbError(self::CHECK, fn () => $klien('k-status', ['status' => 3]));
        $this->assertDbError([1265], fn () => $klien('k-hak', ['hak' => 'simapi,admin']));
        $klien('mudig', ['jenis' => 3, 'hak' => 'stat,dm,esign', 'status' => 10]);

        $token = fn (int $idKlien, string $raw, array $lebih = []) => $this->db->table('simapi_token')->insert($lebih + ['token_hash' => hash('sha256', $raw), 'id_klien' => $idKlien, 'expired_at' => '2026-11-04 01:00:00']);
        $token($id, 'a');
        $token($id, 'b');
        $this->assertDbError([1062], fn () => $token($id, 'a'));
        $this->assertDbError(self::CHECK, fn () => $token($id, 'c', ['status' => 3]));
        $this->assertDbError([1452], fn () => $token(999999, 'd'));

        $this->db->table('simapi_jd')->insert(['jenis_data' => 'pegawai']);
        $jd = (int) $this->db->insertID();
        $this->assertDbError([1062], fn () => $this->db->table('simapi_jd')->insert(['jenis_data' => 'pegawai']));
        $this->db->table('simapi_auth_jd')->insert(['id_klien' => $id, 'id_simapi_jd' => $jd]);
        $this->assertDbError([1062], fn () => $this->db->table('simapi_auth_jd')->insert(['id_klien' => $id, 'id_simapi_jd' => $jd]));

        $ch = fn (array $lebih = []) => $this->db->table('simapi_ch')->insert($lebih + ['id_simapi_jd' => $jd, 'id_rwy' => '7', 'nip' => null, 'action' => 1, 'action_at' => '2026-11-04 00:00:00', 'field_name' => 'a|b', 'field_value' => '1|2']);
        $ch();
        $idCh = (int) $this->db->insertID();
        $this->assertDbError(self::CHECK, fn () => $ch(['action' => 3]));
        $this->assertDbError([1452], fn () => $ch(['id_simapi_jd' => 999999]));
        $ch(['nip' => '000000000000000001', 'action' => 2]);

        $this->db->table('simapi_auth_ch')->insert(['id_klien' => $id, 'id_simapi_ch' => $idCh]);
        $this->assertDbError([1062], fn () => $this->db->table('simapi_auth_ch')->insert(['id_klien' => $id, 'id_simapi_ch' => $idCh]));
        $this->assertDbError([1451], fn () => $this->db->table('simapi_ch')->where('id_simapi_ch', $idCh)->delete());
        $this->assertDbError([1451], fn () => $this->db->table('simapi_jd')->where('id_simapi_jd', $jd)->delete());
        $this->assertDbError([1451], fn () => $this->db->table('klien_layanan')->where('id_klien', $id)->delete());
    }

    public function testDownLaluUpKembali(): void
    {
        $this->naik();
        $this->m->down();
        $this->naik = false;

        foreach (CreateKlienLayananSimapi::TABEL as $tabel) {
            $this->assertFalse($this->skemaTableExists($tabel), $tabel);
        }

        $this->naik();

        foreach (CreateKlienLayananSimapi::TABEL as $tabel) {
            $this->assertTrue($this->skemaTableExists($tabel), $tabel);
        }
    }

    public function testKueriAuditBerjalan(): void
    {
        $this->naik();
        $sql = (string) preg_replace('/^\s*--.*$/m', '', (string) file_get_contents(ROOTPATH . 'docs/db-review/sql/DBV-026-audit-klien-simapi.sql'));
        $n   = 0;

        foreach (array_filter(array_map('trim', explode(';', $sql))) as $q) {
            foreach (CreateKlienLayananSimapi::TABEL as $tabel) {
                $q = (string) preg_replace('/(FROM|JOIN)\s+' . $tabel . '\b/', '$1 ' . $this->db->prefixTable($tabel), $q);
            }

            try {
                $this->db->query($q)->getResultArray();
            } catch (\Throwable $e) {
                throw new RuntimeException($e->getMessage() . "\n" . $q);
            }

            $n++;
        }

        $this->assertSame(8, $n);
    }

    private function naik(): void
    {
        $this->m->up();
        $this->naik = true;
    }
}
