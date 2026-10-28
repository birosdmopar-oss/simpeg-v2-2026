<?php

declare(strict_types=1);

namespace Tests\Unit\Presensi\Rekap;

use App\Libraries\Presensi\Rekap\JadwalKerjaRekap;
use App\Libraries\Presensi\Rekap\KlasifikasiKehadiran;
use CodeIgniter\Test\CIUnitTestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * PRD 5B §7.4.1 AC-D09b.5 (MAKE-042): satu test per cabang klasifikasi (TB, cuti, konket 8/9/10/13/lain, TK, hadir) +
 * boundary TL/PSW 30/60 dengan jam `web_config` (normal & puasa, Jumat & bukan Jumat). September 2026: 1 Sep = Selasa,
 * 4/11/18/25 Sep = Jumat; puasa 21–25 Sep.
 *
 * @internal
 */
final class KlasifikasiKehadiranTest extends CIUnitTestCase
{
    public function testCabangKlasifikasiDanKolomRingkas(): void
    {
        $hasil = $this->rekap(
            libur: ['2026-09-16'],
            cuti: [['2026-09-02', '2026-09-02']],
            tb: [['2026-09-03', '2026-09-03']],
            konket: [
                ['mulai' => '2026-09-07 00:00:00', 'akhir' => '2026-09-07 23:59:59', 'kategori' => 8],
                ['mulai' => '2026-09-08 00:00:00', 'akhir' => '2026-09-08 23:59:59', 'kategori' => 9],
                ['mulai' => '2026-09-09 00:00:00', 'akhir' => '2026-09-09 23:59:59', 'kategori' => 10],
                ['mulai' => '2026-09-10 00:00:00', 'akhir' => '2026-09-10 23:59:59', 'kategori' => 13],
                ['mulai' => '2026-09-11 00:00:00', 'akhir' => '2026-09-11 23:59:59', 'kategori' => 5],
            ],
            presensi: [
                '2026-09-01' => ['masuk' => '07:30:00', 'pulang' => '16:00:00'],
                '2026-09-02' => ['masuk' => '07:30:00', 'pulang' => '16:00:00'], // cuti menang
                '2026-09-03' => ['masuk' => '07:30:00', 'pulang' => '16:00:00'], // TB menang
                '2026-09-10' => ['masuk' => null, 'pulang' => null],
                '2026-09-14' => ['masuk' => null, 'pulang' => null],
                '2026-09-16' => ['masuk' => '07:30:00', 'pulang' => '16:00:00'], // libur
            ],
            hariIni: '2026-09-16',
        );

        $this->assertSame([
            '2026-09-01' => 'H', '2026-09-02' => 'CT', '2026-09-03' => 'TB', '2026-09-04' => 'TK', '2026-09-05' => 'weekend',
            '2026-09-06' => 'weekend', '2026-09-07' => 'DL', '2026-09-08' => 'CT', '2026-09-09' => 'SKT', '2026-09-10' => 'LL',
            '2026-09-11' => null, '2026-09-12' => 'weekend', '2026-09-13' => 'weekend', '2026-09-14' => 'TK', '2026-09-15' => 'TK',
            '2026-09-16' => 'libur',
        ], $hasil['harian'], 'dipotong hari ini 16 Sep');

        $this->assertSame([
            'hari_kerja' => 11, 'hadir' => 2, 'tl' => 0, 'psw' => 0, 'tk' => 3, 'sakit' => 1, 'dinas_luar' => 1,
            'lain_lain'  => 1, 'cuti' => 2, 'tidak_hadir' => 7, 'tb' => 1,
        ], $hasil['ringkas'], 'LL = hadir; kategori lain tidak dihitung; tidak_hadir = cuti+DL+sakit+TK, tanpa TB');
    }

    public function testKonketTumpangTindihBarisTerakhirDanAkhirPekanMendahuluiLibur(): void
    {
        $hasil = $this->rekap(
            libur: ['2026-09-05'],
            konket: [
                ['mulai' => '2026-09-01', 'akhir' => '2026-09-02', 'kategori' => 8],
                ['mulai' => '2026-09-02', 'akhir' => '2026-09-02', 'kategori' => 10],
            ],
            hariIni: '2026-09-05',
        );

        $this->assertSame(['DL', 'SKT', 'TK', 'TK', 'weekend'], array_values($hasil['harian']));
    }

    /**
     * @return iterable<string, array{0: string, 1: ?string, 2: ?string, 3: int, 4: int}>
     */
    public static function batasTlPsw(): iterable
    {
        // Normal bukan Jumat (07:30–16:00).
        yield 'tepat waktu' => ['2026-09-01', '07:30:00', '16:00:00', 0, 0];
        yield 'tl 1 menit tanpa kompensasi' => ['2026-09-01', '07:31:00', '16:00:00', 1, 0];
        yield 'tl 30 kompensasi 30' => ['2026-09-01', '08:00:00', '16:30:00', 0, 0];
        yield 'tl 30 kompensasi 29' => ['2026-09-01', '08:00:00', '16:29:00', 1, 0];
        yield 'tl 31 kompensasi 59' => ['2026-09-01', '08:01:00', '16:59:00', 1, 0];
        yield 'tl 60 kompensasi 60' => ['2026-09-01', '08:30:00', '17:00:00', 0, 0];
        yield 'tl 61 kompensasi 120' => ['2026-09-01', '08:31:00', '18:00:00', 1, 0];
        yield 'detik dibuang' => ['2026-09-01', '07:30:59', '15:59:01', 0, 0];
        yield 'psw 1 menit' => ['2026-09-01', '07:30:00', '15:59:00', 0, 1];
        yield 'tl dan psw' => ['2026-09-01', '08:31:00', '15:00:00', 1, 1];
        // Normal Jumat (07:30–16:30).
        yield 'jumat pulang 16:00 psw' => ['2026-09-04', '07:30:00', '16:00:00', 0, 1];
        yield 'jumat tl 30 kompensasi 30' => ['2026-09-04', '08:00:00', '17:00:00', 0, 0];
        // Puasa bukan Jumat (08:00–15:00) dan Jumat (08:00–15:30).
        yield 'puasa tepat waktu' => ['2026-09-21', '08:00:00', '15:00:00', 0, 0];
        yield 'puasa tl 60 kompensasi 60' => ['2026-09-22', '09:00:00', '16:00:00', 0, 0];
        yield 'puasa tl 60 kompensasi 59' => ['2026-09-22', '09:00:00', '15:59:00', 1, 0];
        yield 'puasa jumat pulang 15:00 psw' => ['2026-09-25', '08:00:00', '15:00:00', 0, 1];
        yield 'puasa jumat 07:59 bukan tl' => ['2026-09-25', '07:59:00', '15:30:00', 0, 0];
        // Hanya satu jam terisi.
        yield 'hanya masuk tl tanpa kompensasi' => ['2026-09-01', '07:45:00', null, 1, 0];
        yield 'hanya masuk tepat' => ['2026-09-01', '07:30:00', null, 0, 0];
        yield 'hanya pulang psw' => ['2026-09-01', null, '15:30:00', 0, 1];
        yield 'hanya pulang tepat' => ['2026-09-01', null, '16:00', 0, 0];
    }

    #[DataProvider('batasTlPsw')]
    public function testBatasTlPswDenganJamWebConfig(string $tgl, ?string $masuk, ?string $pulang, int $tl, int $psw): void
    {
        $hasil = $this->rekap(presensi: [$tgl => ['masuk' => $masuk, 'pulang' => $pulang]], hariIni: $tgl);

        $this->assertSame('H', $hasil['harian'][$tgl]);
        $this->assertSame([1, $tl, $psw], [$hasil['ringkas']['hadir'], $hasil['ringkas']['tl'], $hasil['ringkas']['psw']]);
    }

    public function testBatasBulanDanHariIni(): void
    {
        $this->assertSame([], $this->rekap(hariIni: '2026-08-31')['harian'], 'bulan belum mulai');
        $this->assertCount(30, $this->rekap(hariIni: '2026-10-05')['harian'], 'bulan selesai: semua tanggal kalender');
        $this->assertSame(22, $this->rekap(hariIni: null)['ringkas']['hari_kerja'], 'tanpa batas hari ini');

        $this->expectException(InvalidArgumentException::class);
        KlasifikasiKehadiran::rekap(2026, 13, null, [], [], [], [], [], $this->jadwal());
    }

    public function testJadwalTidakSahDitolak(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new JadwalKerjaRekap('07:30', '16:00', '16:30', '08:00', '15:00', '15:30', '2026-09-21', null);
    }

    /**
     * @param list<string>                                                    $libur
     * @param list<array{0: string, 1: string}>                               $cuti
     * @param list<array{0: string, 1: string}>                               $tb
     * @param list<array{mulai: string, akhir: string, kategori: int|string}> $konket
     * @param array<string, array{masuk?: ?string, pulang?: ?string}>         $presensi
     *
     * @return array{ringkas: array<string, int>, harian: array<string, string|null>}
     */
    private function rekap(array $libur = [], array $cuti = [], array $tb = [], array $konket = [], array $presensi = [], ?string $hariIni = null): array
    {
        return KlasifikasiKehadiran::rekap(2026, 9, $hariIni, $libur, $cuti, $tb, $konket, $presensi, $this->jadwal());
    }

    private function jadwal(): JadwalKerjaRekap
    {
        return new JadwalKerjaRekap('07:30', '16:00', '16:30', '08:00', '15:00', '15:30', '2026-09-21', '2026-09-25');
    }
}
