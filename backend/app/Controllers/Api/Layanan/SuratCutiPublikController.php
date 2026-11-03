<?php

declare(strict_types=1);

namespace App\Controllers\Api\Layanan;

use App\Controllers\Api\ApiController;
use App\Libraries\Layanan\Cuti\VerifikasiSuratCutiService;
use App\Libraries\Pdf\Pdf;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Route publik surat cuti ber-token, tanpa `jwt` (filter `throttle`) — PRD Fase 6 v1.0f §7.2, keputusan Horii 03-11-2026:
 * - `GET api/v1/publik/surat-cuti/{token}` — verifikasi QR kaki surat (Q6-23, AC-02.8), data minimal tanpa NIP;
 * - `GET api/v1/publik/surat-cuti/{token}/pdf` — PDF surat ber-TTE `inline` (Q6-22, AC-02.7), isi QR tanda tangan.
 * Header `X-Robots-Tag: noindex, nofollow` dan `Cache-Control: no-store` dipasang sebelum service berjalan sehingga ikut di
 * respons 200 maupun 404 seragam (pola `VerifikasiSlipController`).
 */
final class SuratCutiPublikController extends ApiController
{
    protected bool $castNumericParams = false;

    public function verifikasi(string $token): ResponseInterface
    {
        $this->headerPublik();

        return $this->respondSuccess($this->service()->verifikasi($token));
    }

    public function pdf(string $token): ResponseInterface
    {
        $this->headerPublik();
        $berkas = $this->service()->pdfTte($token);

        return Pdf::respon($this->response, $berkas['isi'], $berkas['nama'], true)->setHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    private function headerPublik(): void
    {
        $this->response->setHeader('X-Robots-Tag', 'noindex, nofollow')->setHeader('Cache-Control', 'no-store');
    }

    private function service(): VerifikasiSuratCutiService
    {
        return service('verifikasiSuratCutiService');
    }
}
