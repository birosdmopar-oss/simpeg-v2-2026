<?php

declare(strict_types=1);

namespace Tests\Unit\Presensi\Geo;

use App\Libraries\Presensi\Geo\Haversine;
use App\Libraries\Presensi\Geo\Koordinat;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * PRD 5A §7.5.4 — jarak haversine R = 6.371.000 m (titik referensi, simetri, titik sama, antipoda).
 *
 * @internal
 */
final class HaversineTest extends CIUnitTestCase
{
    /**
     * Nilai acuan dihitung dari geometri bola R = 6.371.000 m: 1° busur = 2πR/360 = 111.194,93 m.
     *
     * @return iterable<string, array{0: float, 1: float, 2: float, 3: float, 4: float}>
     */
    public static function titikAcuan(): iterable
    {
        $satuDerajat = 2 * M_PI * Haversine::JARI_JARI_BUMI_METER / 360;

        yield '1° bujur di khatulistiwa' => [0.0, 0.0, 0.0, 1.0, $satuDerajat];
        yield '1° lintang' => [-6.0, 106.0, -7.0, 106.0, $satuDerajat];
        yield 'kutub ke kutub (setengah keliling)' => [90.0, 0.0, -90.0, 0.0, M_PI * Haversine::JARI_JARI_BUMI_METER];
        yield 'antipoda di khatulistiwa' => [0.0, 0.0, 0.0, 180.0, M_PI * Haversine::JARI_JARI_BUMI_METER];
        yield '1° bujur di lintang 60° (≈ cos 60° × 1° busur; lingkaran besar sedikit lebih pendek)' => [60.0, 10.0, 60.0, 11.0, $satuDerajat * cos(deg2rad(60.0))];
    }

    #[DataProvider('titikAcuan')]
    public function testJarakTitikAcuan(float $lat1, float $lng1, float $lat2, float $lng2, float $harapan): void
    {
        $this->assertEqualsWithDelta($harapan, Haversine::jarakMeter($lat1, $lng1, $lat2, $lng2), 1.0);
    }

    public function testTitikSamaNolDanSimetris(): void
    {
        $this->assertSame(0.0, Haversine::jarakMeter(-6.1754, 106.8272, -6.1754, 106.8272));

        $ab = Haversine::jarakMeter(-6.1754, 106.8272, -6.1950, 106.8231);
        $ba = Haversine::jarakMeter(-6.1950, 106.8231, -6.1754, 106.8272);
        $this->assertEqualsWithDelta($ab, $ba, 1e-6);
        $this->assertGreaterThan(2000.0, $ab);
        $this->assertLessThan(2500.0, $ab);
    }

    public function testJarakPendekMeteran(): void
    {
        // 0,0001° lintang ≈ 11,12 m.
        $this->assertEqualsWithDelta(11.12, Haversine::jarakMeter(-6.17540, 106.8272, -6.17550, 106.8272), 0.01);
    }

    public function testAntaraKoordinat(): void
    {
        $a = Koordinat::dariInput(-6.1754, 106.8272);
        $b = Koordinat::dariInput('-6.1950', '106.8231');
        $this->assertNotNull($a);
        $this->assertNotNull($b);
        $this->assertSame(Haversine::jarakMeter(-6.1754, 106.8272, -6.1950, 106.8231), Haversine::antara($a, $b));
    }
}
