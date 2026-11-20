<?php

declare(strict_types=1);

namespace Tests\Paritas\Simapi;

use App\Libraries\Integrasi\Simapi\PenyajiPegawaiLama;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Integrasi\SimapiUjiTrait;

/**
 * CR-170 (I-2) — test karakterisasi penanda `simapi_update_hist` sebagai syarat purge X-12:
 * {@see PenyajiPegawaiLama::pegawaiUpdate()} memakai anti-join (LEFT JOIN `simapi_update_hist` … IS NULL) per klien, jadi
 * setiap penanda yang terhapus membuat barisnya terkirim ulang ke klien itu saja. Data & NIP sintetis.
 *
 * @internal
 */
final class PenandaPegawaiUpdateTest extends DatabaseTestCase
{
    use FeatureTestTrait;
    use SimapiUjiTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pasangSimapiUji();
    }

    protected function tearDown(): void
    {
        $this->lepasSimapiUji();
        parent::tearDown();
    }

    private function su(): int
    {
        $utc = '2026-11-05 17:30:00';
        $this->db->table('simapi_update')->insert(['nip' => '199001012015011001', 'last_action' => 'UPDATE', 'last_update' => $utc, 'nama_field' => 'a', 'nilai_lama' => '1', 'nilai_baru' => '2', 'created_at' => $utc]);

        return (int) $this->db->insertID();
    }

    /**
     * @return list<string>
     */
    private function tarik(int $idKlien): array
    {
        return array_map(static fn (array $r): string => (string) $r['id'], (new PenyajiPegawaiLama($this->db))->pegawaiUpdate($idKlien));
    }

    public function testPenandaTerhapusMembuatBarisTerkirimUlangHanyaKeKlienItu(): void
    {
        $a   = $this->klienUji('cr170a', 1, ['simapi'])['id'];
        $b   = $this->klienUji('cr170b', 1, ['simapi'])['id'];
        $ids = array_map('strval', [$this->su(), $this->su(), $this->su()]);

        $this->assertSame($ids, $this->tarik($a));
        $this->assertSame($ids, $this->tarik($b));
        $this->assertSame([], $this->tarik($a));

        // Hapus penanda baris TENGAH (bukan terbesar) milik klien A: berbeda dengan change_history, tetap terkirim ulang.
        $this->db->table('simapi_update_hist')->where(['id_klien' => $a, 'id_simapi_update' => (int) $ids[1]])->delete();

        $this->assertSame([$ids[1]], $this->tarik($a), 'penanda terhapus → baris itu terkirim ulang ke klien A');
        $this->assertSame([], $this->tarik($b), 'klien B tidak terpengaruh');
        $this->assertSame([], $this->tarik($a), 'sesudah ditandai ulang tidak berulang lagi');

        $this->db->table('simapi_update_hist')->where('id_klien', $a)->delete();
        $this->assertSame($ids, $this->tarik($a), 'semua penanda klien A terhapus → semua terkirim ulang');
        $this->assertSame([], $this->tarik($b));
        $this->assertSame(3, $this->db->table('simapi_update_hist')->where('id_klien', $b)->countAllResults());
    }
}
