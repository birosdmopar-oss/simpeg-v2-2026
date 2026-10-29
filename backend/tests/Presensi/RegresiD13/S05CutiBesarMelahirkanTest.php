<?php

declare(strict_types=1);

namespace Tests\Presensi\RegresiD13;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Presensi\RegresiD13UjiTrait;

/**
 * D-13 S05 — Cuti besar / cuti melahirkan, potongan per periode (PRD 5C §7.6, DoD 081-4): cuti besar 5% pada periode
 * ke-1, ke-2 bila `lama_cuti` > 35, ke-3 bila > 65 (`alasan = cuti_besar`, presensi = 5, SKP 0); cuti melahirkan hanya bila
 * anak > 3: 40 / 70 / 80% pada periode ke-1/2/3 (`alasan = cuti_melahirkan`). Periode ke-1 = bulan Tukin yang memuat tgl 16
 * bulan mulai (mulai 17 Agu 2026 → 2026-09). Legacy [K] `L_presensi.php:12470-12523` (besar), `:12527-12575` (melahirkan),
 * `:12170-12198` (jumlah anak), `:13026-13057` (urutan pengganti total).
 *
 * Test lama yang dicakup: `Presensi/TukinData/CutiBesarMelahirkanPeriodeTest` (lewat `TukinDataService` langsung).
 *
 * @internal
 */
#[Group('d13')]
final class S05CutiBesarMelahirkanTest extends DatabaseTestCase
{
    use RegresiD13UjiTrait;

    private const WIB = '2026-12-20 09:00:00';

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanD13();
    }

    protected function tearDown(): void
    {
        $this->bersihkanD13();
        parent::tearDown();
    }

    /**
     * @return iterable<string, array{0: int, 1: list<string>}> [lama_cuti, bulan Tukin yang dipotong 5%]
     */
    public static function cutiBesar(): iterable
    {
        yield 'lama 35: periode ke-1 saja' => [35, ['2026-09']];
        yield 'lama 36: periode ke-1 dan ke-2' => [36, ['2026-09', '2026-10']];
        yield 'lama 65: periode ke-1 dan ke-2' => [65, ['2026-09', '2026-10']];
        yield 'lama 66: periode ke-1 s.d. ke-3' => [66, ['2026-09', '2026-10', '2026-11']];
    }

    #[DataProvider('cutiBesar')]
    public function testCutiBesarLimaPersenPerPeriode(int $lama, array $dipotong): void
    {
        $nip = $this->pegawaiRekap($this->satkerD13);
        $this->cuti($nip, 'besar', '2026-08-17', '2026-11-20', $lama);

        foreach (['2026-09', '2026-10', '2026-11', '2026-12'] as $bulan) {
            $laporan = $this->laporanD13($nip, $bulan, self::WIB);

            if (in_array($bulan, $dipotong, true)) {
                $this->assertSame(['cuti_besar', '5.00', '0.00', '5.00'], [$laporan['alasan'], $laporan['total_presensi'], $laporan['total_skp'], $laporan['total_potongan']], $bulan);
            } else {
                $this->assertNotSame('cuti_besar', $laporan['alasan'], $bulan);
            }

            $this->assertRekapSamaLaporanD13($nip, $laporan, $bulan, self::WIB);
        }
    }

    /**
     * @return iterable<string, array{0: int, 1: array<string, string|null>}> [jumlah anak, bulan → total potongan (null = bukan cuti_melahirkan)]
     */
    public static function cutiMelahirkan(): iterable
    {
        yield 'anak 3: tanpa potongan periode' => [3, ['2026-09' => null, '2026-10' => null, '2026-11' => null]];
        yield 'anak 4: 40 / 70 / 80' => [4, ['2026-09' => '40.00', '2026-10' => '70.00', '2026-11' => '80.00', '2026-12' => null]];
    }

    /**
     * @param array<string, string|null> $harapan
     */
    #[DataProvider('cutiMelahirkan')]
    public function testCutiMelahirkanAnakKeEmpat(int $anak, array $harapan): void
    {
        $nip = $this->pegawaiRekap($this->satkerD13);
        $this->keluarga($nip, $anak);
        $this->cuti($nip, 'melahirkan', '2026-08-17', '2026-11-13', 90);

        foreach ($harapan as $bulan => $total) {
            $laporan = $this->laporanD13($nip, $bulan, self::WIB);

            if ($total === null) {
                $this->assertNotSame('cuti_melahirkan', $laporan['alasan'], $bulan);
            } else {
                $this->assertSame(['cuti_melahirkan', $total], [$laporan['alasan'], $laporan['total_potongan']], $bulan);
            }

            $this->assertRekapSamaLaporanD13($nip, $laporan, $bulan, self::WIB);
        }
    }
}
