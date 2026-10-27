<?php

declare(strict_types=1);

namespace Tests\Unit\Layanan\Cuti;

use App\Libraries\Layanan\Cuti\AturanCuti;
use App\Libraries\Layanan\Cuti\SaldoCuti;
use App\Libraries\Layanan\Cuti\SaldoTahunanCuti;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * MAKE-022 M3 — rumus saldo tahun baru PRD Fase 4 v1.3 §6.3.6 (Q-02): boundary pool 5/6/7/12/13, utang 0/6/12/>12,
 * tanpa baris tahun lalu, ditangguhkan, invarian batas D8, dan paritas terhadap `calculate_yearly_sc_2` (tabel nilai
 * hasil hitung tangan dari `Cron.php:2534-2663`).
 *
 * @internal
 */
final class SaldoTahunanCutiTest extends CIUnitTestCase
{
    /**
     * @return iterable<string, array{array{int, int, int, int, int}, array{int, int, int}}>
     */
    public static function kasus(): iterable
    {
        // Lalu (N, N-1, N-2, utang, ditangguhkan) → baru (N, N-1, N-2); utang dan ditangguhkan baru selalu 0.
        yield 'pool 5: N lalu 5' => [[5, 0, 0, 0, 0], [12, 5, 0]];
        yield 'pool 6: N lalu 6' => [[6, 0, 0, 0, 0], [12, 6, 0]];
        yield 'pool 7: N lalu 7 dibatasi 6 → 6' => [[7, 0, 0, 0, 0], [12, 6, 0]];
        yield 'pool 7: N lalu 6 + N-1 lalu 1' => [[6, 1, 0, 0, 0], [12, 6, 1]];
        yield 'pool 12: N lalu 6 + N-1 lalu 6' => [[6, 6, 0, 0, 0], [12, 6, 6]];
        yield 'pool 13 → dibatasi 12' => [[6, 6, 0, 0, 1], [12, 6, 6]];
        yield 'N lalu 12 hanya 6 dibawa' => [[12, 0, 0, 0, 0], [12, 6, 0]];
        yield 'N-2 lalu kedaluwarsa' => [[0, 0, 6, 0, 0], [12, 0, 0]];
        yield 'asal-usul hilang: N lalu 2, N-1 lalu 6 → N-1 6, N-2 2' => [[2, 6, 0, 0, 0], [12, 6, 2]];
        yield 'ditangguhkan masuk pool' => [[0, 0, 0, 0, 4], [12, 4, 0]];
        yield 'ditangguhkan + N lalu' => [[3, 0, 0, 0, 5], [12, 6, 2]];
        yield 'utang 0' => [[0, 0, 0, 0, 0], [12, 0, 0]];
        yield 'utang 6' => [[0, 0, 0, 6, 0], [6, 0, 0]];
        yield 'utang 12' => [[0, 0, 0, 12, 0], [0, 0, 0]];
        yield 'utang > 12 → lantai N = 0 (perapian DQ-23)' => [[0, 0, 0, 15, 0], [0, 0, 0]];
        yield 'utang 3 dengan pool' => [[6, 6, 0, 3, 0], [9, 6, 6]];
        yield 'saldo impor di atas D8 tetap dibatasi' => [[12, 9, 6, 0, 0], [12, 6, 6]];
        yield 'semua nol' => [[0, 0, 0, 0, 0], [12, 0, 0]];
    }

    /**
     * @param array{int, int, int, int, int} $lalu
     * @param array{int, int, int}            $baru
     */
    #[DataProvider('kasus')]
    public function testRumusSaldoTahunBaru(array $lalu, array $baru): void
    {
        $hasil = SaldoTahunanCuti::bentuk(new SaldoCuti(...$lalu), AturanCuti::bawaan());

        $this->assertSame($baru, [$hasil->n, $hasil->n1, $hasil->n2]);
        $this->assertSame(0, $hasil->utang);
        $this->assertSame(0, $hasil->ditangguhkan);
    }

    public function testTanpaBarisTahunLaluPoolNolDanNPenuh(): void
    {
        $hasil = SaldoTahunanCuti::tanpaTahunLalu(AturanCuti::bawaan());

        $this->assertTrue((new SaldoCuti(12, 0, 0, 0, 0))->sama($hasil));
    }

    public function testInvarianBatasD8PadaHimpunanNilaiLuas(): void
    {
        $aturan = AturanCuti::bawaan();
        $nilai  = [0, 1, 5, 6, 7, 11, 12, 13, 20, 127];

        foreach ($nilai as $n) {
            foreach ($nilai as $n1) {
                foreach ($nilai as $utang) {
                    foreach ([0, 4, 12, 30] as $ditangguhkan) {
                        $h = SaldoTahunanCuti::bentuk(new SaldoCuti($n, $n1, 3, $utang, $ditangguhkan), $aturan);

                        $this->assertSame([], $h->periksaBatas($aturan), json_encode([$n, $n1, $utang, $ditangguhkan]));
                        $this->assertLessThanOrEqual($aturan->maksTotal, $h->total());
                        $this->assertGreaterThanOrEqual(0, $h->n);
                    }
                }
            }
        }
    }

    public function testParitasTerhadapCalculateYearlyScDuaUntukSaldoTernormalisasi(): void
    {
        // Hitung tangan dari Cron.php:2534-2663 (calculate_yearly_sc_2) untuk saldo bentuk legacy, utang <= 12.
        $tabel = [
            [[12, 6, 6, 0, 0], [12, 6, 6]],
            [[12, 3, 0, 0, 0], [12, 6, 3]],
            [[8, 0, 0, 4, 0], [8, 6, 0]],
            [[0, 0, 0, 12, 12], [0, 6, 6]],
        ];

        foreach ($tabel as [$lalu, $baru]) {
            $h = SaldoTahunanCuti::bentuk(new SaldoCuti(...$lalu), AturanCuti::bawaan());

            $this->assertSame($baru, [$h->n, $h->n1, $h->n2], json_encode($lalu));
        }
    }
}
