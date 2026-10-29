<?php

declare(strict_types=1);

namespace Tests\Presensi\RegresiD13;

use PHPUnit\Framework\Attributes\Group;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Presensi\RegresiD13UjiTrait;

/**
 * D-13 S08 — Batas TL/PSW 30 & 60 menit (PRD 5C §7.6): selisih menit dengan detik dibuang ke arah nol; 1–30 = TL1/PSW1,
 * 31–60 = TL2/PSW2, > 60 = TL3/PSW3; tepat di batas dan ±1 menit; kompensasi TL1 bila pulang ≥ 30 menit lebih lambat.
 * Jam bawaan 07:30 / 16:00 (Jumat 16:30). Legacy [K] `L_presensi.php:12871-12883` (selisih menit), `:12888-12931`
 * (golongan & kompensasi).
 *
 * Test lama yang dicakup: `unit/Presensi/Tukin/TukinTest` (library murni), `unit/Presensi/Rekap/KlasifikasiKehadiranTest`
 * (rekap kehadiran). Di sini lewat `dh_online` nyata dan laporan/rekap Tukin.
 *
 * @internal
 */
#[Group('d13')]
final class S08BatasTlPswTest extends DatabaseTestCase
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

    public function testBatasTigaPuluhDanEnamPuluhMenit(): void
    {
        $nip = $this->pegawaiHadirD13();
        // [tanggal (bukan Jumat), masuk, pulang, potongan harian, kode]
        $kasus = [
            ['2026-08-17', '08:00:00', '16:00:00', '0.10', 'TL1'],   // 30 menit tepat
            ['2026-08-18', '08:00:59', '16:00:00', '0.10', 'TL1'],   // 30 menit 59 detik → 30
            ['2026-08-19', '08:01:00', '16:00:00', '0.20', 'TL2'],   // 31 menit
            ['2026-08-20', '08:30:00', '16:00:00', '0.20', 'TL2'],   // 60 menit tepat
            ['2026-08-24', '08:31:00', '16:00:00', '0.25', 'TL3'],   // 61 menit
            ['2026-08-25', '07:30:00', '15:30:00', '0.10', 'PSW1'],  // pulang 30 menit lebih awal
            ['2026-08-26', '07:30:00', '15:29:00', '0.20', 'PSW2'],  // 31 menit
            ['2026-08-27', '07:30:00', '15:00:00', '0.20', 'PSW2'],  // 60 menit
            ['2026-09-01', '07:30:00', '14:59:00', '0.25', 'PSW3'],  // 61 menit
            ['2026-09-02', '07:30:59', '16:00:00', '0.00', null],    // 59 detik → 0 menit
            ['2026-09-03', '07:31:00', '16:00:00', '0.10', 'TL1'],   // 1 menit
            ['2026-08-31', '08:00:00', '16:30:00', '0.00', null],    // TL1 gugur: pulang 30 menit lebih lambat
        ];

        foreach ($kasus as [$tgl, $masuk, $pulang]) {
            $this->presensiD13($nip, $tgl, $masuk, $pulang);
        }

        $laporan = $this->laporanD13($nip);
        $harian  = self::harianD13($laporan);

        foreach ($kasus as [$tgl, $masuk, $pulang, $potongan, $kode]) {
            $this->assertSame($potongan, $harian[$tgl]['potongan'], "{$tgl} {$masuk}-{$pulang}");
            $kode === null
                ? $this->assertSame('', $harian[$tgl]['keterangan'], $tgl)
                : $this->assertStringContainsString($kode, $harian[$tgl]['keterangan'], $tgl);
        }

        $this->assertSame(['TL1' => 3, 'TL2' => 2, 'TL3' => 1, 'PSW1' => 1, 'PSW2' => 2, 'PSW3' => 1], array_intersect_key(
            array_filter($laporan['jumlah']),
            array_flip(['TL1', 'TL2', 'TL3', 'PSW1', 'PSW2', 'PSW3']),
        ));
        $this->assertSame(['0.95', '0.75', '1.70'], [$laporan['rincian']['TL'], $laporan['rincian']['PSW'], $laporan['total_presensi']]);
        $this->assertRekapSamaLaporanD13($nip, $laporan);
    }
}
