<?php

declare(strict_types=1);

namespace Tests\Unit\Dashboard\Beranda;

use App\Libraries\Dashboard\Beranda\JalurLegacyBuntu;
use App\Libraries\Dashboard\Beranda\KelengkapanData;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * MAKE-087 — inti kartu "Data Pangkat" (`kd_rpangkat_2` legacy, Q-D15 A): rentang golongan Pengangkatan CPNS/PNS → riwayat
 * terakhir, penyesuaian ijazah menghapus golongan yang dilompati, baris "CPNS <gol>" dihitung sekali, lengkap = punya lampiran;
 * jalur legacy loop tak berujung / TypeError → {@see JalurLegacyBuntu}. Port ini juga dibandingkan dengan salinan legacy pada
 * 20.000 kasus acak saat pengembangan (0 beda); kasus di bawah = jalur utamanya. Tipe hasil ikut legacy: pembagian
 * PHP yang habis dibagi = int, selain itu float dibulatkan.
 *
 * @internal
 */
final class KelengkapanPangkatTest extends CIUnitTestCase
{
    public function testRentangCpnsPnsDanLampiran(): void
    {
        $rows = [
            self::kp(1, '1', 'III', 'a', 'CPNS III/a', '2010-03-01'),
            self::kp(2, '2', 'III', 'a', 'III/a', '2011-03-01'),
            self::kp(3, '3', 'III', 'b', 'III/b', '2015-04-01'),
        ];

        // total = rentang III/a..III/b (2) + CPNS = 3
        $this->assertSame(100, KelengkapanData::persenPangkat($rows, ['1' => true, '2' => true, '3' => true]));
        $this->assertSame(67.0, KelengkapanData::persenPangkat($rows, ['1' => true, '2' => true]), 'III/b tanpa lampiran');
        $this->assertSame(33.0, KelengkapanData::persenPangkat($rows, ['1' => true]));
        $this->assertSame(0, KelengkapanData::persenPangkat($rows, []));
    }

    public function testTanpaPengangkatanMulaiIIIaTanpaCekCpns(): void
    {
        $rows = [self::kp(1, '3', 'III', 'b', 'III/b', '2015-04-01')];

        // rentang III/a..III/b; III/a tidak ada → 1 dari 2
        $this->assertSame(50.0, KelengkapanData::persenPangkat($rows, ['1' => true]));
    }

    public function testBarisTerakhirCpnsHanyaCekCpns(): void
    {
        $rows = [self::kp(1, '1', 'II', 'a', 'CPNS II/a', '2024-03-01')];

        $this->assertSame(100, KelengkapanData::persenPangkat($rows, ['1' => true]));
        $this->assertSame(0, KelengkapanData::persenPangkat($rows, []));
    }

    public function testPenyesuaianIjazahMenghapusGolonganYangDilompati(): void
    {
        $rows = [
            self::kp(1, '1', 'II', 'a', 'CPNS II/a', '2000-01-01'),
            self::kp(2, '2', 'II', 'a', 'II/a', '2001-01-01'),
            self::kp(3, '5', 'III', 'a', 'III/a', '2003-01-01'), // II/b..II/d dilompati
        ];

        // rentang {II/a, III/a} + CPNS = 3
        $this->assertSame(100, KelengkapanData::persenPangkat($rows, ['1' => true, '2' => true, '3' => true]));
        $this->assertSame(67.0, KelengkapanData::persenPangkat($rows, ['1' => true, '2' => true]));
    }

    public function testIjazahSebagaiBarisPertamaJalurTypeErrorLegacy(): void
    {
        $this->expectException(JalurLegacyBuntu::class);
        KelengkapanData::persenPangkat([self::kp(1, '5', 'III', 'b', 'III/b', '2015-04-01')], ['1' => true]);
    }

    public function testIjazahYangGolongannyaTidakDitemukanJalurLoopLegacy(): void
    {
        $this->expectException(JalurLegacyBuntu::class);
        KelengkapanData::persenPangkat([
            self::kp(1, '3', 'III', 'a', 'III/a', '2010-03-01'),
            self::kp(2, '5', 'III', 'b', 'lain', '2015-04-01'), // kunci gol_ruang ≠ "III/b" → while(key) tak pernah cocok
        ], []);
    }

    public function testTanpaRiwayatNol(): void
    {
        $this->assertSame(0, KelengkapanData::persenPangkat([], []));
    }

    /**
     * @return array<string, mixed>
     */
    private static function kp(int $id, string $jenis, string $gol, string $ruang, string $golRuang, string $tmt): array
    {
        return ['id_riwayat_kp' => $id, 'nip' => 'N', 'id_jenis_kp' => $jenis, 'gol' => $gol, 'ruang' => $ruang, 'gol_ruang' => $golRuang, 'tmtsk' => $tmt];
    }
}
