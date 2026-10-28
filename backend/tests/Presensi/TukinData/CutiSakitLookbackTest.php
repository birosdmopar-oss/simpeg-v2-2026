<?php

declare(strict_types=1);

namespace Tests\Presensi\TukinData;

use App\Libraries\Presensi\Tukin\StatusHarian;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Kepegawaian\PegawaiFixtureTrait;
use Tests\Support\Presensi\CutiTbUjiTrait;
use Tests\Support\Presensi\TukinDataUjiTrait;

/**
 * PRD 5B §7.1.5 AC-D06.3 (MAKE-039) — cuti sakit dari `riwayat_cuti` nyata [K rekap `L_presensi.php:12796-12804`,
 * `:12686-12697`]: 14 hari kerja berurutan → 0; hari ke-15 dan seterusnya → 1% × 0,2 per hari (tepat 14/15); urutan dari
 * periode sebelumnya dibawa (lookback); cuti lain / libur di tengah urutan tidak memutus; baris presensi memutus.
 *
 * Periode April 2026 = 16 Maret–15 April (16 Maret Senin): hari kerja ke-14 = 2 April, ke-15 = 3 April.
 *
 * @internal
 */
final class CutiSakitLookbackTest extends DatabaseTestCase
{
    use CutiTbUjiTrait;
    use PegawaiFixtureTrait;
    use TukinDataUjiTrait;

    /** Potongan satu hari cuti sakit > 14 = tarif `CUTI_SAKIT` 1% (100 bp) × 0,2. */
    private const POTONGAN_SAKIT = 20;

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

    public function testTepatEmpatBelasLaluKeLimaBelas(): void
    {
        $this->assertSame(14, StatusHarian::BATAS_HARI_CUTI_SAKIT);
        $nip = $this->buatPegawai();
        $this->cuti($nip, 'sakit', '2026-03-16', '2026-04-03', 15);

        $hasil = $this->hitungTukin($nip, 2026, 4, '2026-04-03');

        $this->assertSame(0, $hasil->harian['2026-04-02']->potongan, 'hari kerja ke-14');
        $this->assertSame(self::POTONGAN_SAKIT, $hasil->harian['2026-04-03']->potongan, 'hari kerja ke-15');
        $this->assertSame(['CS'], $hasil->harian['2026-04-03']->kategori);
        $this->assertSame(self::POTONGAN_SAKIT, $hasil->potonganPresensi);
    }

    public function testLookbackLintasPeriode(): void
    {
        $nip = $this->buatPegawai();
        // 14 hari kerja terakhir periode Maret (24 Feb–13 Mar) + hari kerja pertama periode April (16 Mar) = ke-15.
        $this->cuti($nip, 'sakit', '2026-02-24', '2026-03-16', 15);

        $hasil = $this->hitungTukin($nip, 2026, 4, '2026-03-16');

        $this->assertSame(self::POTONGAN_SAKIT, $hasil->harian['2026-03-16']->potongan);
    }

    public function testCutiLainDanLiburDiTengahTidakMemutus(): void
    {
        $nip = $this->buatPegawai();
        $this->cuti($nip, 'sakit', '2026-03-16', '2026-03-24', 7);
        $this->cuti($nip, 'tahunan', '2026-03-25', '2026-03-25', 1);
        $this->libur('2026-03-26');
        $this->cuti($nip, 'sakit', '2026-03-27', '2026-04-06', 7);

        $hasil = $this->hitungTukin($nip, 2026, 4, '2026-04-06');

        // Sakit: 16–20, 23–24 (7) + 27, 30, 31, 1, 2, 3 (6) = 13 sampai 3 April; 6 April = ke-14 → masih 0.
        $this->assertSame(0, $hasil->harian['2026-04-06']->potongan);
        $this->assertSame('cuti', $hasil->harian['2026-03-25']->status);
        $this->assertSame(0, $hasil->potonganPresensi);

        $this->cuti($nip, 'sakit', '2026-04-07', '2026-04-07', 1);
        $this->assertSame(self::POTONGAN_SAKIT, $this->hitungTukin($nip, 2026, 4, '2026-04-07')->harian['2026-04-07']->potongan, 'ke-15 tanpa diputus cuti tahunan/libur');
    }

    public function testBarisPresensiMemutusUrutan(): void
    {
        $nip = $this->buatPegawai();
        $this->cuti($nip, 'sakit', '2026-03-16', '2026-03-31', 12);
        $this->presensiHadir($nip, '2026-04-01');
        $this->cuti($nip, 'sakit', '2026-04-02', '2026-04-03', 2);

        $hasil = $this->hitungTukin($nip, 2026, 4, '2026-04-03');

        $this->assertSame(0, $hasil->harian['2026-04-03']->potongan, 'urutan direset oleh presensi 1 April');
    }
}
