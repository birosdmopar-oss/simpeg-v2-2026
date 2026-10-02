<?php

declare(strict_types=1);

namespace Tests\Unit\Presensi\SlipGaji;

use App\Libraries\Presensi\SlipGaji\GerbangPeriode;
use App\Libraries\Presensi\SlipGaji\PeriodeSlip;
use CodeIgniter\Test\CIUnitTestCase;
use DateTime;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * CR-027 (G-1..G-5, D-11 DoD "tepat sebelum dan sesudah ambang") — gerbang waktu slip: rilis tanggal 4 pukul
 * 10:00:00 WIB pada bulan periode (inklusif), periode tertua Januari 2024, dan zona pembanding selalu Asia/Jakarta.
 *
 * @internal
 */
final class GerbangPeriodeTest extends CIUnitTestCase
{
    private static function wib(string $waktu): DateTimeImmutable
    {
        return new DateTimeImmutable($waktu, new DateTimeZone('Asia/Jakarta'));
    }

    /**
     * @return iterable<string, array{0: DateTimeImmutable, 1: bool}>
     */
    public static function batasRilisOktober2026(): iterable
    {
        yield 'WIB 09:59:59 belum' => [self::wib('2026-10-04 09:59:59'), false];

        yield 'WIB 09:59:59.999999 belum' => [self::wib('2026-10-04 09:59:59.999999'), false];

        yield 'WIB 10:00:00 rilis' => [self::wib('2026-10-04 10:00:00'), true];

        yield 'WIB 10:00:00.000001 rilis' => [self::wib('2026-10-04 10:00:00.000001'), true];

        yield 'UTC 02:59:59 belum' => [new DateTimeImmutable('2026-10-04T02:59:59Z'), false];

        yield 'UTC 03:00:00 rilis' => [new DateTimeImmutable('2026-10-04T03:00:00Z'), true];

        yield 'UTC 3 Okt 17:00 = 4 Okt 00:00 WIB belum' => [new DateTimeImmutable('2026-10-03T17:00:00Z'), false];

        yield 'WIB 3 Okt 23:59:59 belum' => [self::wib('2026-10-03 23:59:59'), false];

        yield 'WIT 11:59:59 belum' => [new DateTimeImmutable('2026-10-04 11:59:59', new DateTimeZone('Asia/Jayapura')), false];

        yield 'WIT 12:00:00 rilis' => [new DateTimeImmutable('2026-10-04 12:00:00', new DateTimeZone('Asia/Jayapura')), true];

        yield 'bulan berikutnya rilis' => [self::wib('2026-11-01 00:00:00'), true];
    }

    #[DataProvider('batasRilisOktober2026')]
    public function testBatasRilisInklusifDalamWib(DateTimeImmutable $sekarang, bool $rilis): void
    {
        $oktober = PeriodeSlip::buat(2026, 10);

        $this->assertSame($rilis, GerbangPeriode::sudahRilis($oktober, $sekarang));
        $this->assertSame($rilis, GerbangPeriode::periksa($oktober, $sekarang)->valid);
        $this->assertSame($rilis ? '2026-10' : '2026-09', GerbangPeriode::terbaru($sekarang)?->kunci());
    }

    public function testWaktuRilis(): void
    {
        $this->assertSame('2026-10-04 10:00:00', GerbangPeriode::waktuRilis(PeriodeSlip::buat(2026, 10)));
        $this->assertSame('2027-01-04 10:00:00', GerbangPeriode::waktuRilis(PeriodeSlip::buat(2027, 1)));
        $this->assertSame('Asia/Jakarta', GerbangPeriode::ZONA);
        $this->assertSame('2024-01', GerbangPeriode::periodeAwal()->kunci());
    }

    public function testLintasTahun(): void
    {
        $sebelum = GerbangPeriode::daftarTersedia(self::wib('2027-01-04 09:59:59'));
        $this->assertCount(36, $sebelum);
        $this->assertSame('2026-12', GerbangPeriode::terbaru(self::wib('2027-01-04 09:59:59'))?->kunci());
        $this->assertSame('2026-12', end($sebelum)->kunci());

        $sesudah = GerbangPeriode::daftarTersedia(self::wib('2027-01-04 10:00:00'));
        $this->assertCount(37, $sesudah);
        $this->assertSame('2027-01', GerbangPeriode::terbaru(self::wib('2027-01-04 10:00:00'))?->kunci());

        // 1 Januari 00:00 WIB = 31 Desember 17:00 UTC: tetap Desember tahun lalu sebagai terbaru.
        $this->assertSame('2026-12', GerbangPeriode::terbaru(new DateTimeImmutable('2026-12-31T17:00:00Z'))?->kunci());
    }

    public function testPeriodeAwalJanuari2024(): void
    {
        $this->assertSame([], GerbangPeriode::daftarTersedia(self::wib('2024-01-04 09:59:59')));
        $this->assertNull(GerbangPeriode::terbaru(self::wib('2024-01-04 09:59:59')));

        // Jam yang jauh sebelum periode awal (mis. jam server salah) → kosong, bukan exception periode < 1900.
        foreach (['2023-12-31 23:59:59', '1900-01-01 00:00:00', '1500-06-15 12:00:00'] as $waktu) {
            $this->assertNull(GerbangPeriode::terbaru(self::wib($waktu)), $waktu);
            $this->assertSame([], GerbangPeriode::daftarTersedia(self::wib($waktu)), $waktu);
        }

        $daftar = GerbangPeriode::daftarTersedia(self::wib('2024-01-04 10:00:00'));
        $this->assertCount(1, $daftar);
        $this->assertSame('2024-01', $daftar[0]->kunci());

        $this->assertTrue(GerbangPeriode::periksa(PeriodeSlip::buat(2024, 1), self::wib('2026-10-05 00:00:00'))->valid);

        $desember2023 = GerbangPeriode::periksa(PeriodeSlip::buat(2023, 12), self::wib('2026-10-05 00:00:00'));
        $this->assertFalse($desember2023->valid);
        $this->assertSame('periode_sebelum_awal', $desember2023->kode);
        $this->assertSame(['periode_awal' => '2024-01'], $desember2023->detail);
        $this->assertStringContainsString('Januari 2024', (string) $desember2023->pesan);
    }

    public function testPeriodeMasaDepanBelumRilis(): void
    {
        $hasil = GerbangPeriode::periksa(PeriodeSlip::buat(2026, 11), self::wib('2026-10-05 08:00:00'));

        $this->assertFalse($hasil->valid);
        $this->assertSame('periode_belum_rilis', $hasil->kode);
        $this->assertSame(['waktu_rilis' => '2026-11-04 10:00:00'], $hasil->detail);
        $this->assertSame(
            'Periode slip gaji belum tersedia. Slip November 2026 dapat dibuka mulai 4 November 2026 pukul 10:00 WIB.',
            $hasil->pesan,
        );

        $lolos = GerbangPeriode::periksa(PeriodeSlip::buat(2026, 9), self::wib('2026-10-05 08:00:00'));
        $this->assertTrue($lolos->valid);
        $this->assertSame('2026-09', $lolos->nilai instanceof PeriodeSlip ? $lolos->nilai->kunci() : null);
    }

    public function testDaftarUrutNaikDariJanuari2024SampaiTerbaru(): void
    {
        $sekarang = self::wib('2026-10-04 10:00:00');
        $daftar   = GerbangPeriode::daftarTersedia($sekarang);

        $this->assertCount(34, $daftar);
        $this->assertSame('2024-01', $daftar[0]->kunci());
        $this->assertSame(GerbangPeriode::terbaru($sekarang)?->kunci(), $daftar[33]->kunci());
        $this->assertSame('2026-10', $daftar[33]->kunci());

        $kunci = array_map(static fn (PeriodeSlip $p): string => $p->kunci(), $daftar);
        $urut  = $kunci;
        sort($urut);
        $this->assertSame($urut, $kunci);
        $this->assertSame(array_values(array_unique($kunci)), $kunci);
    }

    public function testObjekMutablePemanggilTidakBerubah(): void
    {
        $sekarang = new DateTime('2026-10-04 03:00:00', new DateTimeZone('UTC'));

        GerbangPeriode::sudahRilis(PeriodeSlip::buat(2026, 10), $sekarang);
        GerbangPeriode::daftarTersedia($sekarang);
        GerbangPeriode::terbaru($sekarang);

        $this->assertSame('UTC', $sekarang->getTimezone()->getName());
        $this->assertSame('2026-10-04 03:00:00', $sekarang->format('Y-m-d H:i:s'));
    }
}
