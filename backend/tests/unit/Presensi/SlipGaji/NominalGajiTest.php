<?php

declare(strict_types=1);

namespace Tests\Unit\Presensi\SlipGaji;

use App\Libraries\Presensi\SlipGaji\NominalGaji;
use CodeIgniter\Test\CIUnitTestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * CR-027 (N-3..N-6, P-10) — nominal sebagai int sen: nilai mentah sel Excel (format Indonesia ketat untuk teks), input
 * API kanonik, batas `DECIMAL(14,2)`, dan format rupiah. Termasuk regresi bug legacy ×10 (`4500000.5`) dan ×100 (form
 * koreksi). Semua nominal sintetis.
 *
 * @internal
 */
final class NominalGajiTest extends CIUnitTestCase
{
    /**
     * @return iterable<string, array{0: mixed, 1: int}>
     */
    public static function selDiterima(): iterable
    {
        yield 'teks ribuan bertitik' => ['1.234.567', 123_456_700];

        yield 'teks desimal koma' => ['1.234,50', 123_450];

        yield 'teks tanpa pemisah' => ['1234567', 123_456_700];

        yield 'teks 0,5' => ['0,5', 50];

        yield 'teks 1.234 = ribuan' => ['1.234', 123_400];

        yield 'teks berspasi di tepi' => ['  1.000  ', 100_000];

        yield 'teks ber-NBSP di tepi' => ["\u{00A0}1.000", 100_000];

        yield 'teks negatif' => ['-1.000', -100_000];

        yield 'int' => [4_500_000, 450_000_000];

        yield 'float bulat' => [4500000.0, 450_000_000];

        yield 'float 4500000.5 (regresi ×10 legacy)' => [4500000.5, 450_000_050];

        yield 'float 0.5' => [0.5, 50];

        yield 'float 19.99' => [19.99, 1999];

        yield 'float 4500000.1' => [4500000.1, 450_000_010];

        yield 'float 0.1 + 0.2' => [0.1 + 0.2, 30];

        yield 'float maksimum' => [999999999999.99, 99_999_999_999_999];

        yield 'teks maksimum' => ['999.999.999.999,99', 99_999_999_999_999];

        yield 'int nol' => [0, 0];
    }

    #[DataProvider('selDiterima')]
    public function testDariSelDiterima(mixed $nilai, int $sen): void
    {
        $hasil = NominalGaji::dariSel($nilai);

        $this->assertTrue($hasil->valid, (string) $hasil->pesan);
        $this->assertSame($sen, $hasil->nilai);
    }

    public function testDariSelKosong(): void
    {
        foreach (['', '   ', "\u{00A0}", " \u{00A0}\t", null] as $nilai) {
            $hasil = NominalGaji::dariSel($nilai);

            $this->assertTrue($hasil->valid, var_export($nilai, true));
            $this->assertNull($hasil->nilai, var_export($nilai, true));
        }
    }

    /**
     * @return iterable<string, array{0: mixed, 1: string}>
     */
    public static function selDitolak(): iterable
    {
        foreach (['-', '.', 'abc', 'Rp 1.000', '1 234', '1,234,567', '1234567.5', '1.5', '1e3', '(125.000)', '+100', '1.234,567', '#REF!', '−1.000', '1.23.456', ',5'] as $teks) {
            yield "teks {$teks}" => [$teks, 'nominal_tidak_valid'];
        }

        yield 'bool' => [true, 'nominal_tidak_valid'];

        yield 'array' => [[], 'nominal_tidak_valid'];

        yield 'NAN' => [NAN, 'nominal_tidak_valid'];

        yield 'INF' => [INF, 'nominal_tidak_valid'];

        yield 'float 1.005' => [1.005, 'nominal_desimal_berlebih'];

        yield 'float 1.0004' => [1.0004, 'nominal_desimal_berlebih'];

        yield 'float 2.675' => [2.675, 'nominal_desimal_berlebih'];

        yield 'teks 13 digit' => ['1.000.000.000.000', 'nominal_terlalu_besar'];

        yield 'teks 22 digit tanpa saturasi' => ['9999999999999999999999', 'nominal_terlalu_besar'];

        yield 'int 10^12' => [1_000_000_000_000, 'nominal_terlalu_besar'];

        yield 'int PHP_INT_MIN' => [PHP_INT_MIN, 'nominal_terlalu_besar'];

        yield 'float 1e300' => [1e300, 'nominal_terlalu_besar'];
    }

    #[DataProvider('selDitolak')]
    public function testDariSelDitolak(mixed $nilai, string $kode): void
    {
        $hasil = NominalGaji::dariSel($nilai, 'Kolom tjistri');

        $this->assertFalse($hasil->valid);
        $this->assertSame($kode, $hasil->kode);
        $this->assertStringStartsWith('Kolom tjistri ', (string) $hasil->pesan);
    }

    public function testPesanMenyebutNilaiMentah(): void
    {
        $this->assertStringContainsString("(nilai: 'Rp 1.000')", (string) NominalGaji::dariSel('Rp 1.000')->pesan);
        $this->assertStringContainsString('(nilai: 1.005)', (string) NominalGaji::dariSel(1.005)->pesan);
    }

    /**
     * @return iterable<string, array{0: mixed, 1: int}>
     */
    public static function apiDiterima(): iterable
    {
        yield 'teks DECIMAL dari DB (regresi ×100 legacy)' => ['4500000.00', 450_000_000];

        yield 'int' => [4_500_000, 450_000_000];

        yield 'teks satu desimal' => ['4500000.5', 450_000_050];

        yield 'float' => [4500000.5, 450_000_050];

        yield 'teks negatif' => ['-1.00', -100];

        yield 'teks bulat' => ['0', 0];
    }

    #[DataProvider('apiDiterima')]
    public function testDariInputApiDiterima(mixed $nilai, int $sen): void
    {
        $hasil = NominalGaji::dariInputApi($nilai);

        $this->assertTrue($hasil->valid, (string) $hasil->pesan);
        $this->assertSame($sen, $hasil->nilai);
    }

    public function testDariInputApiDitolak(): void
    {
        foreach (['4.500.000', '4500000,50', ' 100', '100 ', '1e3', '', '1.005', '1234567890123', null, true, [], 'abc'] as $nilai) {
            $hasil = NominalGaji::dariInputApi($nilai, 'Gaji Pokok');

            $this->assertFalse($hasil->valid, var_export($nilai, true));
            $this->assertSame('nominal_tidak_valid', $hasil->kode, var_export($nilai, true));
            $this->assertStringStartsWith('Gaji Pokok ', (string) $hasil->pesan);
        }

        $this->assertSame('nominal_desimal_berlebih', NominalGaji::dariInputApi(1.005)->kode);
        $this->assertSame('nominal_terlalu_besar', NominalGaji::dariInputApi(1_000_000_000_000)->kode);
    }

    public function testPeriksaNonNegatif(): void
    {
        $hasil = NominalGaji::periksaNonNegatif(-1, 'Kolom potlain');

        $this->assertNotNull($hasil);
        $this->assertSame('nominal_negatif', $hasil->kode);
        $this->assertSame('Kolom potlain tidak boleh negatif.', $hasil->pesan);
        $this->assertNull(NominalGaji::periksaNonNegatif(0));
        $this->assertNull(NominalGaji::periksaNonNegatif(1));
    }

    public function testFormatAngka(): void
    {
        $this->assertSame('4.500.001', NominalGaji::formatAngka(450_000_050));
        $this->assertSame('4.500.000', NominalGaji::formatAngka(450_000_049));
        $this->assertSame('-2', NominalGaji::formatAngka(-150));
        $this->assertSame('0', NominalGaji::formatAngka(-40));
        $this->assertSame('0', NominalGaji::formatAngka(0));
        $this->assertSame('1', NominalGaji::formatAngka(50));
        $this->assertSame('1.000.000.000.000', NominalGaji::formatAngka(NominalGaji::SEN_MAKS));
    }

    public function testDesimalDb(): void
    {
        $this->assertSame('4500000.50', NominalGaji::keDesimal(450_000_050));
        $this->assertSame('-1.50', NominalGaji::keDesimal(-150));
        $this->assertSame('0.00', NominalGaji::keDesimal(0));
        $this->assertSame('0.05', NominalGaji::keDesimal(5));
        $this->assertSame('999999999999.99', NominalGaji::keDesimal(NominalGaji::SEN_MAKS));

        $this->assertSame(450_000_050, NominalGaji::dariDesimal('4500000.50'));
        $this->assertSame(-150, NominalGaji::dariDesimal('-1.50'));
        $this->assertSame(0, NominalGaji::dariDesimal('0.00'));
        $this->assertSame(NominalGaji::SEN_MAKS, NominalGaji::dariDesimal('999999999999.99'));

        foreach (['abc', '4500000.5', '4500000', '4.500.000,00', ' 1.00', '1234567890123.00', ''] as $teks) {
            try {
                NominalGaji::dariDesimal($teks);
                $this->fail('Tidak melempar untuk ' . var_export($teks, true));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testKeDesimalDiLuarBatasMelempar(): void
    {
        $this->expectException(InvalidArgumentException::class);

        NominalGaji::keDesimal(NominalGaji::SEN_MAKS + 1);
    }

    public function testRoundTripDesimal(): void
    {
        foreach ([0, 1, 99, 100, 123_456_789, -5, NominalGaji::SEN_MAKS, -NominalGaji::SEN_MAKS] as $sen) {
            $this->assertSame($sen, NominalGaji::dariDesimal(NominalGaji::keDesimal($sen)));
            $this->assertSame($sen, NominalGaji::dariInputApi(NominalGaji::keDesimal($sen))->nilai);
        }
    }
}
