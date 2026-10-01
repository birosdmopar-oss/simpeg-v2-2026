<?php

declare(strict_types=1);

namespace Tests\Unit\Presensi\SlipGaji;

use App\Libraries\Presensi\SlipGaji\RingkasanSlip;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * CR-027 (R-1..R-4) — ringkasan slip setia legacy: total = bersih GPP + bersih TK (rumus final menunggu Biro
 * Keuangan). Nominal karangan dalam rupiah, dikali 100 menjadi sen.
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

    public function testPeriksaKonsistensi(): void
    {
        // Data sintetis konsisten untuk GPP dan TK; bersih_2 = bersih_tk − pajak + tunj_pajak juga terpenuhi (0 − 0).
        $this->assertSame([], RingkasanSlip::periksaKonsistensi(self::slipSintetis()));

        $slip               = self::slipSintetis();
        $slip['bersih_gpp'] = 346_052_400;
        $slip['pajak']      = 10_000;

        $peringatan = RingkasanSlip::periksaKonsistensi($slip);

        $this->assertSame(['bersih_gpp_tidak_konsisten', 'bersih_2_tidak_konsisten'], array_column($peringatan, 'kode'));
        $this->assertSame(346_052_500, $peringatan[0]['harapan']);
        $this->assertSame(346_052_400, $peringatan[0]['nilai']);
        $this->assertSame('Gaji Bersih (GPP) 3.460.524 tidak sama dengan hasil hitung 3.460.525.', $peringatan[0]['pesan']);
    }
}
