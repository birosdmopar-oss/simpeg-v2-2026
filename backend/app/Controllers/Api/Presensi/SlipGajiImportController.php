<?php

declare(strict_types=1);

namespace App\Controllers\Api\Presensi;

use App\Controllers\Api\ApiController;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Presensi\Slip\ImporSlipService;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * D-10 impor Slip Gaji (PRD 5A §6.2, §7.2) — controller tipis; aturan & akses di {@see ImporSlipService}.
 *
 * - `POST slip-gaji/impor/pratinjau` (multipart `berkas`, `filter_bulan?`, `filter_tahun?`) → token pratinjau + baris.
 * - `POST slip-gaji/impor/pratinjau/{token}/commit` (body `{timpa?: bool}`) → hasil commit.
 *
 * Role route 1, 3 (`RoutesPresensi`); role 3 tanpa `id_satker` → 403 di service (SG-2).
 */
final class SlipGajiImportController extends ApiController
{
    protected bool $castNumericParams = false;

    public function pratinjau(): ResponseInterface
    {
        $berkas = $this->request->getFile('berkas');

        return $this->respondSuccess($this->service()->pratinjau(
            $this->auth(),
            $berkas instanceof UploadedFile ? $berkas : null,
            $this->request->getPost('filter_bulan'),
            $this->request->getPost('filter_tahun'),
        ));
    }

    public function commit(string $token): ResponseInterface
    {
        $body = $this->payload();

        return $this->respondSuccess($this->service()->commit($this->auth(), $token, $body['timpa'] ?? null));
    }

    private function service(): ImporSlipService
    {
        return new ImporSlipService();
    }

    private function auth(): AuthContext
    {
        return service('authContext');
    }
}
