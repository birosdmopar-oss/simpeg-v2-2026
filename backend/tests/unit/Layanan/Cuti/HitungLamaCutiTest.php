<?php

declare(strict_types=1);

namespace Tests\Unit\Layanan\Cuti;

use App\Libraries\Layanan\Cuti\AturanCuti;
use App\Libraries\Layanan\Cuti\HitungLamaCuti;
use App\Libraries\Layanan\Cuti\PorsiTahunCuti;
use App\Libraries\Presensi\Tukin\HariKerja;
use App\Libraries\Presensi\Tukin\PeriodeTukin;
use CodeIgniter\Test\CIUnitTestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * MAKE-022 Tahap 2b — lama cuti (PRD Fase 4 v1.1 §6.3.2): K22–K24, T1, T4–T7 (§6.3.8/§6.3.10), pemecahan porsi tahun
 * (§6.3.3, K13), dan **test paritas** hari kerja terhadap `Presensi\Tukin\HariKerja` (keputusan #13).
 *
 * Kalender acuan: 2026-11-01 = Minggu, jadi 2026-11-02 Senin … 2026-11-06 Jumat, 2026-11-09 Senin.
 * 2027-12-31 = Jumat, 2028-01-01 = Sabtu, 2028-01-03 = Senin.
 *
 * @internal
 */
final class HitungLamaCutiTest extends CIUnitTestCase
{
    private AturanCuti $aturan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->aturan = AturanCuti::bawaan();
    }

    public function testK22HariLiburDiTengahMengurangi(): void
    {
        $this->assertSame(3, HitungLamaCuti::hariKerja('2026-11-02', '2026-11-04', []));
        $this->assertSame(2, HitungLamaCuti::hariKerja('2026-11-02', '2026-11-04', ['2026-11-03']));
        $this->assertSame(['2026-11-03'], HitungLamaCuti::liburTerpakai('2026-11-02', '2026-11-04', ['2026-11-03']));
    }

    public function testK23HanyaTanggalYangDiberikanYangDikurangi(): void
    {
        // Libur status 2/10 tidak diberikan pemanggil (HariLiburService::tanggalLibur hanya status 1) → tidak dikurangi.
        $this->assertSame(3, HitungLamaCuti::hariKerja('2026-11-02', '2026-11-04', ['2026-11-10']));
    }

    public function testK24AkhirPekanTidakDihitung(): void
    {
        $this->assertSame(2, HitungLamaCuti::hariKerja('2026-11-06', '2026-11-09', []));
        $this->assertTrue(HitungLamaCuti::akhirPekan('2026-11-07'));
        $this->assertTrue(HitungLamaCuti::akhirPekan('2026-11-08'));
        $this->assertFalse(HitungLamaCuti::akhirPekan('2026-11-09'));
    }

    public function testT5RentangSeluruhnyaLiburAtauAkhirPekanNol(): void
    {
        $this->assertSame(0, HitungLamaCuti::hariKerja('2026-11-07', '2026-11-08', []));
        $this->assertSame(0, HitungLamaCuti::hariKerja('2026-11-03', '2026-11-03', ['2026-11-03']));
        $this->assertSame(0, HitungLamaCuti::lama(1, '2026-11-07', '2026-11-08', [], $this->aturan));
    }

    public function testT6LiburDiAkhirPekanTidakDobelDanLiburSebagianBeririsan(): void
    {
        // Libur jatuh di Sabtu: tetap 2 (Jumat + Senin).
        $this->assertSame(2, HitungLamaCuti::hariKerja('2026-11-06', '2026-11-09', ['2026-11-07']));
        $this->assertSame([], HitungLamaCuti::liburTerpakai('2026-11-06', '2026-11-09', ['2026-11-07']));
        // Libur multi-hari 05–06 hanya sebagian beririsan dengan 06–09: Jumat hilang, Senin tetap.
        $this->assertSame(1, HitungLamaCuti::hariKerja('2026-11-06', '2026-11-09', ['2026-11-05', '2026-11-06']));
        // Duplikat tanggal libur tidak dihitung dua kali.
        $this->assertSame(2, HitungLamaCuti::hariKerja('2026-11-02', '2026-11-04', ['2026-11-03', '2026-11-03']));
    }

    public function testHariKalenderInklusifDanRentangTerbalik(): void
    {
        $this->assertSame(1, HitungLamaCuti::hariKalender('2026-11-07', '2026-11-07'));       // T4: TM = TA → 1
        $this->assertSame(1, HitungLamaCuti::lama(2, '2026-11-07', '2026-11-07', [], $this->aturan));
        $this->assertSame(1, HitungLamaCuti::lama(4, '2026-11-07', '2026-11-07', [], $this->aturan));
        $this->assertSame(1, HitungLamaCuti::lama(6, '2026-11-07', '2026-11-07', [], $this->aturan));
        $this->assertSame(31, HitungLamaCuti::lama(4, '2026-10-01', '2026-10-31', ['2026-10-05'], $this->aturan)); // libur tidak mengurangi kalender
        $this->assertSame(0, HitungLamaCuti::hariKalender('2026-11-09', '2026-11-02'));
        $this->assertSame(0, HitungLamaCuti::hariKerja('2026-11-09', '2026-11-02', []));
    }

    public function testJenisTidakDikenalDitolak(): void
    {
        $this->expectException(InvalidArgumentException::class);
        HitungLamaCuti::lama(7, '2026-11-02', '2026-11-04', [], $this->aturan);
    }

    /**
     * T1 — `addMonth` legacy [K `FH:960-966`]: tanggal sama bulan berikutnya, dijepit ke akhir bulan, dikurangi 1 hari.
     *
     * @return iterable<string, array{string, int, string}>
     */
    public static function tglAkhirBesar(): iterable
    {
        yield '15-01 + 1 → 14-02' => ['2025-01-15', 1, '2025-02-14'];

        yield '31-01-2025 + 1 → 27-02' => ['2025-01-31', 1, '2025-02-27'];

        yield '31-01-2024 + 1 → 28-02 (kabisat)' => ['2024-01-31', 1, '2024-02-28'];

        yield '30-01-2025 + 1 → 27-02' => ['2025-01-30', 1, '2025-02-27'];

        yield '30-01-2024 + 1 → 28-02' => ['2024-01-30', 1, '2024-02-28'];

        yield '31-03 + 1 → 29-04' => ['2025-03-31', 1, '2025-04-29'];

        yield '15-01 + 3 → 14-04' => ['2025-01-15', 3, '2025-04-14'];

        yield '31-01 + 2 → 30-03' => ['2025-01-31', 2, '2025-03-30'];

        yield '01-12 + 3 → 29-02-2028 (lintas tahun, kabisat)' => ['2027-12-01', 3, '2028-02-29'];
    }

    #[DataProvider('tglAkhirBesar')]
    public function testT1TglAkhirCutiBesar(string $mulai, int $durasi, string $akhir): void
    {
        $this->assertSame($akhir, HitungLamaCuti::tglAkhirBesar($mulai, $durasi, $this->aturan));
    }

    public function testDurasiBesarDiLuarPilihanDitolak(): void
    {
        $this->expectException(InvalidArgumentException::class);
        HitungLamaCuti::tglAkhirBesar('2025-01-15', 4, $this->aturan);
    }

    public function testT7PorsiLintasTahun(): void
    {
        // 2027-12-30 Kamis .. 2028-01-04 Selasa; 01-01-2028 Sabtu (libur di akhir pekan tidak berpengaruh).
        $porsi = PorsiTahunCuti::pecah(1, '2027-12-30', '2028-01-04', 2027, ['2028-01-01'], $this->aturan);
        $this->assertSame(['tahun_ini' => 2, 'tahun_depan' => 2], $porsi);

        // TM 31-12 Jumat saja.
        $this->assertSame(['tahun_ini' => 1, 'tahun_depan' => 0], PorsiTahunCuti::pecah(1, '2027-12-31', '2027-12-31', 2027, [], $this->aturan));

        // TM 01-01 tahun berikut → lcTahunIni 0.
        $this->assertSame(['tahun_ini' => 0, 'tahun_depan' => 2], PorsiTahunCuti::pecah(1, '2028-01-03', '2028-01-04', 2027, [], $this->aturan));

        // Kalender (jenis 4) juga dipecah inklusif.
        $this->assertSame(['tahun_ini' => 2, 'tahun_depan' => 5], PorsiTahunCuti::pecah(4, '2027-12-30', '2028-01-05', 2027, [], $this->aturan));

        $this->assertSame(2027, PorsiTahunCuti::tahunAcuan('2027-12-30 10:11:12'));
    }

    public function testT7RentangDiLuarDuaTahunDitolakPorsi(): void
    {
        $this->expectException(InvalidArgumentException::class);
        PorsiTahunCuti::pecah(1, '2027-12-30', '2029-01-04', 2027, [], $this->aturan);
    }

    public function testT8TanggalTahunSebelumnyaDitolakPorsi(): void
    {
        $this->expectException(InvalidArgumentException::class);
        PorsiTahunCuti::pecah(1, '2026-12-28', '2026-12-30', 2027, [], $this->aturan);
    }

    /**
     * Paritas terhadap `HariKerja` Tukin (keputusan #13): per tanggal dan total satu tahun penuh dengan libur yang
     * jatuh di hari kerja maupun akhir pekan.
     */
    public function testParitasDenganHariKerjaTukin(): void
    {
        $libur = ['2026-01-01', '2026-03-19', '2026-05-01', '2026-08-17', '2026-12-25', '2026-11-07', '2026-11-08', '2026-12-25'];
        $set   = HariKerja::himpunanLibur($libur);

        foreach (HariKerja::kalender('2026-01-01', '2026-12-31') as $tanggal) {
            $this->assertSame(
                HariKerja::hariKerja($tanggal, $set),
                HitungLamaCuti::hariKerjaTunggal($tanggal, HitungLamaCuti::himpunanLibur($libur)),
                "Paritas hari kerja gagal pada {$tanggal}",
            );
        }

        $periode = new PeriodeTukin('2026-01-01', '2026-12-31');
        $this->assertSame(count(HariKerja::daftar($periode, $libur)), HitungLamaCuti::hariKerja('2026-01-01', '2026-12-31', $libur));
        $this->assertSame(HariKerja::kalender('2026-02-27', '2026-03-02'), HitungLamaCuti::kalender('2026-02-27', '2026-03-02'));
    }
}
