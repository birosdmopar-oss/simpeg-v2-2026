<?php

declare(strict_types=1);

namespace Tests\Unit\Presensi\SlipGaji;

use App\Exceptions\ValidationException;
use App\Libraries\Presensi\SlipGaji\HasilSlip;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * CR-027 (K-CR027-5) — hasil gagal atas input pengguna dipetakan ke 422 per field (ADR-001).
 *
 * @internal
 */
final class HasilSlipTest extends CIUnitTestCase
{
    public function testHasilGagalMenjadi422PerField(): void
    {
        $hasil = HasilSlip::gagal('periode_wajib', 'Periode slip gaji wajib diisi.', ['a' => 1]);

        $this->assertNull($hasil->nilai);
        $this->assertSame(['a' => 1], $hasil->detail);

        try {
            $hasil->lemparBilaGagal('periode');
            $this->fail('ValidationException tidak dilempar');
        } catch (ValidationException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertSame(['periode' => ['Periode slip gaji wajib diisi.']], $e->getErrors());
        }

        $berhasil = HasilSlip::berhasil(42);
        $this->assertSame($berhasil, $berhasil->lemparBilaGagal('periode'));
        $this->assertSame(42, $berhasil->nilai);
        $this->assertNull($berhasil->kode);
    }
}
