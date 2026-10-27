<?php

declare(strict_types=1);

namespace App\Controllers\Api\Presensi;

use App\Controllers\Api\ApiController;
use App\Libraries\Presensi\Slip\VerifikasiSlipService;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * D-14 verifikasi QR publik `GET api/v1/publik/verifikasi-slip/{token}` (PRD 5A §6.3, §7.4) — satu-satunya route 5A
 * tanpa `jwt` (filter `throttle`). Header `X-Robots-Tag: noindex, nofollow` dan `Cache-Control: no-store` dipasang sebelum
 * service berjalan sehingga ikut di respons 200 maupun 404 (envelope galat memakai respons yang sama).
 */
final class VerifikasiSlipController extends ApiController
{
    protected bool $castNumericParams = false;

    public function verifikasi(string $token): ResponseInterface
    {
        $this->response->setHeader('X-Robots-Tag', 'noindex, nofollow')->setHeader('Cache-Control', 'no-store');

        return $this->respondSuccess((new VerifikasiSlipService())->verifikasi($token));
    }
}
