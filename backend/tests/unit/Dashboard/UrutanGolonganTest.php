<?php

declare(strict_types=1);

namespace Tests\Unit\Dashboard;

use App\Libraries\Dashboard\Statistik\UrutanGolongan;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * F7-7 seri golongan (MAKE-036): urut `pangkat.order` (bukan string), "CPNS III/a" digabung ke "III/a", kolom PPPK
 * setelah seluruh kolom PNS.
 *
 * @internal
 */
final class UrutanGolonganTest extends CIUnitTestCase
{
    public function testPnsUrutOrderBukanString(): void
    {
        // Urutan string: "I/a" < "II/a" < "IV/a" < "IX"… ; urutan order: I/a (1) → II/a (5) → IV/a (13).
        $hasil = UrutanGolongan::susun([
            ['id_pangkat' => 13, 'gol_ruang' => 'IV/a', 'order' => 13],
            ['id_pangkat' => 1, 'gol_ruang' => 'I/a', 'order' => 1],
            ['id_pangkat' => 5, 'gol_ruang' => 'II/a', 'order' => 5],
        ], []);

        $this->assertSame(['I/a', 'II/a', 'IV/a'], array_column($hasil['seri'], 'label'));
        $this->assertSame(['pns_i_a', 'pns_ii_a', 'pns_iv_a'], array_column($hasil['seri'], 'key'));
        $this->assertSame(['p13' => 'pns_iv_a', 'p1' => 'pns_i_a', 'p5' => 'pns_ii_a'], $hasil['peta']);
    }

    public function testCpnsDigabungKeGolRuangPns(): void
    {
        $hasil = UrutanGolongan::susun([
            ['id_pangkat' => 9, 'gol_ruang' => 'III/a', 'order' => 9],
            ['id_pangkat' => 29, 'gol_ruang' => 'CPNS III/a', 'order' => 29],
            ['id_pangkat' => 30, 'gol_ruang' => 'cpns  III/b', 'order' => 30],
        ], []);

        $this->assertSame(['III/a', 'III/b'], array_column($hasil['seri'], 'label'));
        $this->assertSame('pns_iii_a', $hasil['peta']['p9']);
        $this->assertSame('pns_iii_a', $hasil['peta']['p29'], 'CPNS III/a dipetakan ke kolom III/a');
        $this->assertSame('pns_iii_b', $hasil['peta']['p30']);
    }

    public function testPppkSetelahPnsUrutOrderSendiri(): void
    {
        $hasil = UrutanGolongan::susun(
            [['id_pangkat' => 13, 'gol_ruang' => 'IV/a', 'order' => 13]],
            [
                ['id_gol_pppk' => 7, 'gol_pppk' => 'VII', 'order' => 7],
                ['id_gol_pppk' => 5, 'gol_pppk' => 'V', 'order' => 5],
            ],
        );

        $this->assertSame(['IV/a', 'V', 'VII'], array_column($hasil['seri'], 'label'));
        $this->assertSame(['pns_iv_a', 'pppk_v', 'pppk_vii'], array_column($hasil['seri'], 'key'));
        $this->assertSame('pppk_v', $hasil['peta']['k5']);
    }

    public function testKosong(): void
    {
        $this->assertSame(['seri' => [], 'peta' => []], UrutanGolongan::susun([], []));
    }

    public function testTanpaCpns(): void
    {
        $this->assertSame('III/a', UrutanGolongan::tanpaCpns('CPNS III/a'));
        $this->assertSame('III/a', UrutanGolongan::tanpaCpns(' III/a '));
        $this->assertSame('CPNSIII/a', UrutanGolongan::tanpaCpns('CPNSIII/a'), 'hanya prefiks diikuti spasi yang dibuang');
    }
}
