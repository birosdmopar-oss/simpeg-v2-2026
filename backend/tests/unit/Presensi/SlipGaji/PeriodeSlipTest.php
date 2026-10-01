<?php

declare(strict_types=1);

namespace Tests\Unit\Presensi\SlipGaji;

use App\Libraries\Presensi\SlipGaji\PeriodeSlip;
use CodeIgniter\Test\CIUnitTestCase;
use InvalidArgumentException;

/**
 * CR-027 (G-7, P-1, P-2, P-8) — periode slip: kunci API kanonik `YYYY-MM`, label Indonesia, dan aritmetika bulan.
 *
 * @internal
 */
final class PeriodeSlipTest extends CIUnitTestCase
{
    public function testDariTeksValid(): void
    {
        $hasil = PeriodeSlip::dariTeks('2026-10');

        $this->assertTrue($hasil->valid);
        $this->assertInstanceOf(PeriodeSlip::class, $hasil->nilai);
        $this->assertSame(2026, $hasil->nilai->tahun);
        $this->assertSame(10, $hasil->nilai->bulan);

        $this->assertTrue(PeriodeSlip::dariTeks('1900-01')->valid);
        $this->assertTrue(PeriodeSlip::dariTeks('2100-12')->valid);
    }

    public function testDariTeksKosongWajib(): void
    {
        foreach (['', '   '] as $teks) {
            $hasil = PeriodeSlip::dariTeks($teks);

            $this->assertFalse($hasil->valid);
            $this->assertSame('periode_wajib', $hasil->kode, var_export($teks, true));
            $this->assertSame('Periode slip gaji wajib diisi.', $hasil->pesan);
        }
    }

    public function testDariTeksTidakValid(): void
    {
        foreach (['2026-1', '10-2026', '1-2026', '2026-13', '2026-00', "2026-10\n", ' 2026-10', '2026-10 ', '1899-12', '2101-01', '2026/10', '२०२६-10'] as $teks) {
            $hasil = PeriodeSlip::dariTeks($teks);

            $this->assertFalse($hasil->valid, var_export($teks, true));
            $this->assertSame('periode_tidak_valid', $hasil->kode, var_export($teks, true));
        }
    }

    public function testKunciDanLabel(): void
    {
        $periode = PeriodeSlip::buat(2026, 10);

        $this->assertSame('2026-10', $periode->kunci());
        $this->assertSame('Oktober 2026', $periode->label());
        $this->assertSame('2026-01', PeriodeSlip::buat(2026, 1)->kunci());
        $this->assertSame('Januari 2026', PeriodeSlip::buat(2026, 1)->label());
        $this->assertSame(2026 * 12 + 9, $periode->indeks());
    }

    public function testSebelumnyaDanBerikutnyaLintasTahun(): void
    {
        $this->assertSame('2026-12', PeriodeSlip::buat(2027, 1)->sebelumnya()->kunci());
        $this->assertSame('Desember 2026', PeriodeSlip::buat(2027, 1)->sebelumnya()->label());
        $this->assertSame('2027-01', PeriodeSlip::buat(2026, 12)->berikutnya()->kunci());
        $this->assertSame('2026-09', PeriodeSlip::buat(2026, 10)->sebelumnya()->kunci());
        // Data sistem boleh melewati batas input 2100.
        $this->assertSame('2101-01', PeriodeSlip::buat(2100, 12)->berikutnya()->kunci());
    }

    public function testSama(): void
    {
        $this->assertTrue(PeriodeSlip::buat(2026, 8)->sama(PeriodeSlip::buat(2026, 8)));
        $this->assertFalse(PeriodeSlip::buat(2026, 8)->sama(PeriodeSlip::buat(2025, 8)));
    }

    public function testBuatDataSistemTidakValidMelempar(): void
    {
        foreach ([[2026, 13], [2026, 0], [1899, 12], [10000, 1]] as [$tahun, $bulan]) {
            try {
                PeriodeSlip::buat($tahun, $bulan);
                $this->fail("Tidak melempar untuk {$tahun}-{$bulan}");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString((string) $tahun, $e->getMessage());
            }
        }
    }
}
