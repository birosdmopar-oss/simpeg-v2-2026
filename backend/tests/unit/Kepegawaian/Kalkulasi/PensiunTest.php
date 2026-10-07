<?php

declare(strict_types=1);

namespace Tests\Unit\Kepegawaian\Kalkulasi;

use App\Libraries\Kepegawaian\Kalkulasi\Pensiun;
use CodeIgniter\Test\CIUnitTestCase;
use DateTime;
use InvalidArgumentException;

/**
 * CR-036 — BUP dan TMT pensiun PNS (SRS-FR-033 58/60/65; legacy `getTanggalPensiun`,
 * `helpers/function_helper.php:2400-2418`).
 *
 * @internal
 */
final class PensiunTest extends CIUnitTestCase
{
    public function testBatasUsiaUrutanLegacy(): void
    {
        // umur_pensiun jabatan > 0 menang (65 JF Ahli Utama, 60 JF Madya), termasuk atas Eselon I/II.
        $this->assertSame(65, Pensiun::batasUsia(65, 3, null));
        $this->assertSame(60, Pensiun::batasUsia(60, 3, null));
        $this->assertSame(58, Pensiun::batasUsia(58, 1, 1));

        // umur_pensiun kosong/0/negatif → Struktural Eselon I/II = 60.
        $this->assertSame(60, Pensiun::batasUsia(null, 1, 1));
        $this->assertSame(60, Pensiun::batasUsia(0, 1, 2));
        $this->assertSame(60, Pensiun::batasUsia(-1, 1, 1));

        // Selain itu 58: Eselon III/IV, bukan struktural, tanpa jabatan.
        $this->assertSame(58, Pensiun::batasUsia(null, 1, 3));
        $this->assertSame(58, Pensiun::batasUsia(null, 1, 4));
        $this->assertSame(58, Pensiun::batasUsia(null, 2, 1));
        $this->assertSame(58, Pensiun::batasUsia(null, null, 1));
        $this->assertSame(58, Pensiun::batasUsia(null, 1, null));
        $this->assertSame(58, Pensiun::batasUsia(null, null, null));
    }

    public function testTanggalPensiunAwalBulanBerikutnya(): void
    {
        $this->assertSame('2026-04-01', Pensiun::tanggalPensiun('1968-03-15', 58));
        $this->assertSame('2026-04-01', Pensiun::tanggalPensiun('1968-03-01', 58));
        $this->assertSame('2027-01-01', Pensiun::tanggalPensiun('1968-12-20', 58));
        $this->assertSame('2026-06-01', Pensiun::tanggalPensiun('1966-05-10', 60));
        $this->assertSame('2031-08-01', Pensiun::tanggalPensiun('1966-07-31', 65));
    }

    /**
     * Deviasi terdokumentasi: legacy meluap untuk tanggal lahir 29–31 (`DateTime::modify`), v2 tidak.
     */
    public function testTanggalLahirAkhirBulanTidakMeluap(): void
    {
        $kasus = [
            // [lahir, BUP, v2, legacy]
            ['1968-01-31', 58, '2026-02-01', '2026-03-01'],
            ['1968-01-29', 58, '2026-02-01', '2026-03-01'],
            ['1968-02-29', 58, '2026-03-01', '2026-04-01'],
            ['1968-03-31', 58, '2026-04-01', '2026-05-01'],
            ['1968-02-29', 60, '2028-03-01', '2028-03-01'],
        ];

        foreach ($kasus as [$lahir, $bup, $v2, $legacy]) {
            $this->assertSame($v2, Pensiun::tanggalPensiun($lahir, $bup), "{$lahir} + {$bup}");
            $this->assertSame($legacy, self::legacy($lahir, $bup), "legacy {$lahir} + {$bup}");
        }
    }

    /**
     * Untuk tanggal lahir 1–28, hasil sama persis dengan legacy pada semua tanggal 1950–1975 dan BUP 58/60/65.
     */
    public function testSamaDenganLegacyUntukTanggal1Sampai28(): void
    {
        $beda = [];

        for ($tahun = 1950; $tahun <= 1975; $tahun++) {
            for ($bulan = 1; $bulan <= 12; $bulan++) {
                for ($hari = 1; $hari <= 28; $hari++) {
                    $lahir = sprintf('%04d-%02d-%02d', $tahun, $bulan, $hari);

                    foreach ([58, 60, 65] as $bup) {
                        if (Pensiun::tanggalPensiun($lahir, $bup) !== self::legacy($lahir, $bup)) {
                            $beda[] = "{$lahir}+{$bup}";
                        }
                    }
                }
            }
        }

        $this->assertSame([], $beda);
    }

    public function testDataSistemSalahDilempar(): void
    {
        $kasus = [
            ['0000-00-00', 58, 'Tanggal lahir'],
            ['1968-02-30', 58, 'Tanggal lahir'],
            ['', 58, 'Tanggal lahir'],
            // Tahun di luar 1900–2100 (batas TanggalBisnis) ditolak walau tanggal kalendernya ada.
            ['1899-12-31', 58, 'Tanggal lahir'],
            ['1968-03-15', 0, 'Batas usia pensiun'],
            ['1968-03-15', -58, 'Batas usia pensiun'],
        ];

        foreach ($kasus as [$lahir, $bup, $pesan]) {
            try {
                Pensiun::tanggalPensiun($lahir, $bup);
                $this->fail("Tidak dilempar: {$lahir} + {$bup}");
            } catch (InvalidArgumentException $e) {
                $this->assertStringStartsWith($pesan, $e->getMessage());
            }
        }
    }

    /**
     * Salinan algoritma legacy `getTanggalPensiun` (tanpa penentuan BUP) sebagai pembanding.
     */
    private static function legacy(string $lahir, int $bup): string
    {
        $dt = new DateTime($lahir);
        $dt->modify("+{$bup} year");
        $dt->modify('+1 month');

        return $dt->format('Y-m-01');
    }
}
