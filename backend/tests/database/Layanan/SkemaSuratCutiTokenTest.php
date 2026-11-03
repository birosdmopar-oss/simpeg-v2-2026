<?php

declare(strict_types=1);

namespace Tests\Database\Layanan;

use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\Support\Database\Seeds\MasterDataSeeder;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Layanan\CutiUjiTrait;
use Tests\Support\SkemaKepegawaianTestTrait;

/**
 * DBV-030 — test skema permanen `surat_cuti_token` (migration `2026-11-03-110000_CreateSuratCutiToken`, DBV-030 §7/§8):
 * struktur & collation, UNIQUE per cuti dan per hash (1062), CHECK status/nonaktif (3819 MySQL / 4025 MariaDB), FK
 * RESTRICT (1451/1452), siklus down()/up(), kueri audit berjalan dan B2 menangkap heks huruf besar (V-01).
 *
 * @internal
 */
#[Group('db-isolasi-penuh')]
final class SkemaSuratCutiTokenTest extends DatabaseTestCase
{
    use CutiUjiTrait;
    use SkemaKepegawaianTestTrait;

    private const VERSION = '2026-11-03-110000';

    private const TABEL = 'surat_cuti_token';

    private const CHECK = [3819, 4025];

    protected $seed = MasterDataSeeder::class;

    protected function tearDown(): void
    {
        if ($this->adaYangDilepas()) {
            $this->pasangUlang();
        }

        parent::tearDown();
    }

    public function testStrukturDanConstraint(): void
    {
        $info = $this->skemaTableInfo(self::TABEL);
        $this->assertSame(['InnoDB', 'utf8mb4_unicode_ci'], [$info['ENGINE'] ?? null, $info['TABLE_COLLATION'] ?? null]);

        foreach ($this->skemaStringCollations(self::TABEL) as $kolom => $collation) {
            $this->assertSame('utf8mb4_unicode_ci', $collation, $kolom);
        }

        $kolom = $this->skemaColumns(self::TABEL);
        $this->assertSame(['char(64)', 'varbinary(255)', 'tinyint', 'int'], [$kolom['token_hash'], $kolom['token_enkripsi'], $kolom['status'], $kolom['id_riwayat_cuti']]);
        $this->assertArrayNotHasKey('nip', $kolom, 'tanpa kolom NIP');

        $a  = $this->buatAlurDuaLangkah();
        $id = $this->buatBarisCuti($a['pemohon']['nip'], ['status' => 1]);
        $t  = fn (int $idCuti, string $token, array $lebih = []) => $this->db->table(self::TABEL)->insert(['id_riwayat_cuti' => $idCuti, 'token_hash' => hash('sha256', $token), 'token_enkripsi' => 'enk-' . $token] + $lebih);

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
        $this->lepasSejak(self::VERSION);
        $this->assertFalse($this->skemaTableExists(self::TABEL));

        $this->pasangUlang();
        $this->assertTrue($this->skemaTableExists(self::TABEL));
        $this->assertSame(['uk_sct_id_riwayat_cuti', 'uk_sct_token_hash'], array_values(array_intersect(['uk_sct_id_riwayat_cuti', 'uk_sct_token_hash'], $this->skemaIndexes(self::TABEL)[2])));
    }

    public function testKueriAuditBerjalanDanB2MenangkapHeksBesar(): void
    {
        $a  = $this->buatAlurDuaLangkah();
        $id = $this->buatBarisCuti($a['pemohon']['nip'], ['status' => 1]);
        $this->db->table(self::TABEL)->insert(['id_riwayat_cuti' => $id, 'token_hash' => strtoupper(hash('sha256', 'token-besar')), 'token_enkripsi' => 'enk']);

        $hasil = $this->jalankanAudit();

        $this->assertCount(5, $hasil);
        $this->assertSame(1, (int) $hasil[1][0]['hash_tidak_sah'], 'V-01: heks huruf BESAR tertangkap (BINARY)');

        $this->db->table(self::TABEL)->where('id_riwayat_cuti', $id)->update(['token_hash' => hash('sha256', 'token-besar')]);
        $this->assertSame(0, (int) $this->jalankanAudit()[1][0]['hash_tidak_sah']);
    }

    /**
     * @return list<list<array<string, mixed>>>
     */
    private function jalankanAudit(): array
    {
        $sql   = (string) preg_replace('/^\s*--.*$/m', '', (string) file_get_contents(ROOTPATH . 'docs/db-review/sql/DBV-030-audit-token-surat-cuti.sql'));
        $hasil = [];

        foreach (array_filter(array_map('trim', explode(';', $sql))) as $q) {
            foreach (['surat_cuti_token', 'riwayat_cuti'] as $tabel) {
                $q = (string) preg_replace('/(FROM|JOIN)\s+' . $tabel . '\b/', '$1 ' . $this->db->prefixTable($tabel), $q);
            }

            try {
                $hasil[] = $this->db->query($q)->getResultArray();
            } catch (\Throwable $e) {
                throw new RuntimeException($e->getMessage() . "\n" . $q);
            }
        }

        return $hasil;
    }
}
