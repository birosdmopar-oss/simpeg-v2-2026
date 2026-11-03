<?php

declare(strict_types=1);

namespace Tests\Unit\Layanan;

use App\Libraries\Layanan\Cuti\QrTtd;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * MAKE-065 Q6-22 — QR visualisasi tanda tangan ikut legacy `saveQRLogo(…, 300, 10, 75)`: PNG 320×320 (QR 300 + margin 10),
 * logo resmi (salinan `QR_MID_IMG` legacy) di tengah; logo hilang/rusak → QR tanpa logo, tetap terbentuk.
 *
 * @internal
 */
final class QrTtdTest extends CIUnitTestCase
{
    private const URL = 'https://simpeg.contoh/surat-cuti/pdf/abababababababababababababababababababababababababababababababab';

    protected function setUp(): void
    {
        parent::setUp();

        if (! extension_loaded('gd')) {
            $this->markTestSkipped('Ekstensi GD tidak tersedia.');
        }
    }

    public function testLogoResmiAdaDanTerbacaGd(): void
    {
        $this->assertSame('668b8d8421dac47691682938cacff7bb24c1366a59ca51ab6fa4eae481813a03', hash_file('sha256', QrTtd::LOGO_BAWAAN), 'salinan apa adanya QR_MID_IMG legacy');

        $png = (string) QrTtd::png(self::URL);
        $this->assertSame([320, 320], array_slice((array) getimagesizefromstring($png), 0, 2), 'QR 300 + margin 10 (saveQRLogo 300, 10, 75)');
        $this->assertTrue($this->adaWarnaSelainHitamPutihDiTengah($png), 'logo tertempel di tengah');
        $this->assertSame(0xFFFFFF, $this->piksel($png, 0, 0), 'margin putih');
    }

    public function testLogoHilangAtauRusakTetapMenghasilkanQrTanpaLogo(): void
    {
        // Byte PNG tidak dibandingkan: TCPDF memilih mask QR secara acak (QR_FIND_FROM_RANDOM), QR tetap sah.
        $this->assertFalse($this->adaWarnaSelainHitamPutihDiTengah((string) QrTtd::png(self::URL, null)));
        $this->assertFalse($this->adaWarnaSelainHitamPutihDiTengah((string) QrTtd::png(self::URL, '/tidak/ada/logo.jpg')));

        $rusak = (string) tempnam(sys_get_temp_dir(), 'logo');
        file_put_contents($rusak, 'bukan gambar');

        try {
            $png = (string) QrTtd::png(self::URL, $rusak);
            $this->assertSame([320, 320], array_slice((array) getimagesizefromstring($png), 0, 2));
            $this->assertFalse($this->adaWarnaSelainHitamPutihDiTengah($png));
        } finally {
            unlink($rusak);
        }
    }

    /**
     * Kotak 75×75 di tengah: QR polos hanya hitam/putih; logo berwarna menghasilkan warna lain.
     */
    private function adaWarnaSelainHitamPutihDiTengah(string $png): bool
    {
        $g = imagecreatefromstring($png);
        $this->assertNotFalse($g);
        $awal = (int) ((imagesx($g) - QrTtd::LEBAR_LOGO) / 2);

        for ($x = $awal; $x < $awal + QrTtd::LEBAR_LOGO; $x++) {
            for ($y = $awal; $y < $awal + QrTtd::LEBAR_LOGO; $y++) {
                $w = imagecolorsforindex($g, imagecolorat($g, $x, $y));

                if (! in_array([$w['red'], $w['green'], $w['blue']], [[0, 0, 0], [255, 255, 255]], true)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function piksel(string $png, int $x, int $y): int
    {
        $g = imagecreatefromstring($png);
        $this->assertNotFalse($g);

        $w = imagecolorsforindex($g, imagecolorat($g, $x, $y));

        return ($w['red'] << 16) | ($w['green'] << 8) | $w['blue'];
    }
}
