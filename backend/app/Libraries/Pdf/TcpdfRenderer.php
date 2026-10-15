<?php

declare(strict_types=1);

namespace App\Libraries\Pdf;

use App\Interfaces\PdfRendererInterface;
use TCPDF;

/**
 * Implementasi TCPDF (ADR-033; `tecnickcom/tcpdf` ^6.6, LGPL-3.0-or-later, dipasang lewat Composer — tidak disalin ke
 * repo). Meniru `PdfCreator extends TCPDF` legacy: kop HTML hanya di halaman pertama (margin atas halaman pertama
 * `marginAtasKop`, berikutnya 10 mm), kaki HTML di setiap halaman, `writeHTML` untuk badan.
 *
 * [V2] Font: legacy memakai `arial`/`bookos` (berkas font lokal server legacy); v2 memakai font inti `helvetica`
 * (tanpa berkas font, tanpa unduhan runtime — ADR-033 §5). Direktori cache TCPDF = `WRITEPATH/cache/tcpdf/`.
 */
final class TcpdfRenderer implements PdfRendererInterface
{
    public const FONT = 'helvetica';

    public const PEMBUAT = 'SIMPEG v2';

    public function render(DokumenPdf $dokumen): string
    {
        self::siapkanKonfigurasi();

        $pdf = new class ($dokumen) extends TCPDF {
            public function __construct(private readonly DokumenPdf $dok)
            {
                parent::__construct($dok->orientasi, 'mm', $dok->ukuran, true, 'UTF-8', false);
            }

            // phpcs:ignore -- nama method milik TCPDF
            public function Header(): void
            {
                if ($this->dok->kopHtml === '') {
                    return;
                }

                if ($this->getPage() === 1) {
                    $this->SetY(8);
                    $this->SetFont(TcpdfRenderer::FONT, '', 11);
                    $this->writeHTML($this->dok->kopHtml, false, true, false, true);
                    $this->SetTopMargin($this->dok->marginAtasKop);
                } else {
                    $this->SetTopMargin(10);
                }
            }

            // phpcs:ignore -- nama method milik TCPDF
            public function Footer(): void
            {
                if ($this->dok->kakiHtml === '') {
                    return;
                }

                $this->SetFont(TcpdfRenderer::FONT, '', 7);
                $this->SetY(-10);
                $this->writeHTML($this->dok->kakiHtml, false, true, false, true);
            }
        };

        $pdf->SetCreator(self::PEMBUAT);
        $pdf->SetAuthor(self::PEMBUAT);
        $pdf->SetTitle($dokumen->judul);
        $pdf->setPrintHeader($dokumen->kopHtml !== '');
        $pdf->setPrintFooter($dokumen->kakiHtml !== '');
        $pdf->SetMargins($dokumen->marginSisi, $dokumen->kopHtml !== '' ? $dokumen->marginAtasKop : 10, $dokumen->marginSisi);
        $pdf->SetAutoPageBreak(true, $dokumen->kakiHtml !== '' ? 15 : 10);
        $pdf->SetFont(self::FONT, '', 10);
        $pdf->AddPage();
        $pdf->writeHTML($dokumen->html, true, false, true, false, '');

        return $pdf->Output('dokumen.pdf', 'S');
    }

    /**
     * Konstanta TCPDF dibaca sekali saat kelas dimuat: arahkan cache ke WRITEPATH sebelum `new TCPDF`.
     *
     * CR-050 (QA B-F2): TCPDF 6.11 memicu `E_USER_DEPRECATED` "TCPDF is deprecated: migrate to tc-lib-pdf" sekali per
     * proses di konstruktornya, yang ditulis CI4 sebagai WARNING ke log setiap render. Diredam lewat saklar resmi TCPDF
     * `TCPDF_SILENCE_DEPRECATION` — hanya notice milik TCPDF itu, didefinisikan di sini (sebelum `new TCPDF`), bukan
     * meredam deprecation lain secara global. Migrasi ke tc-lib-pdf dicatat di ADR-033 (keputusan terpisah).
     */
    private static function siapkanKonfigurasi(): void
    {
        if (! defined('TCPDF_SILENCE_DEPRECATION')) {
            define('TCPDF_SILENCE_DEPRECATION', true);
        }

        if (! defined('K_PATH_CACHE')) {
            $dir = rtrim(WRITEPATH, '/\\') . '/cache/tcpdf/';

            if (! is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }

            define('K_PATH_CACHE', $dir);
        }
    }
}
