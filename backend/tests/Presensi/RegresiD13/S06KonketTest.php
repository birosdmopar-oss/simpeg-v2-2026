<?php

declare(strict_types=1);

namespace Tests\Presensi\RegresiD13;

use PHPUnit\Framework\Attributes\Group;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Presensi\RegresiD13UjiTrait;

/**
 * D-13 S06 — Konket `affect_tukin = 1` = hadir tanpa potongan (PRD 5C §7.6, DoD 081-5): `absen_ijin` status `V` dengan
 * `affect_tukin = 1` membebaskan hari itu apa pun kategorinya (termasuk kategori 13, yang khusus uang makan), mendahului
 * presensi (terlambat tidak dinilai); `affect_tukin = 2` tidak membebaskan. Legacy [K] `L_presensi.php:12346`,
 * `:12828-12846`.
 *
 * Test lama yang dicakup: `Presensi/TukinData/HarianTukinAdapterTest::testKonketDisetujuiBerpengaruhTukinPerTanggal`,
 * `PrecedenceKombinasiTest` (cuti + konket, konket + presensi).
 *
 * @internal
 */
#[Group('d13')]
final class S06KonketTest extends DatabaseTestCase
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

    public function testKonketBerpengaruhTukinTanpaPotongan(): void
    {
        $nip = $this->pegawaiHadirD13(['2026-08-19', '2026-08-20', '2026-08-24']);
        $this->konketRekap($nip, '2026-08-19', 5);
        $this->konketRekap($nip, '2026-08-20', 13);
        $this->konketRekap($nip, '2026-08-24', 5, 2);
        $this->konketRekap($nip, '2026-08-25', 5);
        $this->presensiD13($nip, '2026-08-25', '09:00:00', '14:00:00'); // konket + presensi terlambat → konket

        $laporan = $this->laporanD13($nip);
        $harian  = self::harianD13($laporan);

        foreach (['2026-08-19', '2026-08-20', '2026-08-25'] as $tgl) {
            $this->assertSame(['konket', '0.00'], [$harian[$tgl]['status'], $harian[$tgl]['potongan']], $tgl);
        }

        $this->assertSame([5, 13], [$harian['2026-08-19']['kategori_konket'], $harian['2026-08-20']['kategori_konket']]);
        $this->assertSame(['TK', '0.60'], [$harian['2026-08-24']['status'], $harian['2026-08-24']['potongan']], 'affect_tukin = 2 tidak membebaskan');
        $this->assertSame(['0.60', '0.00', '0.00', '0.60'], [$laporan['rincian']['TK'], $laporan['rincian']['TL'] ?? '0.00', $laporan['rincian']['PSW'] ?? '0.00', $laporan['total_presensi']]);
        $this->assertRekapSamaLaporanD13($nip, $laporan);
    }
}
