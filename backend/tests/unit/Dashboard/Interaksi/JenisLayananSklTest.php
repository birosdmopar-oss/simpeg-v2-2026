<?php

declare(strict_types=1);

namespace Tests\Unit\Dashboard\Interaksi;

use App\Exceptions\ValidationException;
use App\Libraries\Dashboard\Interaksi\Survei\JenisLayananSkl;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * F-11 kode jenis layanan SKL 1–5 → tabel riwayat (Q7-22, [K] `L_survey.php:15-21`); di luar 1–5 → 422.
 *
 * @internal
 */
final class JenisLayananSklTest extends CIUnitTestCase
{
    public function testPetaLegacy(): void
    {
        $this->assertSame(['riwayat_cuti', 'id_riwayat_cuti'], JenisLayananSkl::tabel(1));
        $this->assertSame(['riwayat_kariskarsu', 'id_riwayat_kariskarsu'], JenisLayananSkl::tabel('2'));
        $this->assertSame(['riwayat_karpeg', 'id_riwayat_karpeg'], JenisLayananSkl::tabel(3));
        $this->assertSame(['riwayat_tb', 'id_riwayat_tb'], JenisLayananSkl::tabel(4));
        $this->assertSame(['riwayat_ib', 'id_riwayat_ib'], JenisLayananSkl::tabel(5));
    }

    public function testDiLuarRentangDitolak(): void
    {
        foreach ([0, 6, '1a', null, 1.0] as $kode) {
            try {
                JenisLayananSkl::tabel($kode);
                $this->fail('seharusnya 422');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('jenis_rwy', (array) $e->getErrors());
            }
        }
    }
}
