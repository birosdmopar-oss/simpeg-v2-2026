<?php

declare(strict_types=1);

namespace App\Interfaces;

use App\Libraries\Pdf\DokumenPdf;

/**
 * Pembangkit PDF dari HTML (ADR-033, WS-2 M5 MAKE-013) — antarmuka tipis supaya pemakai (LKH, bagan struktur
 * organisasi, tanda terima usulan, DRH) tidak bergantung pada library. Implementasi bawaan: TCPDF
 * (`App\Libraries\Pdf\TcpdfRenderer`); cadangan dompdf bisa dipasang di balik antarmuka yang sama.
 *
 * Ambil lewat `App\Libraries\Pdf\Pdf::renderer()`.
 */
interface PdfRendererInterface
{
    /**
     * Render dokumen menjadi biner PDF (diawali `%PDF`).
     */
    public function render(DokumenPdf $dokumen): string;
}
