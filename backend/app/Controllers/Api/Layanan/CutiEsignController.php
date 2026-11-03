<?php

declare(strict_types=1);

namespace App\Controllers\Api\Layanan;

use App\Controllers\Api\ApiController;
use App\Libraries\Layanan\Cuti\EsignCutiService;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Penandatanganan surat cuti ber-TTE (PRD Fase 4 §6.5 C-05; PRD Fase 6 F6-18, keputusan Horii 03-11-2026) —
 * `POST cuti/{id}/esign`: semua akun login lolos filter, hanya penanda tangan pemilik tugas yang diterima service (403
 * selainnya), batas 5 percobaan gagal / 15 menit (429). Passphrase hanya dibaca dari body JSON dan tidak pernah dicatat,
 * disimpan, atau dikembalikan.
 */
final class CutiEsignController extends ApiController
{
    public function esign(int $id): ResponseInterface
    {
        $agent = $this->request->getUserAgent();

        return $this->respondSuccess(
            $this->service()->terbitkan(service('authContext'), $id, $this->payload(), $this->request->getIPAddress(), $agent->getAgentString()),
        );
    }

    private function service(): EsignCutiService
    {
        return service('esignCutiService');
    }
}
