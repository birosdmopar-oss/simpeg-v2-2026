<?php

declare(strict_types=1);

namespace Tests\Unit\Kepegawaian\Lkh\AutoApproval;

use App\Libraries\Kepegawaian\Lkh\AutoApproval\PenentuAutoApproval;
use CodeIgniter\Test\CIUnitTestCase;
use InvalidArgumentException;

/**
 * MAKE-068 — aturan murni auto-approval LKH: bulan lalu, rentang tanggal, dan penolakan bulan. Tanpa DB dan jam sistem.
 *
 * @internal
 */
final class PenentuAutoApprovalTest extends CIUnitTestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function bulanLaluProvider(): iterable
    {
        yield 'tanggal 5 bulan biasa' => ['2026-11-05', '2026-10'];
        yield 'awal tahun' => ['2027-01-05', '2026-12'];
        yield 'akhir bulan' => ['2026-03-31', '2026-02'];
    }

    /**
     * @dataProvider bulanLaluProvider
     */
    public function testBulanLaluDariTanggalHariIni(string $hariIni, string $harapan): void
    {
        $this->assertSame($harapan, PenentuAutoApproval::bulanLalu($hariIni));
    }

    public function testTanggalHariIniTidakSahDitolak(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PenentuAutoApproval::bulanLalu('2026-02-30');
    }

    /**
     * @return iterable<string, array{string, array{awal: string, akhir: string}}>
     */
    public static function rentangProvider(): iterable
    {
        yield 'bulan 31 hari' => ['2026-10', ['awal' => '2026-10-01', 'akhir' => '2026-10-31']];
        yield 'bulan 30 hari' => ['2026-09', ['awal' => '2026-09-01', 'akhir' => '2026-09-30']];
        yield 'februari biasa' => ['2026-02', ['awal' => '2026-02-01', 'akhir' => '2026-02-28']];
        yield 'februari kabisat' => ['2028-02', ['awal' => '2028-02-01', 'akhir' => '2028-02-29']];
        yield 'abad bukan kabisat' => ['2100-02', ['awal' => '2100-02-01', 'akhir' => '2100-02-28']];
    }

    /**
     * @dataProvider rentangProvider
     *
     * @param array{awal: string, akhir: string} $harapan
     */
    public function testRentangTanggalBulan(string $bulan, array $harapan): void
    {
        $this->assertSame($harapan, PenentuAutoApproval::rentangBulan($bulan));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function formatBulanSalahProvider(): iterable
    {
        yield 'bulan 13' => ['2026-13'];
        yield 'tanpa nol' => ['2026-1'];
        yield 'tanggal lengkap' => ['2026-10-01'];
    }

    /**
     * @dataProvider formatBulanSalahProvider
     */
    public function testFormatBulanSalahDitolak(string $bulan): void
    {
        $this->expectException(InvalidArgumentException::class);

        PenentuAutoApproval::rentangBulan($bulan);
    }

    public function testRunNyataHanyaBulanLalu(): void
    {
        $this->assertNull(PenentuAutoApproval::tolakBulan('2026-10', '2026-11-05', false));
        $this->assertNotNull(PenentuAutoApproval::tolakBulan('2026-09', '2026-11-05', false));
    }

    public function testDryRunBolehBulanLampauLain(): void
    {
        $this->assertNull(PenentuAutoApproval::tolakBulan('2026-09', '2026-11-05', true));
    }

    public function testBulanBerjalanDanMasaDepanDitolakWalauDryRun(): void
    {
        $this->assertNotNull(PenentuAutoApproval::tolakBulan('2026-11', '2026-11-05', true));
        $this->assertNotNull(PenentuAutoApproval::tolakBulan('2026-12', '2026-11-05', true));
        $this->assertNotNull(PenentuAutoApproval::tolakBulan('2026-12', '2026-11-05', false));
    }
}
