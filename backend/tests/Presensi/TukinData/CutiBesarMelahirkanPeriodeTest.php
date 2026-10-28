<?php

declare(strict_types=1);

namespace Tests\Presensi\TukinData;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;
use Tests\Support\Presensi\CutiTbUjiTrait;
use Tests\Support\Presensi\TukinDataUjiTrait;

/**
 * PRD 5B §7.1.5 AC-D06.4/06.5 (MAKE-039) — potongan periode dari `riwayat_cuti.lama_cuti` nyata [K rekap
 * `L_presensi.php:12470-12575`, arti v2 = rekap K-1]:
 * - cuti besar `lama_cuti` 35 / 36 / 65 / 66 → 1 / 2 / 2 / 3 kali potongan 5% pada periode ke-1/2/3;
 * - cuti melahirkan: anak 3 → tanpa potongan periode; anak 4 → 40 / 70 / 80% pada periode ke-1/2/3.
 *
 * Cuti mulai 16 Maret 2026 → tanggal potong 16 Maret (periode April), 16 April (Mei), 16 Mei (Juni).
 *
 * @internal
 */
final class CutiBesarMelahirkanPeriodeTest extends DatabaseTestCase
{
    use CutiTbUjiTrait;
    use PegawaiFixtureTrait;
    use TukinDataUjiTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetWebConfig();
        $this->aturWebConfig(self::TARIF_UJI);
    }

    protected function tearDown(): void
    {
        $this->resetWebConfig();
        parent::tearDown();
    }

    /**
     * @return iterable<string, array{0: int, 1: int, 2: ?int}> [lama_cuti, bulan Tukin 2026, total potongan bp (null = bukan periode potong)]
     */
    public static function cutiBesar(): iterable
    {
        yield 'lama 35 periode ke-1' => [35, 4, 500];
        yield 'lama 35 tanpa periode ke-2' => [35, 5, null];
        yield 'lama 36 periode ke-2' => [36, 5, 500];
        yield 'lama 36 tanpa periode ke-3' => [36, 6, null];
        yield 'lama 65 tanpa periode ke-3' => [65, 6, null];
        yield 'lama 66 periode ke-3' => [66, 6, 500];
    }

    #[DataProvider('cutiBesar')]
    public function testCutiBesarPeriodePotong(int $lama, int $bulan, ?int $total): void
    {
        $nip = $this->buatPegawai();
        $this->cuti($nip, 'besar', '2026-03-16', '2026-05-20', $lama);

        $hasil = $this->hitungTukin($nip, 2026, $bulan);

        if ($total === null) {
            $this->assertNotSame('cuti_besar', $hasil->alasan);
        } else {
            $this->assertSame(['cuti_besar', $total, $total], [$hasil->alasan, $hasil->totalPresensi, $hasil->totalPotongan]);
        }
    }

    /**
     * @return iterable<string, array{0: int, 1: int, 2: ?int}> [jumlah anak, bulan Tukin 2026, total potongan bp]
     */
    public static function cutiMelahirkan(): iterable
    {
        yield 'anak 3 tanpa potongan periode' => [3, 4, null];
        yield 'anak 4 periode ke-1 40%' => [4, 4, 4000];
        yield 'anak 4 periode ke-2 70%' => [4, 5, 7000];
        yield 'anak 4 periode ke-3 80%' => [4, 6, 8000];
    }

    #[DataProvider('cutiMelahirkan')]
    public function testCutiMelahirkanAnakKeEmpat(int $anak, int $bulan, ?int $total): void
    {
        $nip = $this->buatPegawai();
        $this->keluarga($nip, $anak);
        $this->cuti($nip, 'melahirkan', '2026-03-16', '2026-05-20', 90);

        $hasil = $this->hitungTukin($nip, 2026, $bulan);

        if ($total === null) {
            $this->assertNotSame('cuti_melahirkan', $hasil->alasan);
        } else {
            $this->assertSame(['cuti_melahirkan', $total], [$hasil->alasan, $hasil->totalPotongan]);
        }
    }
}
