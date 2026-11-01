<?php

declare(strict_types=1);

namespace Tests\Unit\Libraries\Waktu;

use App\Libraries\Kepegawaian\Lkh\LkhService;
use App\Libraries\Kepegawaian\Usulan\TandaTerimaUsulan;
use CodeIgniter\Test\CIUnitTestCase;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * CR-086: kode tanda terima dan kunci QR LKH harus sama dengan perhitungan legacy untuk instan yang sama. Legacy menyimpan jam
 * dinding WIB dan menghitung `strtotime(created_at)` di server WIB; v2 menyimpan UTC.
 *
 * @internal
 */
final class KodeStempelLegacyTest extends CIUnitTestCase
{
    /**
     * @return iterable<string, array{string, string}> [jam dinding WIB legacy, stempel UTC v2]
     */
    public static function instan(): iterable
    {
        yield 'tengah hari' => ['2026-10-13 12:30:15', '2026-10-13 05:30:15'];
        yield '1 detik sebelum 00:00 WIB' => ['2026-12-31 23:59:59', '2026-12-31 16:59:59'];
        yield 'tepat 00:00 WIB (17:00 UTC) tahun baru' => ['2027-01-01 00:00:00', '2026-12-31 17:00:00'];
    }

    #[DataProvider('instan')]
    public function testKodeTandaTerimaSamaDenganLegacy(string $wib, string $utc): void
    {
        $epoch  = (new DateTimeImmutable($wib, new DateTimeZone('Asia/Jakarta')))->getTimestamp();
        $legacy = (new DateTimeImmutable($wib, new DateTimeZone('Asia/Jakarta')))->format('y') . '0' . $epoch . '1' . 42;

        $this->assertSame($legacy, TandaTerimaUsulan::kode($utc, true, 42));
        $this->assertSame(substr($legacy, 0, -3) . '0' . 42, TandaTerimaUsulan::kode($utc, false, 42), 'KARIS/KARSU memakai jenis 0');

        $tahunWib = (new DateTimeImmutable($wib, new DateTimeZone('Asia/Jakarta')))->format('y');
        $this->assertSame('0000' . $tahunWib . '1' . 42, TandaTerimaUsulan::noTiket($utc, true, 42), 'no tiket memakai tahun WIB');
    }

    #[DataProvider('instan')]
    public function testKunciQrLkhSamaDenganLegacy(string $wib, string $utc): void
    {
        $epoch = (new DateTimeImmutable($wib, new DateTimeZone('Asia/Jakarta')))->getTimestamp();

        $this->assertSame('7' . '26' . '0' . $epoch, LkhService::kunciQr(7, '2026-10-12', $utc));
    }
}
