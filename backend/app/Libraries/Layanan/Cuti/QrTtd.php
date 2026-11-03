<?php

declare(strict_types=1);

namespace App\Libraries\Layanan\Cuti;

/**
 * Gambar QR visualisasi tanda tangan BSrE (`imageTTD`) — PRD Fase 6 v1.0f Q6-22 (keputusan Horii 03-11-2026, ikut legacy
 * `Qrbuilder::saveQRLogo($dest, $url, QR_MID_IMG, 300, 10, 75)`): QR berisi URL PDF ber-TTE, koreksi galat tinggi (H),
 * ukuran QR 300 px + margin 10 px (gambar 320×320), logo resmi di tengah dengan lebar 75 px di atas latar putih
 * (*punchout*, setara `logoPunchoutBackground(true)`).
 *
 * Logo bawaan = {@see self::LOGO_BAWAAN}, salinan apa adanya berkas legacy `QR_MID_IMG`
 * (`logo_kemenparekraf_200x200.jpg`, JPEG progresif — dibaca GD). Berkas tidak ada/rusak → QR tanpa logo (tanda tangan
 * tetap jalan). Tanpa ekstensi GD → null (penandatanganan tanpa visualisasi gambar).
 */
final class QrTtd
{
    public const UKURAN = 300;

    public const MARGIN = 10;

    public const LEBAR_LOGO = 75;

    public const LOGO_BAWAAN = APPPATH . 'Assets/esign/logo_qr_ttd.jpg';

    public static function png(string $url, ?string $logo = self::LOGO_BAWAAN): ?string
    {
        if (! extension_loaded('gd') || $url === '') {
            return null;
        }

        $mentah = (new \TCPDF2DBarcode($url, 'QRCODE,H'))->getBarcodePngData(6, 6, [0, 0, 0]);
        $qr     = is_string($mentah) ? imagecreatefromstring($mentah) : false;

        if ($qr === false) {
            return null;
        }

        $sisi   = self::UKURAN + 2 * self::MARGIN;
        $kanvas = imagecreatetruecolor($sisi, $sisi);
        $putih  = (int) imagecolorallocate($kanvas, 255, 255, 255);
        imagefilledrectangle($kanvas, 0, 0, $sisi - 1, $sisi - 1, $putih);
        // Tetangga terdekat (bukan resampling) supaya modul QR tetap hitam/putih tajam, mudah dipindai.
        imagecopyresized($kanvas, $qr, self::MARGIN, self::MARGIN, 0, 0, self::UKURAN, self::UKURAN, imagesx($qr), imagesy($qr));
        self::tempelLogo($kanvas, $logo, $putih);

        // Tulis ke stream memori (bukan ob_*), supaya tidak bercampur dengan buffer keluaran lain.
        $aliran = fopen('php://memory', 'w+b');

        if ($aliran === false || ! imagepng($kanvas, $aliran)) {
            return null;
        }

        rewind($aliran);
        $png = (string) stream_get_contents($aliran);
        fclose($aliran);

        return $png;
    }

    private static function tempelLogo(\GdImage $kanvas, ?string $logo, int $putih): void
    {
        if ($logo === null || $logo === '' || ! is_file($logo) || ! is_readable($logo)) {
            return;
        }

        $isi  = file_get_contents($logo);
        $info = is_string($isi) && $isi !== '' ? @getimagesizefromstring($isi) : false;

        // Hanya JPEG/PNG/GIF yang sah; isi lain (berkas rusak) diabaikan → QR tanpa logo.
        if (! is_string($isi) || $info === false || ! in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF], true)) {
            return;
        }

        $gambar = @imagecreatefromstring($isi);

        if ($gambar === false) {
            return;
        }

        $lebar  = self::LEBAR_LOGO;
        $tinggi = max(1, (int) round(imagesy($gambar) * $lebar / imagesx($gambar)));
        $x      = (int) ((imagesx($kanvas) - $lebar) / 2);
        $y      = (int) ((imagesy($kanvas) - $tinggi) / 2);

        imagefilledrectangle($kanvas, $x, $y, $x + $lebar - 1, $y + $tinggi - 1, $putih);
        imagecopyresampled($kanvas, $gambar, $x, $y, 0, 0, $lebar, $tinggi, imagesx($gambar), imagesy($gambar));
    }
}
