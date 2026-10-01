<?php

declare(strict_types=1);

namespace Tests\Unit\Kepegawaian\Kalkulasi;

use App\Libraries\Kepegawaian\Kalkulasi\HukumanDisiplin;
use CodeIgniter\Test\CIUnitTestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * CR-025 (B-21 "kalkulasi tanggal berakhir hukdis") — akhir = TMT + masa sanksi (SRS-FR-027, MTC-021), masa SK
 * diutamakan atas masa bawaan `jenis_hukdis.masa_sanksi_bulan` (K5b), tanpa masa → manual.
 *
 * @internal
 */
final class HukumanDisiplinTest extends CIUnitTestCase
{
    public function testMtc021MasaBawaanJenis(): void
    {
        $hasil = HukumanDisiplin::hitungTanggalBerakhir('2026-01-01', null, 12);

        $this->assertTrue($hasil->valid);
        $this->assertSame('2027-01-01', $hasil->tanggal);
        $this->assertNull($hasil->kode);
        $this->assertSame(['masa_bulan' => 12, 'sumber_masa' => 'master'], $hasil->detail);
    }

    public function testMasaSkMenangAtasMasaBawaan(): void
    {
        $hasil = HukumanDisiplin::hitungTanggalBerakhir('2026-01-01', 6, 12);

        $this->assertTrue($hasil->valid);
        $this->assertSame('2026-07-01', $hasil->tanggal);
        $this->assertSame(['masa_bulan' => 6, 'sumber_masa' => 'sk'], $hasil->detail);

        $this->assertSame(['masa_bulan' => 18, 'sumber_masa' => 'sk'], HukumanDisiplin::hitungTanggalBerakhir('2026-01-01', 18, null)->detail);
    }

    /**
     * @return iterable<string, array{string, int, string}>
     */
    public static function tanggalPinggirProvider(): iterable
    {
        yield 'akhir Januari + 1' => ['2026-01-31', 1, '2026-02-28'];
        yield 'akhir Januari kabisat + 1' => ['2024-01-31', 1, '2024-02-29'];
        yield '29 Feb + 12' => ['2024-02-29', 12, '2025-02-28'];
        yield '29 Feb + 48' => ['2024-02-29', 48, '2028-02-29'];
        yield '31 Agustus + 6' => ['2026-08-31', 6, '2027-02-28'];
        yield 'lintas tahun' => ['2026-12-01', 1, '2027-01-01'];
        yield 'masa maksimum' => ['2026-01-01', 255, '2047-04-01'];
        yield 'masa minimum' => ['2026-01-01', 1, '2026-02-01'];
    }

    #[DataProvider('tanggalPinggirProvider')]
    public function testTanggalPinggir(string $tmt, int $masa, string $harapan): void
    {
        $this->assertSame($harapan, HukumanDisiplin::hitungTanggalBerakhir($tmt, null, $masa)->tanggal, 'masa bawaan');
        $this->assertSame($harapan, HukumanDisiplin::hitungTanggalBerakhir($tmt, $masa, null)->tanggal, 'masa SK');
    }

    public function testTanpaMasaDiisiManual(): void
    {
        $hasil = HukumanDisiplin::hitungTanggalBerakhir('2026-01-01', null, null);

        $this->assertTrue($hasil->valid);
        $this->assertNull($hasil->tanggal);
        $this->assertSame('tanpa_masa', $hasil->kode);
        $this->assertSame(
            'Jenis hukuman disiplin ini tidak memiliki masa sanksi. Isi tanggal berakhir secara manual bila SK menyebutkannya.',
            $hasil->pesan,
        );
        $this->assertSame(['masa_bulan' => null, 'sumber_masa' => null], $hasil->detail);
        $this->assertNull(HukumanDisiplin::masaBerlaku(null, null));
    }

    /**
     * @return iterable<string, array{int|null, int|null}>
     */
    public static function masaTidakValidProvider(): iterable
    {
        yield 'SK 0' => [0, 12];
        yield 'SK 256' => [256, null];
        yield 'SK -1' => [-1, 12];
        yield 'master 0' => [null, 0];
        yield 'master 256' => [null, 256];
        yield 'master -1' => [null, -1];
    }

    #[DataProvider('masaTidakValidProvider')]
    public function testMasaTidakValid(?int $masaSk, ?int $masaBawaan): void
    {
        $hasil = HukumanDisiplin::hitungTanggalBerakhir('2026-01-01', $masaSk, $masaBawaan);

        $this->assertFalse($hasil->valid);
        $this->assertNull($hasil->tanggal);
        $this->assertSame('masa_sanksi_tidak_valid', $hasil->kode);
        $this->assertSame('Masa sanksi harus bilangan bulat 1–255 bulan.', $hasil->pesan);
    }

    public function testTmtTidakValid(): void
    {
        $this->assertSame('tanggal_tidak_valid', HukumanDisiplin::hitungTanggalBerakhir('2026-02-29', null, 12)->kode);
        $this->assertSame('tanggal_tidak_valid', HukumanDisiplin::hitungTanggalBerakhir('2026-13-01', null, 12)->kode);

        $kosong = HukumanDisiplin::hitungTanggalBerakhir('', 6, 12);
        $this->assertSame('tanggal_wajib', $kosong->kode);
        $this->assertSame('TMT hukuman disiplin wajib diisi.', $kosong->pesan);
    }

    public function testValidasiTanggalBerakhirManual(): void
    {
        $sama = HukumanDisiplin::validasiTanggalBerakhir('2026-01-01', '2026-01-01');
        $this->assertFalse($sama->valid);
        $this->assertSame('akhir_tidak_setelah_tmt', $sama->kode);
        $this->assertSame('Tanggal berakhir hukuman disiplin harus setelah TMT (1 Januari 2026).', $sama->pesan);

        $sebelum = HukumanDisiplin::validasiTanggalBerakhir('2026-01-01', '2025-12-31');
        $this->assertSame('akhir_tidak_setelah_tmt', $sebelum->kode);

        $sah = HukumanDisiplin::validasiTanggalBerakhir('2026-01-01', '2026-01-02');
        $this->assertTrue($sah->valid);
        $this->assertSame('2026-01-02', $sah->tanggal);

        $akhirSalah = HukumanDisiplin::validasiTanggalBerakhir('2026-01-01', '2026-02-30');
        $this->assertSame('tanggal_tidak_valid', $akhirSalah->kode);
        $this->assertStringStartsWith('Tanggal berakhir hukuman disiplin tidak valid.', (string) $akhirSalah->pesan);

        $this->assertSame('tanggal_wajib', HukumanDisiplin::validasiTanggalBerakhir('', '2026-01-02')->kode);
    }

    public function testMasaTeks(): void
    {
        $this->assertSame('6 Bulan', HukumanDisiplin::masaTeks(6));
        $this->assertSame('1 Tahun', HukumanDisiplin::masaTeks(12));
        $this->assertSame('1 Tahun 6 Bulan', HukumanDisiplin::masaTeks(18));
        $this->assertSame('21 Tahun 3 Bulan', HukumanDisiplin::masaTeks(255));

        $this->expectException(InvalidArgumentException::class);
        HukumanDisiplin::masaTeks(0);
    }
}
