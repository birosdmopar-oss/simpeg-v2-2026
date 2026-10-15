<?php

declare(strict_types=1);

namespace Tests\Unit\Kepegawaian\Lkh;

use App\Libraries\Kepegawaian\Lkh\LkhAturan;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Aturan murni LKH B-12b (MAKE-013): tanggal minimal 4 hari kerja, batas tanggal 5, batas ubah/hapus legacy.
 *
 * @internal
 */
final class LkhAturanTest extends CIUnitTestCase
{
    public function testEmpatHariKerjaTerakhirMelewatiAkhirPekanDanLibur(): void
    {
        // Selasa 2026-10-13: hari kerja mundur 13, 12 (libur dilewati), 09, 08, 07 → ke-4 = 2026-10-07.
        $this->assertSame('2026-10-07', LkhAturan::tanggalMinimal('2026-10-13', ['2026-10-12' => true]));
        $this->assertSame('2026-10-08', LkhAturan::tanggalMinimal('2026-10-13', []));
    }

    public function testMulaiTanggalLimaTidakSebelumAwalBulan(): void
    {
        // Rabu 2026-10-07: 4 hari kerja = 07, 06, 05, 02 → dipotong ke 2026-10-01.
        $this->assertSame('2026-10-02', LkhAturan::tanggalMinimal('2026-10-07', []));
        $this->assertSame('2026-10-01', LkhAturan::tanggalMinimal('2026-10-07', ['2026-10-02' => true, '2026-10-05' => true]));
        // Tanggal 2 (sebelum tanggal 5): boleh mundur ke bulan lalu.
        $this->assertSame('2026-09-29', LkhAturan::tanggalMinimal('2026-10-02', []));
    }

    public function testPengecualianLegacy(): void
    {
        $this->assertSame('2021-12-01', LkhAturan::tanggalMinimal('2021-12-03', []));
        $this->assertSame('2024-01-29', LkhAturan::tanggalMinimal('2024-02-05', []));
    }

    public function testBatasUbahDanHapus(): void
    {
        $this->assertSame('2026-10-01', LkhAturan::batasUbah('2026-10-13'));
        $this->assertSame('2026-09-01', LkhAturan::batasUbah('2026-10-04'));
        $this->assertSame('2026-09-30', LkhAturan::batasHapus('2026-10-13'));
    }
}
