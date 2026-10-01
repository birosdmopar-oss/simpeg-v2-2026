<?php

declare(strict_types=1);

namespace Tests\Unit\Presensi\SlipGaji;

use App\Libraries\Presensi\SlipGaji\PencocokanNama;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * CR-027 (M-2, M-3, P-9) — pencocokan nama longgar setia legacy dan format nama bergelar. Nama sintetis.
 *
 * @internal
 */
final class PencocokanNamaTest extends CIUnitTestCase
{
    public function testNormalisasi(): void
    {
        $this->assertSame('pegawaicontoh', PencocokanNama::normalisasi('PEGAWAI  Contoh'));
        $this->assertSame('drpegawaicontohskom', PencocokanNama::normalisasi('Dr. Pegawai Contoh, S.Kom.'));
        $this->assertSame('', PencocokanNama::normalisasi("...'"));
    }

    public function testCocok(): void
    {
        $this->assertTrue(PencocokanNama::cocok('PEGAWAI CONTOH', 'Pegawai Contoh'));
        $this->assertTrue(PencocokanNama::cocok('Dr. Pegawai Contoh, S.Kom.', 'Pegawai Contoh'));
        $this->assertTrue(PencocokanNama::cocok('Pegawai  Contoh', 'Pegawai Contoh'));
        $this->assertTrue(PencocokanNama::cocok('Pegawai Contoh', 'Dr. Pegawai Contoh'));
        // Positif palsu legacy yang didokumentasikan (M-3): substring.
        $this->assertTrue(PencocokanNama::cocok('Ani', 'Daniel'));
    }

    public function testTidakCocok(): void
    {
        $this->assertFalse(PencocokanNama::cocok('Pegawai Contah', 'Pegawai Contoh'));
        $this->assertFalse(PencocokanNama::cocok('...', 'Pegawai Contoh'));
        $this->assertFalse(PencocokanNama::cocok('', 'Pegawai Contoh'));
        $this->assertFalse(PencocokanNama::cocok('Pegawai Contoh', ''));
    }

    public function testDenganGelar(): void
    {
        $this->assertSame('Dr. Pegawai Contoh, S.Kom.', PencocokanNama::denganGelar('Dr.', 'Pegawai Contoh', 'S.Kom.'));
        $this->assertSame('Pegawai Contoh', PencocokanNama::denganGelar('', 'Pegawai Contoh', ''));
        $this->assertSame('Pegawai Contoh', PencocokanNama::denganGelar(null, 'Pegawai Contoh', ' '));
        $this->assertSame('Pegawai Contoh, S.Kom.', PencocokanNama::denganGelar(null, ' Pegawai Contoh ', ' S.Kom. '));
        $this->assertSame('Dr. Pegawai Contoh', PencocokanNama::denganGelar(' Dr. ', 'Pegawai Contoh', null));
    }
}
