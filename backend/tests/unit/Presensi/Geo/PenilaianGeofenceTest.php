<?php

declare(strict_types=1);

namespace Tests\Unit\Presensi\Geo;

use App\Libraries\Presensi\Geo\Haversine;
use App\Libraries\Presensi\Geo\Koordinat;
use App\Libraries\Presensi\Geo\LokasiGeofence;
use App\Libraries\Presensi\Geo\PenilaianGeofence;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * PRD 5A §7.5.4 (AC-D02.1, AC-D02.2, AC-D02.7 bagian murni): radius inklusif (`≤`), salah satu lokasi cukup, pengesah =
 * lokasi sah terdekat (seri → id terkecil), lokasi terdekat untuk pesan galat; parsing koordinat masukan dan baris
 * `lokasi_presensi` (Q5A-11).
 *
 * @internal
 */
final class PenilaianGeofenceTest extends CIUnitTestCase
{
    private const KANTOR = ['lat' => '-6.1754000', 'lng' => '106.8272000'];

    public function testTepatDiBatasRadiusSah(): void
    {
        $posisi = $this->titik(-6.1760, 106.8280);
        $jarak  = Haversine::jarakMeter(-6.1754, 106.8272, -6.1760, 106.8280);

        $hasil = PenilaianGeofence::nilai($posisi, [$this->lokasi(1, 'Kantor', $jarak)]);
        $this->assertTrue($hasil->dalamRadius(), 'jarak = radius → sah (≤ inklusif)');
        $this->assertSame(1, $hasil->pengesah?->id);
        $this->assertSame($jarak, $hasil->jarakPengesah);

        $luar = PenilaianGeofence::nilai($posisi, [$this->lokasi(1, 'Kantor', $jarak - 0.001)]);
        $this->assertFalse($luar->dalamRadius(), 'sedikit di luar radius → tidak sah');
        $this->assertNull($luar->pengesah);
        $this->assertSame(1, $luar->terdekat?->id);
        $this->assertSame((int) round($jarak), $luar->jarakTerdekatMeter());
    }

    public function testSalahSatuLokasiCukupDanPengesahTerdekat(): void
    {
        $posisi = $this->titik(-6.1754, 106.8272);
        $lokasi = [
            $this->lokasi(7, 'Jauh', 50, '-6.2000', '106.8000'),
            $this->lokasi(5, 'Sedang', 5000, '-6.1800', '106.8272'),
            $this->lokasi(9, 'Dekat', 100),
        ];

        $hasil = PenilaianGeofence::nilai($posisi, $lokasi);
        $this->assertTrue($hasil->dalamRadius());
        $this->assertSame(9, $hasil->pengesah?->id, 'dua lokasi sah → pengesah = jarak terkecil');
        $this->assertSame(9, $hasil->terdekat?->id);
    }

    public function testLokasiTerakhirTidakMenentukan(): void
    {
        // Legacy klien (P5-03) menilai lokasi terakhir; v2: sah bila salah satu dalam radius walau lokasi terakhir di luar.
        $hasil = PenilaianGeofence::nilai($this->titik(-6.1754, 106.8272), [
            $this->lokasi(1, 'Dalam', 30),
            $this->lokasi(2, 'Luar', 10, '-6.3000', '106.9000'),
        ]);
        $this->assertSame(1, $hasil->pengesah?->id);
    }

    public function testSeriJarakMemilihIdTerkecil(): void
    {
        $hasil = PenilaianGeofence::nilai($this->titik(-6.1754, 106.8272), [
            $this->lokasi(12, 'B', 100),
            $this->lokasi(3, 'A', 100),
        ]);
        $this->assertSame(3, $hasil->pengesah?->id);
        $this->assertSame(3, $hasil->terdekat?->id);
    }

    public function testDiLuarSemuaRadiusMemberiLokasiTerdekat(): void
    {
        $hasil = PenilaianGeofence::nilai($this->titik(-6.1754, 106.8272), [
            $this->lokasi(4, 'Gedung Jauh', 100, '-6.2000', '106.8272'),
            $this->lokasi(2, 'Gedung Dekat', 100, '-6.1800', '106.8272'),
        ]);
        $this->assertFalse($hasil->dalamRadius());
        $this->assertSame('Gedung Dekat', $hasil->terdekat?->nama);
        $this->assertEqualsWithDelta(511, $hasil->jarakTerdekatMeter(), 1);
    }

    public function testDaftarKosong(): void
    {
        $hasil = PenilaianGeofence::nilai($this->titik(0, 0), []);
        $this->assertFalse($hasil->dalamRadius());
        $this->assertNull($hasil->terdekat);
        $this->assertNull($hasil->jarakTerdekatMeter());
    }

    public function testAkurasiTidakIkutMenilai(): void
    {
        // Akurasi bukan bagian masukan penilaian: posisi sama → putusan sama (F5-10). Akurasi hanya divalidasi & dicatat.
        $this->assertSame(250.5, Koordinat::akurasiDariInput(250.5));
        $this->assertSame(12.0, Koordinat::akurasiDariInput('12'));
        $this->assertSame(0.0, Koordinat::akurasiDariInput(0));
        $this->assertNull(Koordinat::akurasiDariInput(-1));
        $this->assertNull(Koordinat::akurasiDariInput(100000.01));
        $this->assertNull(Koordinat::akurasiDariInput('1e3'));
        $this->assertNull(Koordinat::akurasiDariInput(true));
    }

    /**
     * @return iterable<string, array{0: mixed, 1: mixed, 2: bool}>
     */
    public static function masukanKoordinat(): iterable
    {
        yield 'angka JSON' => [-6.1754, 106.8272, true];
        yield 'string desimal' => ['-6.1754', '106.8272', true];
        yield 'bilangan bulat' => [0, 0, true];
        yield 'batas inklusif' => [-90, 180, true];
        yield 'batas inklusif negatif' => [90.0, -180.0, true];
        yield 'lintang > 90' => [90.0001, 0, false];
        yield 'bujur < -180' => [0, -180.0001, false];
        yield 'string kosong' => ['', '106.8', false];
        yield 'eksponen' => ['-6e0', '106.8', false];
        yield 'koma desimal' => ['-6,17', '106.8', false];
        yield 'spasi' => [' -6.17', '106.8', false];
        yield 'boolean' => [true, 106.8, false];
        yield 'null' => [null, 106.8, false];
        yield 'NAN' => [NAN, 106.8, false];
        yield 'INF' => [-6.1, INF, false];
        yield 'array' => [[-6.1], 106.8, false];
    }

    #[DataProvider('masukanKoordinat')]
    public function testKoordinatDariInput(mixed $lat, mixed $lng, bool $sah): void
    {
        $this->assertSame($sah, Koordinat::dariInput($lat, $lng) !== null);
    }

    public function testKoordinatDibulatkanTujuhDesimal(): void
    {
        $k = Koordinat::dariInput(-6.175412345678, 106.82720004999);
        $this->assertNotNull($k);
        $this->assertSame('-6.1754123', $k->latBulat());
        $this->assertSame('106.8272000', $k->lngBulat());
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: bool}>
     */
    public static function barisLokasi(): iterable
    {
        $sah = ['id_lokasi_presensi' => '7', 'nama_lokasi' => 'Kantor', 'latitude' => '-6.1754', 'longitude' => '106.8272', 'radius' => '100'];

        yield 'string MySQLi sah' => [$sah, true];
        yield 'spasi tepi VARCHAR' => [['latitude' => ' -6.1754 ', 'longitude' => '106.8272 '] + $sah, true];
        yield 'radius 0' => [['radius' => 0] + $sah, true];
        yield 'radius < 10 m impor dipakai apa adanya' => [['radius' => '5'] + $sah, true];
        yield 'latitude teks' => [['latitude' => 'Jakarta'] + $sah, false];
        yield 'longitude kosong' => [['longitude' => ''] + $sah, false];
        yield 'lintang di luar rentang' => [['latitude' => '-96.1'] + $sah, false];
        yield 'radius negatif' => [['radius' => '-1'] + $sah, false];
        yield 'radius bukan angka' => [['radius' => 'seratus'] + $sah, false];
        yield 'id kosong' => [['id_lokasi_presensi' => null] + $sah, false];
    }

    /**
     * @param array<string, mixed> $baris
     */
    #[DataProvider('barisLokasi')]
    public function testLokasiDariBaris(array $baris, bool $sah): void
    {
        $lokasi = LokasiGeofence::dariBaris($baris);
        $this->assertSame($sah, $lokasi !== null);

        if ($lokasi !== null) {
            $this->assertSame(7, $lokasi->id);
            $this->assertSame('Kantor', $lokasi->nama);
        }
    }

    private function titik(float $lat, float $lng): Koordinat
    {
        $k = Koordinat::dariInput($lat, $lng);
        $this->assertNotNull($k);

        return $k;
    }

    private function lokasi(int $id, string $nama, float $radius, string $lat = self::KANTOR['lat'], string $lng = self::KANTOR['lng']): LokasiGeofence
    {
        $l = LokasiGeofence::dariBaris(['id_lokasi_presensi' => $id, 'nama_lokasi' => $nama, 'latitude' => $lat, 'longitude' => $lng, 'radius' => $radius]);
        $this->assertNotNull($l);

        return $l;
    }
}
