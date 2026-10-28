<?php

declare(strict_types=1);

namespace App\Controllers\Api\Layanan;

use App\Controllers\Api\ApiController;
use App\Libraries\Layanan\Cuti\EsignCutiService;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Penerbitan surat cuti ber-TTE (PRD Fase 4 §6.5, C-05) — `POST cuti/{id}/esign`, role 1 dan 3. Passphrase hanya dibaca
 * dari body JSON dan tidak pernah dicatat atau dikembalikan.
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
