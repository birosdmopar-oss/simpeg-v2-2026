<?php

declare(strict_types=1);

namespace App\Libraries\Pdf;

use App\Interfaces\PdfRendererInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Titik masuk PDF (ADR-033, WS-2 MAKE-013). Config\Services tidak ditambah entri (aturan kepemilikan Fase 3: Services.php
 * hanya untuk mengganti stub), jadi pemakai mengambil renderer lewat `Pdf::renderer()`; test boleh menyuntik renderer
 * lain lewat `Pdf::pakai()` (dan `Pdf::reset()` di tearDown).
 *
 * Pemakaian (mis. tanda terima usulan Karpeg/Karis WS-1):
 *
 *   $isi = Pdf::renderer()->render(new DokumenPdf('Tanda Terima', view('pdf/tanda_terima', $data), kopHtml: $kop));
 *   return Pdf::respon($this->response, $isi, 'tanda-terima.pdf');
 */
final class Pdf
{
    private static ?PdfRendererInterface $renderer = null;

    private function __construct()
    {
    }

    public static function renderer(): PdfRendererInterface
    {
        return self::$renderer ??= new TcpdfRenderer();
    }

    public static function pakai(PdfRendererInterface $renderer): void
    {
        self::$renderer = $renderer;
    }

    public static function reset(): void
    {
        self::$renderer = null;
    }

    /**
     * Tag `<img>` kode QR (PNG tersemat, `src="@<base64>"` writeHTML TCPDF) untuk $teks — pengganti QRBuilder legacy
     * (`###QRCODE###` di template LKH). Tanpa GD/Imagick → teks kodenya saja (dokumen tetap terbit).
     */
    public static function qrImg(string $teks, int $tinggiPx = 100): string
    {
        $png = (extension_loaded('gd') || extension_loaded('imagick'))
            ? (new \TCPDF2DBarcode($teks, 'QRCODE,M'))->getBarcodePngData(4, 4, [0, 0, 0])
            : false;

        if (! is_string($png) || $png === '') {
            return '<span style="font-size:7pt;">' . esc($teks) . '</span>';
        }

        return '<img src="@' . base64_encode($png) . '" height="' . $tinggiPx . '" alt="Kode QR" />';
    }

    /**
     * Respons unduhan/pratinjau PDF: `application/pdf`, `Content-Disposition` (inline = pratinjau di peramban),
     * `nosniff`, `no-store` (dokumen berisi data pribadi).
     */
    public static function respon(ResponseInterface $response, string $isi, string $nama, bool $inline = false): ResponseInterface
    {
        $aman = preg_replace('/[^A-Za-z0-9._-]+/', '_', $nama) ?? 'dokumen.pdf';

        return $response
            ->setStatusCode(200)
            ->setContentType('application/pdf')
            ->setHeader('Content-Disposition', ($inline ? 'inline' : 'attachment') . '; filename="' . $aman . '"')
            ->setHeader('X-Content-Type-Options', 'nosniff')
            ->setHeader('Cache-Control', 'private, no-store')
            ->setBody($isi);
    }
}
