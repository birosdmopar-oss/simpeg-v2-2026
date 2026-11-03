<?php

declare(strict_types=1);

namespace App\Libraries\Layanan\Cuti;

/**
 * Gambar QR visualisasi tanda tangan BSrE (`imageTTD`, keputusan Horii 03-11-2026 F6-18 K3b, ikut legacy
 * `L_cuti.php` `saveQRLogo(…, 300, …)`): PNG 300×300 px berisi URL PDF ber-TTE, koreksi galat tinggi (H) agar logo di
 * tengah tetap terbaca. Logo opsional (`$logo` = path PNG di luar repo; kosong = tanpa logo). Tanpa ekstensi GD → null
 * (penandatanganan tetap jalan tanpa visualisasi gambar).
 */
final class QrTtd
{
    public const UKURAN = 300;

    public static function png(string $url, ?string $logo = null): ?string
    {
        if (! extension_loaded('gd') || $url === '') {
            return null;
        }

        $mentah = (new \TCPDF2DBarcode($url, 'QRCODE,H'))->getBarcodePngData(6, 6, [0, 0, 0]);
        $qr     = is_string($mentah) ? imagecreatefromstring($mentah) : false;

        if ($qr === false) {
            return null;
        }

        $kanvas = imagecreatetruecolor(self::UKURAN, self::UKURAN);
        imagefill($kanvas, 0, 0, (int) imagecolorallocate($kanvas, 255, 255, 255));
        imagecopyresampled($kanvas, $qr, 0, 0, 0, 0, self::UKURAN, self::UKURAN, imagesx($qr), imagesy($qr));

        if ($logo !== null && $logo !== '' && is_file($logo) && is_readable($logo)) {
            $gambarLogo = imagecreatefromstring((string) file_get_contents($logo));

            if ($gambarLogo !== false) {
                $sisi = (int) (self::UKURAN / 4);
                $pos  = (int) ((self::UKURAN - $sisi) / 2);
                imagecopyresampled($kanvas, $gambarLogo, $pos, $pos, 0, 0, $sisi, $sisi, imagesx($gambarLogo), imagesy($gambarLogo));
            }
        }

        ob_start();
        imagepng($kanvas);

        return (string) ob_get_clean();
    }
}
