<?php

declare(strict_types=1);

namespace Tests\Unit\Layanan\Tb;

use App\Libraries\Layanan\Tb\BebasPresensiTb;
use CodeIgniter\Test\CIUnitTestCase;
use InvalidArgumentException;

/**
 * MAKE-024 — `bebasPresensiPenuh` murni (PRD Fase 4 v1.1 §6.7.5, AC-07.3, D15): true di rentang TB disetujui (inklusif),
 * true di rentang perpanjangan (berganda), false di luar dan di celah TB–perpanjangan, false untuk TB status 0/2/3;
 * `rentangBebasPresensi` per periode; `status_penuh` turunan.
 *
 * @internal
 */
final class BebasPresensiTbTest extends CIUnitTestCase
{
    /**
     * TB disetujui 2024-02-01..2024-02-29 (kabisat) dengan dua perpanjangan; celah 2024-03-01..2024-03-04.
     *
     * @return list<array<string, mixed>>
     */
    private static function daftar(): array
    {
        return [
            ['status' => '1', 'tgl_mulai' => '2024-02-01', 'tgl_akhir' => '2024-02-29', 'perpanjangan' => [
                ['tgl_mulai_perpan' => '2024-03-05', 'tgl_akhir_perpan' => '2024-03-31'],
                ['tgl_mulai_perpan' => '2024-04-01', 'tgl_akhir_perpan' => '2024-04-10'],
            ]],
            ['status' => 0, 'tgl_mulai' => '2024-06-01', 'tgl_akhir' => '2024-06-30'],
            ['status' => 2, 'tgl_mulai' => '2024-07-01', 'tgl_akhir' => '2024-07-31'],
            ['status' => 3, 'tgl_mulai' => '2024-08-01', 'tgl_akhir' => '2024-08-31', 'perpanjangan' => [
                ['tgl_mulai_perpan' => '2024-09-01', 'tgl_akhir_perpan' => '2024-09-30'],
            ]],
        ];
    }

    public function testPredikatPerTanggal(): void
    {
        $tb = self::daftar();

        foreach (['2024-02-01', '2024-02-29', '2024-03-05', '2024-03-31', '2024-04-01', '2024-04-10'] as $t) {
            $this->assertTrue(BebasPresensiTb::bebasPresensiPenuh($tb, $t), "{$t} (ujung inklusif)");
        }

        foreach (['2024-01-31', '2024-03-01', '2024-03-04', '2024-04-11', '2024-06-15', '2024-07-15', '2024-08-15', '2024-09-15'] as $t) {
            $this->assertFalse(BebasPresensiTb::bebasPresensiPenuh($tb, $t), "{$t} (di luar / celah / status bukan 1)");
        }

        $this->assertFalse(BebasPresensiTb::bebasPresensiPenuh([], '2024-02-10'));
        $this->assertSame(1, BebasPresensiTb::statusPenuh($tb, '2024-03-10'));
        $this->assertSame(0, BebasPresensiTb::statusPenuh($tb, '2024-03-02'));
    }

    public function testRentangPerPeriode(): void
    {
        $tb = self::daftar();

        $this->assertSame(
            ['2024-02-28', '2024-02-29', '2024-03-05', '2024-03-06'],
            BebasPresensiTb::rentangBebasPresensi($tb, '2024-02-28', '2024-03-06'),
            'lintas bulan kabisat, celah dilewati',
        );
        $this->assertCount(27 + 10, BebasPresensiTb::rentangBebasPresensi($tb, '2024-03-01', '2024-04-30'));
        $this->assertSame([], BebasPresensiTb::rentangBebasPresensi($tb, '2024-06-01', '2024-06-30'));

        // Lintas tahun: TB 2024-12-20..2025-01-05.
        $lintas = [['status' => 1, 'tgl_mulai' => '2024-12-20', 'tgl_akhir' => '2025-01-05']];
        $this->assertSame(['2024-12-31', '2025-01-01'], BebasPresensiTb::rentangBebasPresensi($lintas, '2024-12-31', '2025-01-01'));
        $this->assertSame(['2025-01-01'], BebasPresensiTb::rentangBebasPresensi($lintas, '2025-01-01', '2025-01-01'));
    }

    public function testRentangBanyakSamaDenganTunggalPerNip(): void
    {
        $perNip = [
            '199001012020011001' => self::daftar(),
            '199001012020011002' => [['status' => 1, 'tgl_mulai' => '2024-03-30', 'tgl_akhir' => '2024-04-02']],
            '199001012020011003' => [['status' => 2, 'tgl_mulai' => '2024-03-01', 'tgl_akhir' => '2024-03-31']],
        ];
        $nip   = ['199001012020011001', '199001012020011002', '199001012020011003', '199001012020019999', '199001012020011001'];
        $hasil = BebasPresensiTb::rentangBebasPresensiBanyak($nip, $perNip, '2024-02-28', '2024-04-02');

        $this->assertSame(['199001012020011001', '199001012020011002', '199001012020011003', '199001012020019999'], array_map('strval', array_keys($hasil)), 'unik, urutan pertama muncul');

        foreach (array_unique($nip) as $n) {
            $this->assertSame(BebasPresensiTb::rentangBebasPresensi($perNip[$n] ?? [], '2024-02-28', '2024-04-02'), $hasil[$n], $n);
        }

        $this->assertSame([], $hasil['199001012020019999'], 'NIP tanpa data → kosong');
        $this->assertSame([], BebasPresensiTb::rentangBebasPresensiBanyak([], $perNip, '2024-02-28', '2024-04-02'));
    }

    public function testRentangBanyakMemvalidasiRentangWalauKosong(): void
    {
        $this->expectException(InvalidArgumentException::class);
        BebasPresensiTb::rentangBebasPresensiBanyak([], [], '2024-03-02', '2024-03-01');
    }

    public function testRentangTerbalikAtauTerlaluPanjangDitolak(): void
    {
        try {
            BebasPresensiTb::rentangBebasPresensi([], '2024-03-02', '2024-03-01');
            $this->fail('rentang terbalik');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame([], BebasPresensiTb::rentangBebasPresensi([], '2024-01-01', '2024-12-31'), '366 hari kabisat boleh');

        $this->expectException(InvalidArgumentException::class);
        BebasPresensiTb::rentangBebasPresensi([], '2024-01-01', '2025-01-01');
    }
}
