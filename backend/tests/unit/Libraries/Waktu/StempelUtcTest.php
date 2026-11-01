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

    public function testKeWibPadaBatas1700Utc(): void
    {
        $this->assertSame('2026-09-15 00:00:00', StempelUtc::keWib('2026-09-14 17:00:00'));
        $this->assertSame('2026-09-14 23:59:59', StempelUtc::keWib('2026-09-14 16:59:59'));
        $this->assertSame('2026-09-15', StempelUtc::tanggalWib('2026-09-14 17:00:00'));
        $this->assertSame('2026-09-14', StempelUtc::tanggalWib('2026-09-14 16:59:59'));
        $this->assertSame('27', StempelUtc::keWib('2026-12-31 17:00:00', 'y'), 'tahun WIB melewati pergantian tahun');
        $this->assertSame('2026-09-15T00:00:00+07:00', StempelUtc::keIsoWib('2026-09-14 17:00:00'));
    }

    public function testEpochMemakaiUtcSamaDenganJamDindingWibLegacy(): void
    {
        // Legacy: strtotime('2026-09-15 00:00:00') pada server WIB = 2026-09-14 17:00:00 UTC.
        $this->assertSame(1789405200, StempelUtc::epoch('2026-09-14 17:00:00'));
        $this->assertSame(
            (new \DateTimeImmutable('2026-09-15 00:00:00', new \DateTimeZone('Asia/Jakarta')))->getTimestamp(),
            StempelUtc::epoch('2026-09-14 17:00:00'),
        );
    }

    public function testNilaiKosongAtauTakTerbacaMenjadiNull(): void
    {
        foreach ([null, '', '   ', '0000-00-00 00:00:00', 'bukan tanggal'] as $nilai) {
            $this->assertNull(StempelUtc::keWib($nilai));
            $this->assertNull(StempelUtc::epoch($nilai));
        }
    }
}
