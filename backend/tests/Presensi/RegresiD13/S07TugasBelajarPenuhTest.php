<?php

declare(strict_types=1);

namespace Tests\Presensi\RegresiD13;

use PHPUnit\Framework\Attributes\Group;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Presensi\RegresiD13UjiTrait;

/**
 * D-13 S07 — Tugas belajar penuh = bebas presensi total (PRD 5C §7.6, DoD 081-1): TB (data Fase 4, aturan
 * `BebasPresensiTb`) meliputi seluruh periode → hari kerja `tugas_belajar` tanpa potongan dan pemutihan total
 * (`alasan = pemutihan_tb`, total 0 termasuk SKP); TB berakhir di tengah periode → hari sesudahnya dinilai presensi tanpa
 * pemutihan. Legacy [K] `L_presensi.php:12296-12330`, `:12761-12778` (TB bebas, tanpa potongan 25%), `:12726-12735`,
 * `:13026-13030` (pemutihan).
 *
 * Test lama yang dicakup: `Presensi/TukinData/PrecedenceKombinasiTest::testTbPenuhPemutihan`,
 * `::testTbBerakhirDiTengahLaluPresensiNormalDanCelahPerpanjangan`, `SumberCutiTbFase4Test::testTugasBelajarLewatAturanFase4DanCelahPerpanjangan`.
 *
 * @internal
 */
#[Group('d13')]
final class S07TugasBelajarPenuhTest extends DatabaseTestCase
{
    use RegresiD13UjiTrait;

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

    public function testTbSepanjangPeriodePemutihan(): void
    {
        $nip = $this->pegawaiRekap($this->satkerD13);
        $this->tbRekap($nip, '2026-08-01', '2026-09-30');

        $laporan = $this->laporanD13($nip);
        $harian  = self::harianD13($laporan);

        $this->assertSame(['tugas_belajar', '0.00'], [$harian['2026-08-19']['status'], $harian['2026-08-19']['potongan']]);
        $this->assertSame(['tugas_belajar', '0.00'], [$harian['2026-09-15']['status'], $harian['2026-09-15']['potongan']]);
        $this->assertSame(['pemutihan_tb', '0.00', '0.00'], [$laporan['alasan'], $laporan['total_presensi'], $laporan['total_potongan']]);
        $this->assertRekapSamaLaporanD13($nip, $laporan);
    }

    public function testTbBerakhirDiTengahLaluPresensiDinilai(): void
    {
        $agustus = ['2026-08-17', '2026-08-18', '2026-08-19', '2026-08-20', '2026-08-21', '2026-08-24', '2026-08-25', '2026-08-26', '2026-08-27', '2026-08-28', '2026-08-31'];
        $nip     = $this->pegawaiHadirD13([...$agustus, '2026-09-02']);
        $this->tbRekap($nip, '2026-08-01', '2026-08-31');

        $laporan = $this->laporanD13($nip);
        $harian  = self::harianD13($laporan);

        $this->assertSame('tugas_belajar', $harian['2026-08-31']['status']);
        $this->assertSame('hadir', $harian['2026-09-01']['status'], 'sesudah TB dinilai presensi');
        $this->assertSame(['TK', '0.60'], [$harian['2026-09-02']['status'], $harian['2026-09-02']['potongan']]);
        $this->assertNotSame('pemutihan_tb', $laporan['alasan'], 'hari kerja terakhir bukan TB');
        $this->assertSame('0.60', $laporan['total_presensi']);
        $this->assertRekapSamaLaporanD13($nip, $laporan);
    }
}
