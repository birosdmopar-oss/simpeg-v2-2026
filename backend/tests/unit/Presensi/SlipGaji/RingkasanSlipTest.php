<?php

declare(strict_types=1);

namespace Tests\Unit\Presensi\SlipGaji;

use App\Libraries\Presensi\SlipGaji\RingkasanSlip;
use CodeIgniter\Test\CIUnitTestCase;
use InvalidArgumentException;

/**
 * CR-027 (R-1..R-4) — ringkasan slip setia legacy: total = bersih GPP + bersih TK (terjawab dari legacy, CR-039;
 * legacy tidak punya cek invarian `bersih_*`). Nominal karangan dalam rupiah, dikali 100 menjadi sen.
 *
 * @internal
 */
final class RingkasanSlipTest extends CIUnitTestCase
{
    /**
     * @return array<string, int>
     */
    private static function slipSintetis(): array
    {
        $rupiah = [
            'gjpokok'    => 3_000_000,
            'tjistri'    => 300_000,
            'tjanak'     => 120_000,
            'tjupns'     => 175_000,
            'tjberas'    => 250_000,
            'pembul'     => 25,
            'potpfk10'   => 384_500,
            'bersih_gpp' => 3_460_525,
            'kotor'      => 4_000_000,
            'potongan'   => 200_000,
            'bersih_tk'  => 3_800_000,
            'bersih_2'   => 3_800_000,
        ];

        return array_map(static fn (int $r): int => $r * 100, $rupiah);
    }

    public function testHitungSetiaLegacy(): void
    {
        $this->assertSame([
            'jumlah_penghasilan_gpp' => 384_502_500,
            'jumlah_potongan_gpp'    => 38_450_000,
            'bersih_gpp'             => 346_052_500,
            'bersih_tk'              => 380_000_000,
            'bersih_2'               => 380_000_000,
            'total_penghasilan'      => 726_052_500,
        ], RingkasanSlip::hitung(self::slipSintetis()));
    }

    public function testBersih2TidakMasukTotal(): void
    {
        $slip             = self::slipSintetis();
        $slip['bersih_2'] = 123_456;

        $this->assertSame(726_052_500, RingkasanSlip::hitung($slip)['total_penghasilan']);
    }

    public function testFieldHilangDianggapNol(): void
    {
        $this->assertSame([
            'jumlah_penghasilan_gpp' => 0,
            'jumlah_potongan_gpp'    => 0,
            'bersih_gpp'             => 0,
            'bersih_tk'              => 5_000,
            'bersih_2'               => 0,
            'total_penghasilan'      => 5_000,
        ], RingkasanSlip::hitung(['bersih_tk' => 5_000]));
    }

    public function testNilaiBukanIntSenDitolak(): void
    {
        // Nilai mentah DB (`DECIMAL` sebagai teks) atau float wajib dikonversi lewat NominalGaji dulu, bukan dijumlah langsung.
        foreach (['4500000.00', 4_500_000.0] as $mentah) {
            $slip               = self::slipSintetis();
            $slip['bersih_gpp'] = $mentah;

            try {
                // Sengaja melanggar tipe untuk menguji penjaga runtime.
                RingkasanSlip::hitung($slip); // @phpstan-ignore argument.type
                $this->fail('Nilai bukan int sen harus ditolak: ' . var_export($mentah, true));
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('bersih_gpp', $e->getMessage());
            }
        }
    }
}
