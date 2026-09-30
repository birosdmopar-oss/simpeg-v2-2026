<?php

declare(strict_types=1);

namespace Tests\Unit\Kepegawaian\Kalkulasi;

use App\Libraries\Kepegawaian\Kalkulasi\TanggalBisnis;
use CodeIgniter\I18n\Time;
use CodeIgniter\Test\CIUnitTestCase;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * CR-025 (B-21) — tanggal bisnis: validasi input, aritmetika bulan/tahun dijepit akhir bulan (setara MySQL `DATE_ADD`),
 * selisih bulan penuh, format Indonesia, dan "hari ini" WIB (legacy `config/config.php:4`).
 *
 * @internal
 */
final class TanggalBisnisTest extends CIUnitTestCase
{
    protected function tearDown(): void
    {
        Time::setTestNow();

        parent::tearDown();
    }

    public function testValid(): void
    {
        foreach (['2024-02-29', '2026-04-01', '1900-01-01', '2100-12-31'] as $tanggal) {
            $this->assertTrue(TanggalBisnis::valid($tanggal), $tanggal);
        }

        $salah = [
            '2025-02-29', '2026-02-30', '2026-04-31', '2026-13-01', '2026-4-1', '01-04-2026', '', ' 2026-04-01',
            '2026-04-01 00:00:00', '1899-12-31', '2101-01-01', '0000-00-00',
        ];

        foreach ($salah as $tanggal) {
            $this->assertFalse(TanggalBisnis::valid($tanggal), var_export($tanggal, true));
        }
    }

    public function testPeriksaMemberiKodeDanLabelDiPesan(): void
    {
        $kosong = TanggalBisnis::periksa('', 'TMT KP');
        $this->assertNotNull($kosong);
        $this->assertFalse($kosong->valid);
        $this->assertSame('tanggal_wajib', $kosong->kode);
        $this->assertSame('TMT KP wajib diisi.', $kosong->pesan);

        $spasi = TanggalBisnis::periksa('   ', 'TMT KGB');
        $this->assertSame('tanggal_wajib', $spasi?->kode);

        $salah = TanggalBisnis::periksa('2026-02-30', 'TMT KGB');
        $this->assertNotNull($salah);
        $this->assertSame('tanggal_tidak_valid', $salah->kode);
        $this->assertSame('TMT KGB tidak valid. Gunakan tanggal kalender dengan format YYYY-MM-DD.', $salah->pesan);

        $this->assertNull(TanggalBisnis::periksa('2026-04-01', 'TMT KP'));
    }

    /**
     * @return iterable<string, array{string, int, string}>
     */
    public static function tambahBulanProvider(): iterable
    {
        yield 'akhir Januari → akhir Februari' => ['2026-01-31', 1, '2026-02-28'];
        yield 'akhir Januari kabisat' => ['2024-01-31', 1, '2024-02-29'];
        yield '31 Maret → 30 April' => ['2026-03-31', 1, '2026-04-30'];
        yield 'lintas tahun' => ['2026-12-15', 1, '2027-01-15'];
        yield '31 Agustus + 6 → Februari' => ['2026-08-31', 6, '2027-02-28'];
        yield 'nol bulan' => ['2026-04-01', 0, '2026-04-01'];
        yield 'maksimum masa sanksi' => ['2026-01-01', 255, '2047-04-01'];
        yield '29 Feb + 12' => ['2024-02-29', 12, '2025-02-28'];
        yield '29 Feb + 48' => ['2024-02-29', 48, '2028-02-29'];
        yield 'hasil melewati 2100' => ['2099-12-01', 255, '2121-03-01'];
    }

    #[DataProvider('tambahBulanProvider')]
    public function testTambahBulanDijepitAkhirBulan(string $tanggal, int $bulan, string $harapan): void
    {
        $this->assertSame($harapan, TanggalBisnis::tambahBulan($tanggal, $bulan));
    }

    public function testTambahBulanTidakAsosiatif(): void
    {
        $bertahap = TanggalBisnis::tambahBulan(TanggalBisnis::tambahBulan('2026-01-31', 1), 1);

        $this->assertSame('2026-03-28', $bertahap);
        $this->assertSame('2026-03-31', TanggalBisnis::tambahBulan('2026-01-31', 2));
    }

    public function testTambahBulanNegatifDitolak(): void
    {
        $this->expectException(InvalidArgumentException::class);

        TanggalBisnis::tambahBulan('2026-04-01', -1);
    }

    public function testTambahBulanTanggalTidakValidDitolak(): void
    {
        foreach (['2026-02-30', '', '01-04-2026', '0000-00-00', '2026-04-01 00:00:00'] as $tanggal) {
            try {
                TanggalBisnis::tambahBulan($tanggal, 1);
                $this->fail('Tanggal tidak valid lolos: ' . var_export($tanggal, true));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testTambahTahun(): void
    {
        $this->assertSame('2026-02-28', TanggalBisnis::tambahTahun('2024-02-29', 2));
        $this->assertSame('2028-02-29', TanggalBisnis::tambahTahun('2024-02-29', 4));
        $this->assertSame('2024-02-28', TanggalBisnis::tambahTahun('2023-02-28', 1));

        $this->expectException(InvalidArgumentException::class);
        TanggalBisnis::tambahTahun('2024-02-29', -1);
    }

    /**
     * @return iterable<string, array{string, string, int}>
     */
    public static function selisihProvider(): iterable
    {
        yield 'tepat 2 tahun' => ['2024-04-01', '2026-04-01', 24];
        yield 'kurang sehari' => ['2024-04-01', '2026-03-31', 23];
        yield '29 Feb ke 28 Feb (dijepit)' => ['2024-02-29', '2026-02-28', 24];
        yield '31 Jan ke 28 Feb' => ['2026-01-31', '2026-02-28', 1];
        yield '31 Jan ke 27 Feb' => ['2026-01-31', '2026-02-27', 0];
        yield '31 Jan ke 30 Mar' => ['2026-01-31', '2026-03-30', 1];
        yield 'tanggal sama' => ['2026-04-01', '2026-04-01', 0];
        yield 'bulan sama' => ['2026-04-01', '2026-04-30', 0];
    }

    #[DataProvider('selisihProvider')]
    public function testSelisihBulanPenuh(string $dari, string $sampai, int $harapan): void
    {
        $this->assertSame($harapan, TanggalBisnis::selisihBulanPenuh($dari, $sampai));
    }

    public function testSelisihBulanPenuhKonsistenDenganTambahBulan(): void
    {
        $pasangan = [
            ['2024-02-29', '2026-02-28'], ['2024-02-29', '2028-02-28'], ['2024-02-29', '2028-02-29'],
            ['2026-01-31', '2026-02-28'], ['2026-01-31', '2026-03-30'], ['2026-01-31', '2026-03-31'],
            ['2026-03-31', '2026-04-29'], ['2026-03-31', '2026-04-30'], ['2026-08-31', '2027-02-28'],
            ['2026-08-30', '2027-02-27'], ['2023-08-30', '2026-09-30'], ['2023-09-30', '2026-09-30'],
            ['2015-03-01', '2026-09-30'], ['2022-04-01', '2026-10-01'], ['2026-12-15', '2027-01-14'],
            ['2026-12-15', '2027-01-15'], ['2000-01-01', '2100-12-31'], ['1900-01-31', '1900-03-01'],
            ['2026-04-01', '2026-04-01'], ['2025-10-31', '2026-02-28'],
        ];

        foreach ($pasangan as [$dari, $sampai]) {
            $n = TanggalBisnis::selisihBulanPenuh($dari, $sampai);

            $this->assertLessThanOrEqual($sampai, TanggalBisnis::tambahBulan($dari, $n), "{$dari} → {$sampai} (n = {$n})");
            $this->assertGreaterThan($sampai, TanggalBisnis::tambahBulan($dari, $n + 1), "{$dari} → {$sampai} (n + 1)");
        }
    }

    public function testSelisihBulanPenuhTerbalikDitolak(): void
    {
        $this->expectException(InvalidArgumentException::class);

        TanggalBisnis::selisihBulanPenuh('2026-04-02', '2026-04-01');
    }

    public function testAwalBulanDanFormatIndonesia(): void
    {
        $this->assertSame('2026-04-01', TanggalBisnis::awalBulan('2026-04-15'));
        $this->assertSame('2024-02-01', TanggalBisnis::awalBulan('2024-02-29'));

        $this->assertSame('1 April 2027', TanggalBisnis::formatIndonesia('2027-04-01'));
        $this->assertSame('1 Oktober 2026', TanggalBisnis::formatIndonesia('2026-10-01'));
        $this->assertSame('29 Februari 2024', TanggalBisnis::formatIndonesia('2024-02-29'));
        $this->assertSame('31 Desember 2100', TanggalBisnis::formatIndonesia('2100-12-31'));
    }

    public function testJumlahHari(): void
    {
        $this->assertSame(29, TanggalBisnis::jumlahHari(2024, 2));
        $this->assertSame(28, TanggalBisnis::jumlahHari(2026, 2));
        $this->assertSame(28, TanggalBisnis::jumlahHari(2100, 2), '2100 bukan kabisat (habis dibagi 100)');
        $this->assertSame(29, TanggalBisnis::jumlahHari(2000, 2), '2000 kabisat (habis dibagi 400)');
        $this->assertSame(30, TanggalBisnis::jumlahHari(2026, 4));
        $this->assertSame(31, TanggalBisnis::jumlahHari(2026, 12));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function hariIniProvider(): iterable
    {
        yield '23:59:59 WIB masih 30 Sep' => ['2026-09-30T16:59:59Z', '2026-09-30'];
        yield '00:00 WIB sudah 1 Okt' => ['2026-09-30T17:00:00Z', '2026-10-01'];
        yield 'lintas tahun' => ['2026-12-31T17:00:00Z', '2027-01-01'];
        yield 'zona asal lain' => ['2026-10-01T06:30:00+09:00', '2026-10-01'];
    }

    #[DataProvider('hariIniProvider')]
    public function testHariIniMemakaiZonaJakarta(string $sekarang, string $harapan): void
    {
        $this->assertSame($harapan, TanggalBisnis::hariIni(new DateTimeImmutable($sekarang)));
    }

    public function testHariIniTanpaArgumenMengikutiTestNowDalamWib(): void
    {
        // 17:30 UTC = 00:30 WIB tanggal berikutnya; tanggal dari jam UTC (appTimezone) akan keliru satu hari.
        Time::setTestNow('2026-09-30 17:30:00', 'UTC');

        $this->assertSame('2026-10-01', TanggalBisnis::hariIni());
        $this->assertSame('2026-09-30', Time::now('UTC')->format('Y-m-d'));

        Time::setTestNow('2026-09-30 16:59:59', new DateTimeZone('UTC'));
        $this->assertSame('2026-09-30', TanggalBisnis::hariIni());
    }

    public function testWajibValid(): void
    {
        $this->assertSame('2026-04-01', TanggalBisnis::wajibValid('2026-04-01', 'TMT'));

        $this->expectException(InvalidArgumentException::class);
        TanggalBisnis::wajibValid('0000-00-00', 'TMT');
    }
}
