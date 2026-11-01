<?php

declare(strict_types=1);

namespace Tests\Unit\Libraries\Waktu;

use App\Libraries\Waktu\StempelUtc;
use CodeIgniter\Test\CIUnitTestCase;
use InvalidArgumentException;

/**
 * CR-083 (keputusan Horii 30-10): batas tanggal bisnis WIB atas kolom DATETIME berisi UTC.
 *
 * @internal
 */
final class StempelUtcTest extends CIUnitTestCase
{
    public function testAwalHariDanHariBerikut(): void
    {
        $this->assertSame('2026-09-14 17:00:00', StempelUtc::awalHari('2026-09-15'));
        $this->assertSame('2026-09-15 17:00:00', StempelUtc::awalHariBerikut('2026-09-15'));
        $this->assertSame('2026-12-31 17:00:00', StempelUtc::awalHariBerikut('2026-12-31'), 'lintas tahun');
        $this->assertSame('2028-02-28 17:00:00', StempelUtc::awalHari('2028-02-29'), 'kabisat');
    }

    public function testTanggalTidakSahDitolak(): void
    {
        $this->expectException(InvalidArgumentException::class);
        StempelUtc::awalHari('2026-09-15 00:00:00');
    }
}
