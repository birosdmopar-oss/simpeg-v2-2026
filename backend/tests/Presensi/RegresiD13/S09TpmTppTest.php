<?php

declare(strict_types=1);

namespace Tests\Presensi\RegresiD13;

use PHPUnit\Framework\Attributes\Group;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Presensi\RegresiD13UjiTrait;

/**
 * D-13 S09 — TPM / TPP (PRD 5C §7.6): tanpa presensi masuk (TPM) = penalti TA + denda selisih jam pulang; tanpa presensi
 * pulang (TPP) = penalti TA + denda TL tanpa kompensasi; tanpa keduanya = TK. Tarif uji TA 1,5% / TL1-PSW1 0,5% / TK 3%,
 * faktor presensi 0,2. Legacy [K] `L_presensi.php:12853-12859`, `:12933-13020`.
 *
 * Test lama yang dicakup: `unit/Presensi/Tukin/TukinTest` (blok "TPM / TPP / TK", library murni). Di sini lewat `dh_online`
 * nyata (CHECK: minimal satu jam terisi) dan laporan/rekap Tukin.
 *
 * @internal
 */
#[Group('d13')]
final class S09TpmTppTest extends DatabaseTestCase
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

    public function testTpmTppDanTk(): void
    {
        $nip = $this->pegawaiHadirD13(['2026-08-24']);
        $this->presensiD13($nip, '2026-08-17', null, '16:00:00');      // TPM, pulang tepat → TA
        $this->presensiD13($nip, '2026-08-18', null, '15:30:00');      // TPM + PSW1 → TA + 0,1
        $this->presensiD13($nip, '2026-08-19', '07:30:00', null);      // TPP, masuk tepat → TA
        $this->presensiD13($nip, '2026-08-20', '08:00:00', null);      // TPP + TL1 (tanpa kompensasi) → TA + 0,1
        // 24 Agu tanpa baris presensi → TK

        $laporan = $this->laporanD13($nip);
        $harian  = self::harianD13($laporan);

        $this->assertSame(['0.30', '0.40', '0.30', '0.40', '0.60'], [
            $harian['2026-08-17']['potongan'], $harian['2026-08-18']['potongan'], $harian['2026-08-19']['potongan'], $harian['2026-08-20']['potongan'], $harian['2026-08-24']['potongan'],
        ]);
        $this->assertStringContainsString('TPM', $harian['2026-08-17']['keterangan']);
        $this->assertStringContainsString('TPP', $harian['2026-08-20']['keterangan']);
        $this->assertSame('TK', $harian['2026-08-24']['status']);
        $this->assertSame(['TPM' => 2, 'TPP' => 2, 'TK' => 1], array_intersect_key(array_filter($laporan['jumlah']), array_flip(['TPM', 'TPP', 'TK'])));
        $this->assertSame(['1.20', '0.60', '2.00'], [$laporan['rincian']['TA'], $laporan['rincian']['TK'], $laporan['total_presensi']]);
        $this->assertRekapSamaLaporanD13($nip, $laporan);
    }
}
