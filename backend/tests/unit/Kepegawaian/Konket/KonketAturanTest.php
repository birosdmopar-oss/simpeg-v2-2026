<?php

declare(strict_types=1);

namespace Tests\Unit\Kepegawaian\Konket;

use App\Libraries\Kepegawaian\Konket\KonketAturan;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Aturan murni Konket B-13 (MAKE-013): konket_min_date legacy dan hari kerja.
 *
 * @internal
 */
final class KonketAturanTest extends CIUnitTestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function kasusMinimal(): iterable
    {
        yield 'tgl 1-3 → 16 bulan lalu' => ['2026-10-03', '2026-09-16'];
        yield 'awal tahun' => ['2026-01-02', '2025-12-16'];
        yield 'tgl 4-18 → 1 bulan ini' => ['2026-10-18', '2026-10-01'];
        yield 'tgl 19+ → 16 bulan ini' => ['2026-10-19', '2026-10-16'];
        yield 'Des 2021' => ['2021-12-28', '2021-12-01'];
        yield 'Des 2023 tgl 2' => ['2023-12-02', '2023-11-01'];
        yield 'Jun 2024 tgl 19' => ['2024-06-19', '2024-06-16'];
        yield 'Des 2024 tgl 16' => ['2024-12-16', '2024-12-01'];
        yield 'Des 2025 tgl 16' => ['2025-12-16', '2025-12-16'];
    }

    #[DataProvider('kasusMinimal')]
    public function testTanggalMinimalIkutLegacy(string $hariIni, string $harapan): void
    {
        $this->assertSame($harapan, KonketAturan::tanggalMinimal($hariIni));
    }

    public function testHariKerjaMelewatiAkhirPekanDanLibur(): void
    {
        // 2026-10-09 Jumat, 10-10/11 akhir pekan, 10-12 Senin libur, 10-13 Selasa.
        $this->assertSame(['2026-10-09', '2026-10-13'], KonketAturan::hariKerja('2026-10-09', '2026-10-13', ['2026-10-12' => true]));
        $this->assertSame('2026-10-09', KonketAturan::hariKerjaSebelum('2026-10-13', ['2026-10-12' => true]));
    }
}
