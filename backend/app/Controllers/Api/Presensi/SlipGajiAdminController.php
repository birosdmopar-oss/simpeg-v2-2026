<?php

declare(strict_types=1);

namespace App\Controllers\Api\Presensi;

use App\Controllers\Api\ApiController;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Pdf\Pdf;
use App\Libraries\Presensi\Slip\SlipGajiAdminService;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * D-11 jalur admin Slip Gaji (PRD 5A §6.2) — monitoring, detail, koreksi, buka ulang, cetak; cakupan role 3 di
 * {@see SlipGajiAdminService}.
 */
final class SlipGajiAdminController extends ApiController
{
    public function daftar(): ResponseInterface
    {
        $q = $this->request->getGet();

        return $this->respondSuccess($this->service()->daftar($this->auth(), is_array($q) ? $q : []));
    }

    public function detail(int $id): ResponseInterface
    {
        return $this->respondSuccess($this->service()->detail($this->auth(), $id));
    }

    public function koreksi(int $id): ResponseInterface
    {
        return $this->respondSuccess($this->service()->koreksi($this->auth(), $id, $this->payload()));
    }

    public function bukaUlang(int $id): ResponseInterface
    {
        return $this->respondSuccess($this->service()->bukaUlang($this->auth(), $id));
    }

    public function pdf(int $id): ResponseInterface
    {
        $pdf = $this->service()->pdf($this->auth(), $id);

        return Pdf::respon($this->response, $pdf['isi'], $pdf['nama']);
    }

    private function service(): SlipGajiAdminService
    {
        return new SlipGajiAdminService();
    }

    private function auth(): AuthContext
    {
        return service('authContext');
    }
}
