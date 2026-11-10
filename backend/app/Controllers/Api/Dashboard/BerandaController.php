<?php

declare(strict_types=1);

namespace App\Controllers\Api\Dashboard;

use App\Controllers\Api\ApiController;
use App\Libraries\Dashboard\Beranda\RingkasanBeranda;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Dashboard;

/**
 * Beranda per role (F-13, MAKE-087; PRD Fase 7 v2.0 §5.10). Milik Jalur D. Kontrak: `README.md` modul ini.
 *
 * - `GET api/v1/dashboard/ringkasan` (jwt, role 1–8): bentuk per keluarga role — lihat {@see RingkasanBeranda}.
 */
final class BerandaController extends ApiController
{
    public function ringkasan(): ResponseInterface
    {
        $layanan = new RingkasanBeranda(db_connect(), config(Dashboard::class), service('cacheService'));

        return $this->respondSuccess($layanan->ringkasan(service('authContext')));
    }
}
