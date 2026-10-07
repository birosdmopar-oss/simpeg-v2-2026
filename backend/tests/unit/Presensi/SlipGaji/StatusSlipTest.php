<?php

declare(strict_types=1);

namespace Tests\Unit\Presensi\SlipGaji;

use App\Libraries\Presensi\SlipGaji\StatusSlip;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * CR-027 (S-1..S-5) — status "sudah dibuka": buka hanya bila belum dibuka, buka ulang (reset) hanya bila sudah dibuka,
 * dan cetak pegawai selama status dibuka.
 *
 * @internal
 */
final class StatusSlipTest extends CIUnitTestCase
{
    public function testPeriksaBuka(): void
    {
        $this->assertTrue(StatusSlip::periksaBuka(null)->valid);
        $this->assertTrue(StatusSlip::periksaBuka('')->valid);

        $sudah = StatusSlip::periksaBuka('2026-10-04 03:00:00');
        $this->assertFalse($sudah->valid);
        $this->assertSame('slip_sudah_dibuka', $sudah->kode);
        $this->assertStringStartsWith('Slip gaji ini sudah pernah dibuka.', (string) $sudah->pesan);
    }

    public function testPeriksaReset(): void
    {
        $belum = StatusSlip::periksaReset(null);
        $this->assertFalse($belum->valid);
        $this->assertSame('slip_belum_dibuka', $belum->kode);
        $this->assertSame('Slip gaji ini belum pernah dibuka, tidak perlu direset.', $belum->pesan);
        $this->assertSame('slip_belum_dibuka', StatusSlip::periksaReset('')->kode);

        $this->assertTrue(StatusSlip::periksaReset('2026-10-04 03:00:00')->valid);
    }

    public function testBolehCetakPegawai(): void
    {
        $this->assertFalse(StatusSlip::bolehCetakPegawai(null));
        $this->assertFalse(StatusSlip::bolehCetakPegawai(''));
        $this->assertTrue(StatusSlip::bolehCetakPegawai('2026-10-04 03:00:00'));
    }
}
