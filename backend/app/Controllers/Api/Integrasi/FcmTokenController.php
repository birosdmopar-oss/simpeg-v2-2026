<?php

declare(strict_types=1);

namespace App\Controllers\Api\Integrasi;

use App\Controllers\Api\ApiController;
use App\Libraries\Push\TokenFcmService;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * `POST|DELETE api/v1/integrasi/fcm-token` (jwt, semua role; PRD Fase 6 §6.2, F6-13) — daftar/lepas token push web.
 * Kontrak: README modul.
 */
final class FcmTokenController extends ApiController
{
    public function daftar(): ResponseInterface
    {
        return $this->respondSuccess((new TokenFcmService(db_connect()))->daftar(service('authContext'), $this->payload()));
    }

    public function lepas(): ResponseInterface
    {
        return $this->respondSuccess((new TokenFcmService(db_connect()))->lepas(service('authContext'), $this->payload()));
    }
}
