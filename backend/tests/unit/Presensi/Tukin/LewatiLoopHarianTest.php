<?php

declare(strict_types=1);

namespace Tests\Unit\Presensi\Tukin;

use App\Libraries\Presensi\Tukin\DataPegawaiTukin;
use App\Libraries\Presensi\Tukin\HariKerja;
use App\Libraries\Presensi\Tukin\KalkulasiTukin;
use App\Libraries\Presensi\Tukin\KonfigurasiTukin;
use App\Libraries\Presensi\Tukin\PeriodeTukin;
use App\Libraries\Presensi\TukinData\SumberDataPegawaiTukin;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * CR-083 T-02 [K, keputusan Horii 29-10]: CPNS tanpa `tmtsk_pangkat` → legacy `new DateTime('')` = saat ekspor
 * (`L_presensi.php:12703-12705`); `$dt < $dtTmtPegBaru` (:12739-12743) benar untuk setiap hari loop yang dipotong ke hari
 * ini (:11881-11885), sehingga seluruh hari loop dilewati tanpa potongan, berapa pun jam acuannya. Cek cuti sepanjang
 * periode tetap atas semua hari kerja periode (legacy :12667 hanya melewati hari sebelum `tmtsk_pangkat` yang tidak
 * kosong). PPPK tanpa TMT sama (keputusan Horii 29-10 malam). Jalur lain (CPNS ber-TMT, PPPK ber-TMT, PNS) tidak berubah.
 *
 * @internal
 */
final class LewatiLoopHarianTest extends CIUnitTestCase
{
    private PeriodeTukin $periode;

    protected function setUp(): void
    {
        parent::setUp();
        $this->periode = new PeriodeTukin('2026-08-16', '2026-09-15'); // 22 hari kerja
    }

    public function testCpnsTanpaTmtMelewatiSeluruhLoopBerapaPunHariIni(): void
    {
        $pegawai = new DataPegawaiTukin('baik', lewatiLoopHarian: true);

        foreach (['tengah periode' => '2026-09-01', 'setelah periode' => '2026-10-27', 'tanpa pemotongan' => null] as $kasus => $hariIni) {
            $h = KalkulasiTukin::hitung($this->periode, [], [], $this->config(), $hariIni, $pegawai); // tanpa presensi sama sekali

            $this->assertSame(0, $h->totalPotongan, $kasus);
            $this->assertSame(0, $h->jumlah['TK'], $kasus);
            $this->assertSame(['di_luar_masa_kerja'], array_values(array_unique(array_map(static fn ($x): string => $x->status, $h->harian))), $kasus);
        }

        $biasa = KalkulasiTukin::hitung($this->periode, [], [], $this->config(), '2026-10-27', new DataPegawaiTukin('baik'));
        $this->assertSame(22, $biasa->jumlah['TK'], 'pembanding: tanpa tanda semua hari kerja TK');
    }

    public function testCutiSepanjangPeriodeTetapAtasSemuaHariKerja(): void
    {
        $cutiSemua = array_fill_keys(HariKerja::daftar($this->periode, []), ['cuti' => ['jenis' => 'tahunan']]);
        $pegawai   = new DataPegawaiTukin(null, lewatiLoopHarian: true); // tanpa SKP → potongan SKP bila bukan cuti sepanjang periode

        $semua = KalkulasiTukin::hitung($this->periode, [], $cutiSemua, $this->config(), '2026-09-01', $pegawai);
        $this->assertSame('cuti_sepanjang_periode', $semua->alasan);
        $this->assertSame(0, $semua->totalPotongan);

        // Satu hari kerja setelah hari ini tanpa cuti → bukan cuti sepanjang periode (hari setelah "saat ekspor" tetap diperiksa).
        unset($cutiSemua['2026-09-15']);
        $sebagian = KalkulasiTukin::hitung($this->periode, [], $cutiSemua, $this->config(), '2026-09-01', $pegawai);
        $this->assertNull($sebagian->alasan);
        $this->assertSame(0, $sebagian->potonganPresensi);
        $this->assertSame($this->config()->tarif('SKP_TIDAK_ADA'), $sebagian->totalPotongan, 'hanya potongan SKP');
    }

    public function testAturanPegawaiBaru(): void
    {
        $libur = [];

        $this->assertSame(['tmtMasuk' => null, 'lewatiLoopHarian' => true], SumberDataPegawaiTukin::pegawaiBaru('CPNS III/a', '', false, null, $this->periode, $libur), 'T-02');
        $this->assertSame(['tmtMasuk' => '2026-08-01', 'lewatiLoopHarian' => false], SumberDataPegawaiTukin::pegawaiBaru('CPNS III/a', '2026-08-01', false, null, $this->periode, $libur), 'CPNS ber-TMT');
        $this->assertSame(['tmtMasuk' => null, 'lewatiLoopHarian' => false], SumberDataPegawaiTukin::pegawaiBaru('CPNS', '', false, null, $this->periode, $libur), 'gol_ruang ≤ 5 karakter bukan CPNS');
        $this->assertSame(['tmtMasuk' => null, 'lewatiLoopHarian' => false], SumberDataPegawaiTukin::pegawaiBaru('III/a', '', false, null, $this->periode, $libur), 'PNS tanpa TMT');
        $this->assertSame(['tmtMasuk' => '2026-08-25', 'lewatiLoopHarian' => false], SumberDataPegawaiTukin::pegawaiBaru('', '', true, ['gol_pppk' => 'V', 'tmtsk' => '2026-08-25'], $this->periode, $libur), 'PPPK TMT setelah hari kerja pertama');
        $this->assertSame(['tmtMasuk' => null, 'lewatiLoopHarian' => false], SumberDataPegawaiTukin::pegawaiBaru('', '', true, ['gol_pppk' => 'V', 'tmtsk' => '2026-08-17'], $this->periode, $libur), 'PPPK TMT = hari kerja pertama (17 Agu)');
        $this->assertSame(['tmtMasuk' => null, 'lewatiLoopHarian' => false], SumberDataPegawaiTukin::pegawaiBaru('', '', true, ['gol_pppk' => 'V', 'tmtsk' => '2025-01-02'], $this->periode, $libur), 'PPPK TMT sebelum hari kerja pertama');
        $this->assertSame(['tmtMasuk' => null, 'lewatiLoopHarian' => false], SumberDataPegawaiTukin::pegawaiBaru('IX', '2024-01-01', true, null, $this->periode, $libur), 'PPPK tanpa jabatan, TMT dari riwayat_kp');
    }

    /**
     * CR-083, keputusan Horii 29-10 malam [K]: PPPK tanpa TMT (`riwayat_mutasi_jabatan` maupun `riwayat_kp` tidak memberi
     * TMT) → legacy `new DateTime('')` = saat ekspor > hari kerja pertama (`:12707-12710`) → seluruh loop dilewati.
     * Periode tanpa hari kerja: `$firstDayHK_CM` kosong juga = saat ekspor → tanda tidak dipasang (tanpa hari kerja,
     * hasilnya sama).
     */
    public function testPppkTanpaTmtMelewatiSeluruhLoop(): void
    {
        $this->assertSame(['tmtMasuk' => null, 'lewatiLoopHarian' => true], SumberDataPegawaiTukin::pegawaiBaru('', '', true, null, $this->periode, []));
        $this->assertSame(['tmtMasuk' => null, 'lewatiLoopHarian' => true], SumberDataPegawaiTukin::pegawaiBaru('IX', '', true, null, $this->periode, []), 'gol ada, TMT kosong');
        $this->assertSame(['tmtMasuk' => null, 'lewatiLoopHarian' => false], SumberDataPegawaiTukin::pegawaiBaru('', '', false, null, $this->periode, []), 'bukan PPPK (PNS) tanpa TMT tetap');

        $akhirPekan = new PeriodeTukin('2026-08-22', '2026-08-23');
        $this->assertSame(['tmtMasuk' => null, 'lewatiLoopHarian' => false], SumberDataPegawaiTukin::pegawaiBaru('', '', true, null, $akhirPekan, []), 'periode tanpa hari kerja');

        $baru = SumberDataPegawaiTukin::pegawaiBaru('', '', true, null, $this->periode, []);
        $h    = KalkulasiTukin::hitung($this->periode, [], [], $this->config(), '2026-10-27', new DataPegawaiTukin('baik', tmtMasuk: $baru['tmtMasuk'], lewatiLoopHarian: $baru['lewatiLoopHarian']));
        $this->assertSame([0, 0], [$h->jumlah['TK'], $h->totalPotongan], 'tanpa presensi pun tanpa potongan');
    }

    private function config(): KonfigurasiTukin
    {
        return new KonfigurasiTukin(['TL1/PSW1' => '0.5', 'TL2/PSW2' => 1, 'TL3/PSW3' => '1.25', 'TA' => '1,5', 'TK' => 3]);
    }
}
