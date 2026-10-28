<?php

declare(strict_types=1);

namespace Tests\Unit\Presensi\Rekap;

use App\Libraries\Presensi\Rekap\HariUangMakan;
use CodeIgniter\Test\CIUnitTestCase;
use InvalidArgumentException;

/**
 * PRD 5B §7.2.2 AC-D09a.2 (MAKE-040) — aturan hari uang makan murni, satu test per butir [K `Lsl_gaji.php:260-383`
 * `0cf7e67`]: libur/akhir pekan; TB 25% + penanda melekat sampai akhir bulan; TB mulai setelah tanggal 1 → penanda tidak
 * aktif; perpanjangan memakai tanggal mulai TB induk; cuti jenis apa pun; konket kategori 13 dibayar vs kategori lain
 * tidak; presensi hanya masuk / hanya pulang dibayar; tanpa jam tidak dibayar; dipotong hari ini.
 *
 * Oktober 2026: 1 = Kamis, 3–4 akhir pekan, 5 = Senin.
 *
 * @internal
 */
final class HariUangMakanTest extends CIUnitTestCase
{
    public function testLiburDanAkhirPekanDilewati(): void
    {
        $presensi = $this->hadir(['2026-10-01', '2026-10-02', '2026-10-03', '2026-10-04', '2026-10-05']);

        $hasil = HariUangMakan::hariDibayar(2026, 10, null, ['2026-10-02'], [], [], [], $presensi);

        $this->assertSame(['2026-10-01', '2026-10-05'], $hasil, 'libur 2 Okt, Sabtu & Minggu dilewati walau ada presensi');
    }

    public function testPresensiHanyaMasukAtauHanyaPulangDibayarTanpaJamTidak(): void
    {
        $presensi = [
            '2026-10-01' => ['masuk' => '07:30:00', 'pulang' => null],
            '2026-10-02' => ['masuk' => null, 'pulang' => '16:00:00'],
            '2026-10-05' => ['masuk' => null, 'pulang' => null],
            '2026-10-06' => ['masuk' => '', 'pulang' => ''],
        ];

        $this->assertSame(['2026-10-01', '2026-10-02'], HariUangMakan::hariDibayar(2026, 10, null, [], [], [], [], $presensi));
    }

    public function testCutiJenisApaPunDilewati(): void
    {
        $presensi = $this->hadir(['2026-10-01', '2026-10-02', '2026-10-05']);

        $hasil = HariUangMakan::hariDibayar(2026, 10, null, [], [['2026-09-28', '2026-10-01'], ['2026-10-05', '2026-10-05']], [], [], $presensi);

        $this->assertSame(['2026-10-02'], $hasil);
    }

    public function testKonketKategori13DibayarKategoriLainTidak(): void
    {
        $konket = [
            ['mulai' => '2026-10-01 00:00:00', 'akhir' => '2026-10-01 23:59:59', 'affect_tukin' => '1', 'kategori' => '13'],
            ['mulai' => '2026-10-02', 'akhir' => '2026-10-02', 'affect_tukin' => 1, 'kategori' => 8],
            // affect_tukin 0: diperlakukan sebagai hari biasa (presensi menentukan).
            ['mulai' => '2026-10-05', 'akhir' => '2026-10-05', 'affect_tukin' => 0, 'kategori' => 13],
        ];
        $presensi = $this->hadir(['2026-10-02', '2026-10-05']);

        $this->assertSame(['2026-10-01', '2026-10-05'], HariUangMakan::hariDibayar(2026, 10, null, [], [], [], $konket, $presensi));
    }

    public function testKonketTumpangTindihBarisTerakhirMenang(): void
    {
        $konket = [
            ['mulai' => '2026-10-01', 'akhir' => '2026-10-02', 'affect_tukin' => 1, 'kategori' => 13],
            ['mulai' => '2026-10-02', 'akhir' => '2026-10-02', 'affect_tukin' => 1, 'kategori' => 8],
        ];

        $this->assertSame(['2026-10-01'], HariUangMakan::hariDibayar(2026, 10, null, [], [], [], $konket, []));
    }

    public function testTbDuaPuluhLimaPersenPenandaMelekatSampaiAkhirBulan(): void
    {
        // TB mulai sebelum Oktober (potongan 25) berakhir 2 Okt: hari kerja sesudahnya tetap tidak dibayar (penanda melekat).
        $tb       = [['id' => 1, 'mulai_induk' => '2026-08-01', 'dari' => '2026-08-01', 'sampai' => '2026-10-02']];
        $presensi = $this->hadir(['2026-10-05', '2026-10-06', '2026-10-30']);

        $this->assertSame([], HariUangMakan::hariDibayar(2026, 10, null, [], [], $tb, [], $presensi));
        $this->assertSame(25, HariUangMakan::potonganTb('2026-08-01', 2026, 10));
    }

    public function testTbMulaiSetelahTanggalSatuPenandaTidakAktif(): void
    {
        // TB mulai 6 Okt (potongan 0 di Oktober): hari TB dilewati, tetapi tidak ada penanda → hari sesudahnya dibayar.
        $tb       = [['id' => 7, 'mulai_induk' => '2026-10-06', 'dari' => '2026-10-06', 'sampai' => '2026-10-07']];
        $presensi = $this->hadir(['2026-10-05', '2026-10-06', '2026-10-08']);

        $this->assertSame(['2026-10-05', '2026-10-08'], HariUangMakan::hariDibayar(2026, 10, null, [], [], $tb, [], $presensi));
        $this->assertSame(0, HariUangMakan::potonganTb('2026-10-06', 2026, 10));
        $this->assertSame(25, HariUangMakan::potonganTb('2026-10-01', 2026, 10), 'mulai tepat tanggal 1 = 25');
    }

    public function testPerpanjanganMemakaiTanggalMulaiTbInduk(): void
    {
        // Induk mulai 15 Okt (0 di Oktober); perpanjangan November memakai mulai induk → November 25 → penanda aktif.
        $tb = [
            ['id' => 3, 'mulai_induk' => '2026-10-15', 'dari' => '2026-10-15', 'sampai' => '2026-10-31'],
            ['id' => 3, 'mulai_induk' => '2026-10-15', 'dari' => '2026-11-02', 'sampai' => '2026-11-03'],
        ];
        $presensi = $this->hadir(['2026-11-04', '2026-11-05']);

        $this->assertSame([], HariUangMakan::hariDibayar(2026, 11, null, [], [], $tb, [], $presensi));
    }

    public function testDipotongHariIniDanBulanDepanKosong(): void
    {
        $presensi = $this->hadir(['2026-10-01', '2026-10-02', '2026-10-05']);

        $this->assertSame(['2026-10-01', '2026-10-02'], HariUangMakan::hariDibayar(2026, 10, '2026-10-02', [], [], [], [], $presensi));
        $this->assertSame([], HariUangMakan::hariDibayar(2026, 11, '2026-10-31', [], [], [], [], $presensi), 'bulan belum dimulai');
    }

    public function testBulanTidakSahDitolak(): void
    {
        $this->expectException(InvalidArgumentException::class);
        HariUangMakan::hariDibayar(2026, 13, null, [], [], [], [], []);
    }

    /**
     * @param list<string> $tanggal
     *
     * @return array<string, array{masuk: string, pulang: string}>
     */
    private function hadir(array $tanggal): array
    {
        return array_fill_keys($tanggal, ['masuk' => '07:30:00', 'pulang' => '16:00:00']);
    }
}
