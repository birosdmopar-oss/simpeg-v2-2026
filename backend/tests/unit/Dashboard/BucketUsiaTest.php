<?php

declare(strict_types=1);

namespace Tests\Unit\Dashboard;

use App\Libraries\Dashboard\Statistik\BucketUsia;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * F7-6 bucket usia statistik (MAKE-036): batas tiap kelompok, umur rusak (≤ 0, < 18) ikut kelompok terbawah.
 *
 * @internal
 */
final class BucketUsiaTest extends CIUnitTestCase
{
    /**
     * @return iterable<string, array{int, string}>
     */
    public static function umur(): iterable
    {
        yield 'nol' => [0, 'le30'];

        yield 'negatif (tgl_lahir rusak)' => [-3, 'le30'];

        yield 'di bawah 18' => [17, 'le30'];

        yield '30 batas atas le30' => [30, 'le30'];

        yield '31 awal 31–40' => [31, '31_40'];

        yield '40' => [40, '31_40'];

        yield '41' => [41, '41_45'];

        yield '45' => [45, '41_45'];

        yield '46' => [46, '46_50'];

        yield '50' => [50, '46_50'];

        yield '51' => [51, '51_56'];

        yield '56' => [56, '51_56'];

        yield '57' => [57, '57_60'];

        yield '60' => [60, '57_60'];

        yield '61' => [61, 'gt60'];

        yield '99' => [99, 'gt60'];
    }

    #[DataProvider('umur')]
    public function testUmurMasukBucketYangBenar(int $umur, string $key): void
    {
        $this->assertSame($key, BucketUsia::key($umur));
    }

    public function testSeriUrutNaikDanLengkap(): void
    {
        $seri = BucketUsia::seri();

        $this->assertSame(['le30', '31_40', '41_45', '46_50', '51_56', '57_60', 'gt60'], array_column($seri, 'key'));
        $this->assertSame(['≤ 30', '31–40', '41–45', '46–50', '51–56', '57–60', '> 60'], array_column($seri, 'label'));
    }
}
