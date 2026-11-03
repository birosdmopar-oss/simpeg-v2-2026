<?php

declare(strict_types=1);

namespace App\Controllers\Api\Integrasi;

use App\Controllers\Api\ApiController;
use App\Libraries\Bkn\Ekinerja\AntreTarikEkinerja;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * `POST api/v1/integrasi/bkn/tarik` (role 1, PRD Fase 6 §6.2, H-10): antre tarik ulang e-Kinerja, respons 202 amplop v2
 * `{jenis, tahun, bulan, antre: true}`. Validasi 422 (`jenis`, `tahun`, `bulan`). Kontrak: README modul.
 */
final class BknTarikController extends ApiController
{
    public function tarik(): ResponseInterface
    {
        $data = (new AntreTarikEkinerja())->antre($this->payload());

        return $this->respondSuccess($data + ['antre' => true], 202);
    }
}
