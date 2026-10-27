<?php

declare(strict_types=1);

namespace Tests\Unit\Layanan\Studi;

use App\Libraries\Layanan\Studi\LamaTahunStudi;
use App\Libraries\Layanan\Studi\RentangTanggal;
use CodeIgniter\Test\CIUnitTestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * MAKE-024 — `lama_tahun` TB/IB = `diff.y + diff.m/12` 1 desimal [K TB-17 `Local.php:3203-3214`]; contoh legacy dari riset
 * (dihitung ulang dengan kode legacy) + boundary kabisat, akhir bulan, lintas tahun, pembulatan setengah.
 *
 * @internal
 */
final class LamaTahunStudiTest extends CIUnitTestCase
{
    /**
     * @return array<string, array{0: string, 1: string, 2: float, 3: string}>
     */
    public static function kasus(): array
    {
        return [
            'kabisat 29-02 s.d. 28-02 (y0 m11 d30)' => ['2024-02-29', '2025-02-28', 0.9, '0.90'],
            'TB "4 tahun" tidak inklusif'           => ['2024-09-01', '2028-08-31', 3.9, '3.90'],
            'tepat 4 tahun'                         => ['2024-09-01', '2028-09-01', 4.0, '4.00'],
            'akhir bulan 31-01 s.d. 01-03 (m0)'     => ['2024-01-31', '2024-03-01', 0.0, '0.00'],
            'lintas tahun y2 m5'                    => ['2023-08-15', '2026-02-14', 2.4, '2.40'],
            'akhir Juni s.d. akhir Juli y1 m1'      => ['2020-06-30', '2021-07-31', 1.1, '1.10'],
            'hari sama'                             => ['2025-05-05', '2025-05-05', 0.0, '0.00'],
            'setengah 3/12 = 0,25 → 0,3'            => ['2024-01-01', '2024-04-01', 0.3, '0.30'],
            'setengah 9/12 = 0,75 → 0,8'            => ['2024-01-01', '2024-10-01', 0.8, '0.80'],
            '1 + 3/12 = 1,25 → 1,3'                 => ['2022-01-15', '2023-04-15', 1.3, '1.30'],
            'satu bulan kurang sehari'              => ['2025-01-15', '2025-02-14', 0.0, '0.00'],
        ];
    }

    #[DataProvider('kasus')]
    public function testRumusLegacy(string $mulai, string $akhir, float $lama, string $kolom): void
    {
        $this->assertSame($lama, LamaTahunStudi::hitung($mulai, $akhir));
        $this->assertSame($kolom, LamaTahunStudi::nilaiKolom($mulai, $akhir));
    }

    public function testTanggalTidakValidAdalahKesalahanPemanggil(): void
    {
        $this->expectException(InvalidArgumentException::class);
        LamaTahunStudi::hitung('2025-02-30', '2025-03-01');
    }

    public function testRentangTanggalBeririsanInklusif(): void
    {
        $this->assertTrue(RentangTanggal::beririsan('2025-01-01', '2025-01-31', '2025-01-31', '2025-02-10'), 'ujung bersentuhan');
        $this->assertFalse(RentangTanggal::beririsan('2025-01-01', '2025-01-30', '2025-01-31', '2025-02-10'));
        $this->assertTrue(RentangTanggal::beririsan('2025-01-01', '2025-12-31', '2025-03-01', '2025-03-02'), 'di dalam');
        $this->assertSame('2024-02-29', RentangTanggal::tambahHari('2024-02-28', 1));
        $this->assertSame('2024-01-01', RentangTanggal::tambahHari('2023-12-31', 1));
    }
}
