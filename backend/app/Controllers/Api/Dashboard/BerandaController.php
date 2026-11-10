<?php

declare(strict_types=1);

namespace App\Controllers\Api\Dashboard;

use App\Controllers\Api\ApiController;
use App\Libraries\Dashboard\Beranda\DirekturBeranda;
use App\Libraries\Dashboard\Beranda\RingkasanBeranda;
use App\Libraries\Kepegawaian\Kalkulasi\TanggalBisnis;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Dashboard;

/**
 * Beranda per role (F-13, MAKE-087; PRD Fase 7 v2.0 §5.10). Milik Jalur D. Kontrak: `README.md` modul ini.
 *
 * - `GET api/v1/dashboard/ringkasan` (jwt, role 1–8): bentuk per keluarga role — lihat {@see RingkasanBeranda}.
 * - `GET api/v1/dashboard/direktur` (role 1, 4): masa kerja direktur politeknik — {@see DirekturBeranda}.
 */
final class BerandaController extends ApiController
{
    public function ringkasan(): ResponseInterface
    {
        $layanan = new RingkasanBeranda(db_connect(), config(Dashboard::class), service('cacheService'));

        return $this->respondSuccess($layanan->ringkasan(service('authContext')));
    }

    public function direktur(): ResponseInterface
    {
        $daftar = (new DirekturBeranda(db_connect(), config(Dashboard::class), TanggalBisnis::hariIni()))->daftar();

        return $this->respondSuccess(['items' => $daftar]);
    }
}
