<?php

declare(strict_types=1);

namespace Tests\Database\Layanan;

use App\Database\Migrations\CreateSuratCutiToken;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\Support\Database\Seeds\MasterDataSeeder;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Layanan\CutiUjiTrait;
use Tests\Support\SkemaKepegawaianTestTrait;

require_once APPPATH . 'Database/MigrationsDitahan/CreateSuratCutiToken.php';

/**
 * DBV-030 — test skema draf `surat_cuti_token` (DBV-030 §7/§8): struktur & collation, UNIQUE per cuti dan per hash (1062),
 * CHECK status/nonaktif (3819 MySQL / 4025 MariaDB), FK RESTRICT (1451/1452), siklus down()/up(), kueri audit berjalan.
 * Masih memuat draf di `MigrationsDitahan/`; saat dipindah (setelah approve DBV) `require_once` dihapus.
 *
 * @internal
 */
#[Group('db-isolasi-penuh')]
final class SkemaSuratCutiTokenTest extends DatabaseTestCase
{
    use CutiUjiTrait;
    use SkemaKepegawaianTestTrait;

    private const CHECK = [3819, 4025];

    protected $seed = MasterDataSeeder::class;

    private CreateSuratCutiToken $m;

    private bool $naik = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->m = new CreateSuratCutiToken();
    }

    protected function tearDown(): void
    {
        if ($this->naik) {
            $this->m->down();
        }

        parent::tearDown();
    }

    public function testStrukturDanConstraint(): void
    {
        $this->naik();
        $info = $this->skemaTableInfo(CreateSuratCutiToken::TABEL);
        $this->assertSame(['InnoDB', 'utf8mb4_unicode_ci'], [$info['ENGINE'] ?? null, $info['TABLE_COLLATION'] ?? null]);

        foreach ($this->skemaStringCollations(CreateSuratCutiToken::TABEL) as $kolom => $collation) {
            $this->assertSame('utf8mb4_unicode_ci', $collation, $kolom);
        }

        $kolom = $this->skemaColumns(CreateSuratCutiToken::TABEL);
        $this->assertSame(['char(64)', 'varbinary(255)', 'tinyint', 'int'], [$kolom['token_hash'], $kolom['token_enkripsi'], $kolom['status'], $kolom['id_riwayat_cuti']]);
        $this->assertArrayNotHasKey('nip', $kolom, 'tanpa kolom NIP');

        $a  = $this->buatAlurDuaLangkah();
        $id = $this->buatBarisCuti($a['pemohon']['nip'], ['status' => 1]);
        $t  = fn (int $idCuti, string $token, array $lebih = []) => $this->db->table(CreateSuratCutiToken::TABEL)->insert(['id_riwayat_cuti' => $idCuti, 'token_hash' => hash('sha256', $token), 'token_enkripsi' => 'enk-' . $token] + $lebih);

        $t($id, 'token-1');
        $this->assertDbError([1062], fn () => $t($id, 'token-2'));
        $id2 = $this->buatBarisCuti($a['pemohon']['nip'], ['status' => 4]);
        $this->assertDbError([1062], fn () => $t($id2, 'token-1'));
        $this->assertDbError(self::CHECK, fn () => $t($id2, 'token-3', ['status' => 3]));
        $this->assertDbError(self::CHECK, fn () => $t($id2, 'token-4', ['status' => 2]));
        $this->assertDbError(self::CHECK, fn () => $t($id2, 'token-5', ['nonaktif_at' => '2026-11-03 00:00:00']));
        $t($id2, 'token-6', ['status' => 2, 'nonaktif_at' => '2026-11-03 00:00:00']);
        $this->assertDbError([1452], fn () => $t(999999, 'token-7'));
        $this->assertDbError([1451], fn () => $this->db->table('riwayat_cuti')->where('id_riwayat_cuti', $id)->delete());
    }

    public function testDownLaluUpKembali(): void
    {
        $this->naik();
        $this->m->down();
        $this->naik = false;
        $this->assertFalse($this->skemaTableExists(CreateSuratCutiToken::TABEL));

        $this->naik();
        $this->assertTrue($this->skemaTableExists(CreateSuratCutiToken::TABEL));
    }

    public function testKueriAuditBerjalan(): void
    {
        $this->naik();
        $sql = (string) preg_replace('/^\s*--.*$/m', '', (string) file_get_contents(ROOTPATH . 'docs/db-review/sql/DBV-030-audit-token-surat-cuti.sql'));
        $n   = 0;

        foreach (array_filter(array_map('trim', explode(';', $sql))) as $q) {
            foreach (['surat_cuti_token', 'riwayat_cuti'] as $tabel) {
                $q = (string) preg_replace('/(FROM|JOIN)\s+' . $tabel . '\b/', '$1 ' . $this->db->prefixTable($tabel), $q);
            }

            try {
                $this->db->query($q)->getResultArray();
            } catch (\Throwable $e) {
                throw new RuntimeException($e->getMessage() . "\n" . $q);
            }

            $n++;
        }

        $this->assertSame(5, $n);
    }

    private function naik(): void
    {
        $this->m->up();
        $this->naik = true;
    }
}
