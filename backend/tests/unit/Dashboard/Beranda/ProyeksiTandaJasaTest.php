<?php

declare(strict_types=1);

namespace Tests\Unit\Dashboard\Beranda;

use App\Libraries\Dashboard\Beranda\ProyeksiTandaJasa;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * MAKE-087 — port murni `L_employee::get_all_tj` (`L_employee.php:3471-3619`): tingkat X/XX/XXX dari lama bekerja per
 * 1 Agustus tahun acuan, tingkat yang sudah dimiliki menghentikan proyeksi, pembulatan ke 1 Agustus, luapan `+N year`.
 *
 * @internal
 */
final class ProyeksiTandaJasaTest extends CIUnitTestCase
{
    /**
     * @return iterable<string, array{0: string|null, 1: int, 2: list<int>, 3: array{tgl_penghargaan: string, tanda_jasa: string}|null}>
     */
    public static function kasus(): iterable
    {
        yield 'X: 10 tahun, Maret → Agustus tahun itu' => ['2016-03-01', 2026, [], ['tgl_penghargaan' => '2026-08-01', 'tanda_jasa' => 'Satyalancana Karya Satya X Tahun']];
        yield 'X: bulan > 8 → Agustus tahun berikutnya' => ['2015-10-15', 2026, [], ['tgl_penghargaan' => '2026-08-01', 'tanda_jasa' => 'Satyalancana Karya Satya X Tahun']];
        yield 'XX: 21 tahun, tanggal lampau tetap keluar' => ['2005-03-01', 2026, [], ['tgl_penghargaan' => '2025-08-01', 'tanda_jasa' => 'Satyalancana Karya Satya XX Tahun']];
        yield 'XXX: 30 tahun' => ['1996-07-31', 2026, [], ['tgl_penghargaan' => '2026-08-01', 'tanda_jasa' => 'Satyalancana Karya Satya XXX Tahun']];
        yield 'sudah punya X → tidak ada' => ['2016-03-01', 2026, [ProyeksiTandaJasa::ID_X], null];
        yield 'punya XX tidak menghentikan X' => ['2016-03-01', 2026, [ProyeksiTandaJasa::ID_XX], ['tgl_penghargaan' => '2026-08-01', 'tanda_jasa' => 'Satyalancana Karya Satya X Tahun']];
        yield '9 tahun → tidak ada' => ['2016-08-02', 2026, [], null];
        yield 'tepat 10 tahun di 1 Agustus' => ['2016-08-01', 2026, [], ['tgl_penghargaan' => '2026-08-01', 'tanda_jasa' => 'Satyalancana Karya Satya X Tahun']];
        yield '29 Februari + 10 tahun meluap ke Maret (strtotime legacy)' => ['2016-02-29', 2026, [], ['tgl_penghargaan' => '2026-08-01', 'tanda_jasa' => 'Satyalancana Karya Satya X Tahun']];
        yield 'tahun acuan depan menaikkan lama bekerja' => ['2016-09-01', 2027, [], ['tgl_penghargaan' => '2027-08-01', 'tanda_jasa' => 'Satyalancana Karya Satya X Tahun']];
        yield 'tanpa TMT CPNS' => [null, 2026, [], null];
        yield 'TMT CPNS tidak valid' => ['2016-02-30', 2026, [], null];
    }

    /**
     * @param list<int>                                                  $dimiliki
     * @param array{tgl_penghargaan: string, tanda_jasa: string}|null $harapan
     */
    #[DataProvider('kasus')]
    public function testBerikutnya(?string $cpns, int $acuan, array $dimiliki, ?array $harapan): void
    {
        $this->assertSame($harapan, ProyeksiTandaJasa::berikutnya($cpns, $acuan, $dimiliki));
    }
}
