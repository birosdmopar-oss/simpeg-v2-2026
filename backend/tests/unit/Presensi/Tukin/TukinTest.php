<?php

declare(strict_types=1);

namespace Tests\Unit\Presensi\Tukin;

use App\Libraries\Presensi\Tukin\HariKerja;
use App\Libraries\Presensi\Tukin\JamKerja;
use App\Libraries\Presensi\Tukin\KalkulasiTukin;
use App\Libraries\Presensi\Tukin\KonfigurasiTukin;
use App\Libraries\Presensi\Tukin\PeriodeTukin;
use App\Libraries\Presensi\Tukin\StatusHarian;
use CodeIgniter\Test\CIUnitTestCase;
use InvalidArgumentException;

final class TukinTest extends CIUnitTestCase
{
    private function config(): KonfigurasiTukin
    {
        return new KonfigurasiTukin(['TL1/PSW1' => 1, 'TL2/PSW2' => 2, 'TL3/PSW3' => 3, 'TA' => 4, 'TK' => 5, 'LKH' => 6]);
    }

    public function testPeriodeDanHariKerja(): void
    {
        $periode = PeriodeTukin::dariBulan(2026, 1);
        $this->assertSame('2025-12-16', $periode->awal);
        $this->assertSame('2026-01-15', $periode->akhir);
        $this->assertSame(['2026-01-02', '2026-01-05'], HariKerja::daftar(new PeriodeTukin('2026-01-01', '2026-01-05'), ['2026-01-01', '2026-01-03']));
        $this->assertSame('2026-02-28', PeriodeTukin::dariBulan(2026, 2, ['awal' => '2026-02-01', 'akhir' => '2026-02-28'])->akhir);
    }

    public function testJamNormalPuasaDanJumat(): void
    {
        $config = new KonfigurasiTukin($this->config()->tarif, puasaMulai: '2026-03-01', puasaSelesai: '2026-03-31');
        $this->assertSame(['masuk' => '07:30', 'pulang' => '16:30'], JamKerja::untuk('2026-02-27', $config));
        $this->assertSame(['masuk' => '08:00', 'pulang' => '15:30'], JamKerja::untuk('2026-03-06', $config));
        $this->assertSame(['masuk' => '08:00', 'pulang' => '15:00'], JamKerja::untuk('2026-03-09', $config));
    }

    /** @dataProvider batasProvider */
    public function testBatasTl(int $menit, float $tarif): void
    {
        $jam = sprintf('%02d:%02d', 7 + intdiv(30 + $menit, 60), (30 + $menit) % 60);
        $hasil = StatusHarian::evaluasi('2026-01-05', ['presensi' => ['masuk' => $jam, 'pulang' => '16:00']], $this->config());
        $this->assertSame($menit, $hasil->tlMenit);
        $this->assertSame($tarif, $hasil->potongan);
    }

    /** @return array<string, array{int, float}> */
    public static function batasProvider(): array
    {
        return ['m30' => [30, 1.0], 'm31' => [31, 2.0], 'm60' => [60, 2.0], 'm61' => [61, 3.0]];
    }

    public function testPrecedenceLimaKombinasi(): void
    {
        $this->assertSame('tugas_belajar', StatusHarian::evaluasi('2026-01-05', ['tugas_belajar' => true, 'cuti' => ['jenis' => 'sakit']], $this->config())->status);
        $this->assertSame('cuti', StatusHarian::evaluasi('2026-01-05', ['cuti' => ['jenis' => 'tahunan'], 'konket' => ['affect_tukin' => 1, 'kategori' => 13], 'libur' => true], $this->config())->status);
        $this->assertSame('konket', StatusHarian::evaluasi('2026-01-05', ['konket' => ['affect_tukin' => 1, 'kategori' => 13], 'libur' => true], $this->config())->status);
        $this->assertSame('libur', StatusHarian::evaluasi('2026-01-05', ['libur' => true, 'presensi' => ['masuk' => '07:30']], $this->config())->status);
        $this->assertSame('TK', StatusHarian::evaluasi('2026-01-05', [], $this->config())->status);
    }

    public function testCutiTpmTppDanTarif(): void
    {
        $this->assertSame(0.0, StatusHarian::evaluasi('2026-01-05', ['cuti' => ['jenis' => 'tahunan']], $this->config())->potongan);
        $this->assertSame(1.0, StatusHarian::evaluasi('2026-01-05', ['cuti' => ['jenis' => 'sakit', 'hari_sakit_berurutan' => 15]], $this->config())->potongan);
        $this->assertSame(40.0, StatusHarian::evaluasi('2026-01-05', ['cuti' => ['jenis' => 'besar', 'bulan_ke' => 1]], $this->config())->potongan);
        $this->assertSame('TPM', StatusHarian::evaluasi('2026-01-05', ['presensi' => ['pulang' => '16:00']], $this->config())->status);
        $this->assertSame('TPP', StatusHarian::evaluasi('2026-01-05', ['presensi' => ['masuk' => '07:30']], $this->config())->status);
        $data = ['2026-01-05' => ['presensi' => ['masuk' => '07:30', 'pulang' => '16:00']]];
        $periode = new PeriodeTukin('2026-01-05', '2026-01-05');
        $normal = KalkulasiTukin::hitung($periode, [], $data, $this->config());
        $ubahTarif = new KonfigurasiTukin(['TL1/PSW1' => 9, 'TL2/PSW2' => 2, 'TL3/PSW3' => 3, 'TA' => 4, 'TK' => 5, 'LKH' => 6]);
        $this->assertNotSame($normal->totalPotongan, KalkulasiTukin::hitung($periode, [], ['2026-01-05' => ['presensi' => ['masuk' => '08:00', 'pulang' => '16:00']]], $ubahTarif)->totalPotongan);
    }

    public function testValidasiKonfigurasi(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new KonfigurasiTukin([]);
    }
}
