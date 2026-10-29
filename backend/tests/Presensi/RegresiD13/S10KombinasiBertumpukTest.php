<?php

declare(strict_types=1);

namespace Tests\Presensi\RegresiD13;

use PHPUnit\Framework\Attributes\Group;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Presensi\RegresiD13UjiTrait;

/**
 * D-13 S10 — Kombinasi bertumpuk libur + cuti + konket + TB dalam satu rentang (PRD 5C §7.6, DoD 081-7): satu status per
 * hari menurut precedence rekap legacy masa kerja → libur/akhir pekan → TB → cuti → konket → presensi, tanpa potongan
 * ganda. Legacy [K] `L_presensi.php:12739-12849`.
 *
 * Hari libur dibuktikan lewat hari kerja, rincian, dan total — tidak meng-assert ada/tidaknya baris libur di `harian[]`
 * (usulan CR-076).
 *
 * Test lama yang dicakup: `Presensi/TukinData/PrecedenceKombinasiTest::testKombinasiSatuStatusTanpaPotonganGanda`.
 *
 * @internal
 */
#[Group('d13')]
final class S10KombinasiBertumpukTest extends DatabaseTestCase
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

    public function testSatuStatusPerHariTanpaPotonganGanda(): void
    {
        // 18–21 Agu: cuti tahunan + konket di seluruh rentang; 19 Agu libur; 20 Agu TB; 21 Agu presensi terlambat tercatat.
        $nip = $this->pegawaiHadirD13(['2026-08-18', '2026-08-19', '2026-08-20']);
        $this->libur('2026-08-19');
        $this->cuti($nip, 'tahunan', '2026-08-18', '2026-08-21', 3);
        $this->konketBebas($nip, '2026-08-18', '2026-08-21');
        $this->tbRekap($nip, '2026-08-20', '2026-08-20');
        $this->presensiD13($nip, '2026-08-21', '09:30:00', '14:00:00');
        // 24 Agu: konket + presensi terlambat → konket.
        $this->konketBebas($nip, '2026-08-24', '2026-08-24');
        $this->presensiD13($nip, '2026-08-24', '08:45:00', '16:00:00');

        $laporan = $this->laporanD13($nip);
        $harian  = self::harianD13($laporan);

        $this->assertSame('cuti', $harian['2026-08-18']['status'], 'cuti mendahului konket');
        $this->assertSame('tugas_belajar', $harian['2026-08-20']['status'], 'TB mendahului cuti & konket');
        $this->assertSame('cuti', $harian['2026-08-21']['status'], 'cuti mendahului konket & presensi');
        $this->assertSame('konket', $harian['2026-08-24']['status'], 'konket mendahului presensi');

        foreach (['2026-08-18', '2026-08-20', '2026-08-21', '2026-08-24'] as $tgl) {
            $this->assertSame('0.00', $harian[$tgl]['potongan'], $tgl);
        }

        if (isset($harian['2026-08-19'])) {
            $this->assertSame('0.00', $harian['2026-08-19']['potongan'], 'libur (bila ditampilkan) tanpa potongan');
        }

        $this->assertSame([], array_filter($laporan['jumlah']), 'tanpa TL/PSW/TK/TPM/TPP/CS');
        $this->assertSame('0.00', $laporan['total_presensi']);
        $this->assertNotSame('pemutihan_tb', $laporan['alasan'], 'hari kerja terakhir bukan TB');
        $this->assertRekapSamaLaporanD13($nip, $laporan);
    }
}
