<?php

declare(strict_types=1);

namespace App\Libraries\Pdf;

use InvalidArgumentException;

/**
 * Isi satu dokumen PDF (ADR-033): HTML badan + kop halaman pertama + kaki setiap halaman, seperti legacy
 * `application/libraries/PdfCreator.php` (setHeaderHtml/setFooterHtml/setTopMargin_firstPage). HTML WAJIB sudah di-escape
 * oleh pemanggil (template v2 memakai `esc()`).
 */
final class DokumenPdf
{
    public const ORIENTASI = ['P', 'L'];

    /**
     * Token di `kakiHtml` yang diganti renderer dengan nomor halaman berjalan dan jumlah halaman ("Halaman x dari y").
     */
    public const TOKEN_HALAMAN = '{halaman}';

    public const TOKEN_TOTAL = '{total}';

    /**
     * @param string $judul          metadata Title PDF
     * @param string $html           badan dokumen (writeHTML)
     * @param string $kopHtml        kop, hanya di halaman pertama (legacy Header())
     * @param string $kakiHtml       kaki di setiap halaman (legacy Footer())
     * @param string $orientasi      P (potret) | L (lanskap)
     * @param string $ukuran         ukuran kertas TCPDF, mis. A4, F4, LEGAL
     * @param float  $marginAtasKop  margin atas halaman pertama bila ada kop (mm; legacy 40)
     * @param float  $marginSisi     margin kiri/kanan (mm)
     */
    public function __construct(
        public readonly string $judul,
        public readonly string $html,
        public readonly string $kopHtml = '',
        public readonly string $kakiHtml = '',
        public readonly string $orientasi = 'P',
        public readonly string $ukuran = 'A4',
        public readonly float $marginAtasKop = 40.0,
        public readonly float $marginSisi = 15.0,
    ) {
        if (! in_array($orientasi, self::ORIENTASI, true)) {
            throw new InvalidArgumentException("Orientasi PDF '{$orientasi}' tidak valid (P/L).");
        }
    }
}
