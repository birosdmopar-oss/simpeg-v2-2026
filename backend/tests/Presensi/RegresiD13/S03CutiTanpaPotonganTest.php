<?php

declare(strict_types=1);

namespace Tests\Presensi\RegresiD13;

use PHPUnit\Framework\Attributes\Group;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Presensi\RegresiD13UjiTrait;

/**
 * D-13 S03 — Cuti tahunan / alasan penting / melahirkan (+ CLTN) tidak dipotong harian (PRD 5C §7.6): hari cuti
 * (`riwayat_cuti` status 1 lewat `SumberCutiTbFase4`) berstatus `cuti`, potongan 0, termasuk bila ada presensi tercatat;
 * cuti alasan penting tidak dipotong; cuti melahirkan tanpa anak ke-4 tidak memicu potongan periode. Cuti status ≠ 1
 * diabaikan. Legacy [K] `L_presensi.php:12807-12820` (cuti tanpa potongan), `:12415-12466` (alasan penting tidak dipakai),
 * `:12527-12575` (melahirkan hanya anak > 3).
 *
 * Test lama yang dicakup: `Presensi/TukinData/PrecedenceKombinasiTest::testCutiTanpaPotonganHarian`,
 * `SumberCutiTbFase4Test::testHanyaStatusSatuDanEkspansiDipotongRentang`.
 *
 * @internal
 */
#[Group('d13')]
final class S03CutiTanpaPotonganTest extends DatabaseTestCase
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

    public function testCutiTahunanAlasanPentingMelahirkanCltnTanpaPotonganHarian(): void
    {
        $tanggal = ['2026-08-18', '2026-08-19', '2026-08-25', '2026-09-01', '2026-09-02', '2026-09-08'];
        $nip     = $this->pegawaiHadirD13($tanggal);
        $this->cuti($nip, 'tahunan', '2026-08-18', '2026-08-19', 2);
        $this->cuti($nip, 'alasan_penting', '2026-08-25', '2026-08-25');
        $this->cuti($nip, 'melahirkan', '2026-09-01', '2026-09-02', 2);
        $this->cuti($nip, 'cltn', '2026-09-08', '2026-09-08');
        $this->cuti($nip, 'tahunan', '2026-08-27', '2026-08-27');
        $this->presensiD13($nip, '2026-08-27', '09:45:00', '14:00:00'); // cuti + presensi tercatat → tetap cuti

        $laporan = $this->laporanD13($nip);
        $harian  = self::harianD13($laporan);

        foreach ([...$tanggal, '2026-08-27'] as $tgl) {
            $this->assertSame(['cuti', '0.00'], [$harian[$tgl]['status'], $harian[$tgl]['potongan']], $tgl);
        }

        $this->assertSame('Cuti alasan penting', $harian['2026-08-25']['keterangan']);
        $this->assertSame(['0.00', '0.00', '0.00', '0.00'], [$laporan['rincian']['CUTI'] ?? '0.00', $laporan['rincian']['TK'] ?? '0.00', $laporan['rincian']['TL'] ?? '0.00', $laporan['total_presensi']]);
        $this->assertNull($laporan['alasan'], 'tanpa aturan pengganti periode (melahirkan tanpa anak ke-4)');
        $this->assertRekapSamaLaporanD13($nip, $laporan);
    }

    public function testCutiBukanStatusSatuDiabaikan(): void
    {
        $nip = $this->pegawaiHadirD13(['2026-08-18']);
        $this->cuti($nip, 'tahunan', '2026-08-18', '2026-08-18', 1, 2);

        $laporan = $this->laporanD13($nip);

        $this->assertSame('TK', self::harianD13($laporan)['2026-08-18']['status']);
        $this->assertSame('0.60', $laporan['total_presensi']);
    }
}
