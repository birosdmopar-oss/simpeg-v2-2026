<?php

declare(strict_types=1);

namespace Tests\Presensi\RegresiD13;

use PHPUnit\Framework\Attributes\Group;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Presensi\RegresiD13UjiTrait;

/**
 * D-13 S04 — Cuti sakit > 14 hari kerja berurutan (PRD 5C §7.6): hari ke-1..14 tanpa potongan, hari ke-15 dan
 * seterusnya `CUTI_SAKIT` 1% × 0,2 per hari kerja; urutan tidak terputus akhir pekan; lookback lintas periode (14 hari di
 * periode lalu + hari pertama periode ini = hari ke-15). Legacy [K] `L_presensi.php:12796-12804` (hari ke-15),
 * `:11890-11891`, `:12686-12697` (lookback satu periode).
 *
 * Test lama yang dicakup: `Presensi/TukinData/CutiSakitLookbackTest` (4 test, lewat `TukinDataService` langsung).
 *
 * @internal
 */
#[Group('d13')]
final class S04CutiSakitLebih14HariTest extends DatabaseTestCase
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

    public function testHariKeLimaBelasDanSeterusnyaDipotong(): void
    {
        // 17 Agu – 4 Sep 2026 = 15 hari kerja berurutan (17–21, 24–28, 31 Agu, 1–4 Sep); 3 Sep = ke-14, 4 Sep = ke-15.
        $sakit = ['2026-08-17', '2026-08-18', '2026-08-19', '2026-08-20', '2026-08-21', '2026-08-24', '2026-08-25', '2026-08-26',
            '2026-08-27', '2026-08-28', '2026-08-31', '2026-09-01', '2026-09-02', '2026-09-03', '2026-09-04'];
        $nip = $this->pegawaiHadirD13($sakit);
        $this->cuti($nip, 'sakit', '2026-08-17', '2026-09-04', 15);

        $laporan = $this->laporanD13($nip);
        $harian  = self::harianD13($laporan);

        $this->assertSame(['cuti', '0.00'], [$harian['2026-09-03']['status'], $harian['2026-09-03']['potongan']], 'hari ke-14');
        $this->assertSame(['cuti', '0.20'], [$harian['2026-09-04']['status'], $harian['2026-09-04']['potongan']], 'hari ke-15: 1% × 0,2');
        $this->assertSame(['0.20', '0.20'], [$laporan['rincian']['CUTI'], $laporan['total_presensi']]);
        $this->assertSame(1, $laporan['jumlah']['CS'] ?? null, 'satu kejadian cuti sakit berpotongan');
        $this->assertRekapSamaLaporanD13($nip, $laporan);
    }

    public function testLookbackLintasPeriode(): void
    {
        // Periode 2026-09 (16 Agu – 15 Sep): 14 hari kerja sakit terakhir di periode itu (27 Agu – 15 Sep).
        $nip = $this->pegawaiRekap($this->satkerD13);
        $this->cuti($nip, 'sakit', '2026-08-27', '2026-09-17', 16);

        $september = $this->laporanD13($nip);
        $this->assertSame('0.00', $september['rincian']['CUTI'] ?? '0.00', '14 hari kerja pertama tanpa potongan');

        // Periode 2026-10 (16 Sep – 15 Okt): 16–17 Sep = hari ke-15/16 berkat lookback.
        $oktober = $this->laporanD13($nip, '2026-10');
        $harian  = self::harianD13($oktober);
        $this->assertSame(['0.20', '0.20'], [$harian['2026-09-16']['potongan'], $harian['2026-09-17']['potongan']], 'hari ke-15 dan ke-16');
        $this->assertSame('0.40', $oktober['rincian']['CUTI']);
        $this->assertRekapSamaLaporanD13($nip, $oktober, '2026-10');
    }
}
