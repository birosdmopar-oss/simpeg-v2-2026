<?php

declare(strict_types=1);

namespace Tests\Paritas\Simapi;

use App\Libraries\Integrasi\Simapi\PenyajiChangeHistory;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Integrasi\SimapiUjiTrait;

/**
 * CR-170 (I-1) — test karakterisasi penanda `simapi_auth_ch` sebagai syarat purge X-12: {@see PenyajiChangeHistory} memakai
 * MAX(`id_simapi_ch`) penanda per klien sebagai high-water mark. Penanda di bawah MAX tidak berpengaruh; penanda MAX yang
 * terhapus membuat baris lama terkirim ulang. Dua klien agar "per klien" teruji. Data & NIP sintetis.
 *
 * @internal
 */
final class PenandaChangeHistoryTest extends DatabaseTestCase
{
    use FeatureTestTrait;
    use SimapiUjiTrait;

    private int $jd = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pasangSimapiUji();
        $this->db->table('simapi_jd')->insert(['jenis_data' => 'pegawai']);
        $this->jd = (int) $this->db->insertID();
    }

    protected function tearDown(): void
    {
        $this->lepasSimapiUji();
        parent::tearDown();
    }

    private function ch(): int
    {
        $this->db->table('simapi_ch')->insert([
            'id_simapi_jd' => $this->jd, 'id_rwy' => '7', 'nip' => '199001012015011001', 'action' => 1,
            'action_at'    => '2026-11-05 01:30:00', 'field_name' => 'a', 'field_value' => '1',
        ]);

        return (int) $this->db->insertID();
    }

    private function tarik(int $idKlien): int
    {
        return count((new PenyajiChangeHistory($this->db))->ambilDanTandai($idKlien));
    }

    private function jumlahPenanda(int $idKlien): int
    {
        return $this->db->table('simapi_auth_ch')->where('id_klien', $idKlien)->countAllResults();
    }

    /**
     * @return array{0: int, 1: int, 2: list<int>}
     */
    private function duaKlienSudahMenarik(): array
    {
        $a   = $this->klienUji('cr170a', 2, ['simapi'])['id'];
        $b   = $this->klienUji('cr170b', 2, ['simapi'])['id'];
        $ids = [$this->ch(), $this->ch(), $this->ch()];

        $this->assertSame(3, $this->tarik($a));
        $this->assertSame(3, $this->tarik($b));
        $this->assertSame([3, 3], [$this->jumlahPenanda($a), $this->jumlahPenanda($b)]);

        return [$a, $b, $ids];
    }

    public function testHapusPenandaBukanMaxTidakMengubahPenarikanBerikutnya(): void
    {
        [$a, $b, $ids] = $this->duaKlienSudahMenarik();

        // Hapus penanda selain MAX milik klien A (calon target purge).
        $this->db->table('simapi_auth_ch')->where('id_klien', $a)->whereIn('id_simapi_ch', [$ids[0], $ids[1]])->delete();
        $this->assertSame(1, $this->jumlahPenanda($a));

        $this->assertSame(0, $this->tarik($a), 'penanda bukan MAX terhapus: tidak ada yang terkirim ulang ke klien A');
        $this->assertSame(0, $this->tarik($b), 'klien B tidak terpengaruh');

        $baru = $this->ch();
        $this->assertSame(1, $this->tarik($a), 'baris baru tetap terkirim ke klien A');
        $this->assertSame(1, $this->tarik($b));
        $this->assertSame(1, $this->db->table('simapi_auth_ch')->where(['id_klien' => $a, 'id_simapi_ch' => $baru])->countAllResults());
    }

    public function testHapusPenandaMaxMembuatBarisLamaTerkirimUlang(): void
    {
        [$a, $b, $ids] = $this->duaKlienSudahMenarik();

        $this->db->table('simapi_auth_ch')->where(['id_klien' => $a, 'id_simapi_ch' => $ids[2]])->delete();

        $this->assertSame(1, $this->tarik($a), 'penanda MAX terhapus: baris di atas MAX baru (baris ketiga) terkirim ulang ke klien A');
        $this->assertSame(0, $this->tarik($b), 'klien B tidak terpengaruh');
        $this->assertSame(3, $this->jumlahPenanda($a), 'penanda ditulis lagi');
        $this->assertSame(0, $this->tarik($a), 'sesudah ditandai ulang tidak berulang lagi');

        // Semua penanda klien A terhapus → seluruh riwayat terkirim ulang.
        $this->db->table('simapi_auth_ch')->where('id_klien', $a)->delete();
        $this->assertSame(3, $this->tarik($a), 'tanpa penanda sama sekali: semua baris lama terkirim ulang');
        $this->assertSame(0, $this->tarik($b));
    }
}
