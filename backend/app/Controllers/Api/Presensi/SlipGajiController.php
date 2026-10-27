<?php

declare(strict_types=1);

namespace App\Controllers\Api\Presensi;

use App\Controllers\Api\ApiController;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Pdf\Pdf;
use App\Libraries\Presensi\Slip\SlipGajiService;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * D-11 jalur pegawai Slip Gaji (PRD 5A §6.1) — controller tipis; aturan di {@see SlipGajiService}. NIP selalu dari token;
 * parameter NIP klien diabaikan.
 */
final class SlipGajiController extends ApiController
{
    protected bool $castNumericParams = false;

    public function daftar(): ResponseInterface
    {
        return $this->respondSuccess($this->service()->daftar($this->auth()));
    }

    public function status(string $periode): ResponseInterface
    {
        return $this->respondSuccess($this->service()->status($this->auth(), $periode));
    }

    public function buka(string $periode): ResponseInterface
    {
        return $this->respondSuccess($this->service()->buka($this->auth(), $periode, $this->request->getIPAddress()));
    }

    public function pdf(string $periode): ResponseInterface
    {
        $pdf = $this->service()->pdf($this->auth(), $periode);

        return Pdf::respon($this->response, $pdf['isi'], $pdf['nama']);
    }

    private function service(): SlipGajiService
    {
        return new SlipGajiService();
    }

    private function auth(): AuthContext
    {
        return service('authContext');
    }
}
