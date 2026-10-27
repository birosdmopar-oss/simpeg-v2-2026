<?php

declare(strict_types=1);

namespace Tests\Unit\Layanan\Tb;

use App\Libraries\Layanan\Tb\PerpanjanganTb;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * MAKE-024 — perpanjangan TB (PRD Fase 4 v1.1 §6.7.4, D15, Q-12, Q-15 TB-13): validasi, saran tanggal mulai, tanggal
 * akhir efektif, dan ringkasan induk pengganti trigger `riwayat_tb_perpan_af*` (MAX terpisah, berganda, kosong → NULL).
 *
 * @internal
 */
final class PerpanjanganTbTest extends CIUnitTestCase
{
    public function testValidasi(): void
    {
        $this->assertSame([], PerpanjanganTb::periksa('2029-09-01', '2030-08-31', 1));
        $this->assertSame([], PerpanjanganTb::periksa('2029-09-01', '2029-09-01', 1), 'satu hari boleh');

        $this->assertSame([
            'tgl_mulai_perpan' => PerpanjanganTb::PESAN_MULAI_WAJIB,
            'tgl_akhir_perpan' => PerpanjanganTb::PESAN_AKHIR_WAJIB,
            'status'           => PerpanjanganTb::PESAN_INDUK_BELUM_DISETUJUI,
        ], PerpanjanganTb::periksa(null, ' ', 0));

        $this->assertSame(['tgl_akhir_perpan' => PerpanjanganTb::PESAN_AKHIR_SEBELUM_MULAI], PerpanjanganTb::periksa('2029-09-01', '2029-08-31', 1));
        $this->assertSame(['tgl_mulai_perpan' => PerpanjanganTb::PESAN_MULAI_TIDAK_VALID], PerpanjanganTb::periksa('2029-02-29', '2030-01-01', 1));

        foreach ([0, 2, 3] as $status) {
            $this->assertArrayHasKey('status', PerpanjanganTb::periksa('2029-09-01', '2030-08-31', $status), "induk {$status}");
        }
    }

    public function testSaranMulaiDanAkhirEfektif(): void
    {
        $this->assertSame('2029-09-01', PerpanjanganTb::saranMulai('2029-08-31', null));
        $this->assertSame('2031-01-01', PerpanjanganTb::saranMulai('2029-08-31', '2030-12-31'), 'perpanjangan terakhir + 1, lintas tahun');
        $this->assertSame('2024-02-29', PerpanjanganTb::saranMulai('2024-02-28', ''), 'kabisat');

        $this->assertSame('2029-08-31', PerpanjanganTb::tglAkhirEfektif('2029-08-31', null));
        $this->assertSame('2030-12-31', PerpanjanganTb::tglAkhirEfektif('2029-08-31', '2030-12-31'));
        $this->assertSame('2029-08-31', PerpanjanganTb::tglAkhirEfektif('2029-08-31', '2029-01-31'), 'perpanjangan lebih awal tidak memundurkan');
    }

    public function testRingkasanMaxTerpisahSepertiTrigger(): void
    {
        $this->assertSame(['tgl_mulai_perpan' => null, 'tgl_akhir_perpan' => null], PerpanjanganTb::ringkasan([]));

        $this->assertSame(
            ['tgl_mulai_perpan' => '2031-09-01', 'tgl_akhir_perpan' => '2032-03-31'],
            PerpanjanganTb::ringkasan([
                ['tgl_mulai_perpan' => '2029-09-01', 'tgl_akhir_perpan' => '2032-03-31'],
                ['tgl_mulai_perpan' => '2031-09-01', 'tgl_akhir_perpan' => '2031-12-31'],
                ['tgl_mulai_perpan' => '2030-09-01', 'tgl_akhir_perpan' => '2030-12-31'],
            ]),
            'MAX mulai dan MAX akhir dari baris berbeda [K trigger]',
        );
    }
}
