<?php

declare(strict_types=1);

namespace App\Controllers\Api\Kepegawaian;

use App\Controllers\Api\ApiController;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * B-19 Struktur Organisasi (WS-2 MAKE-012).
 *
 *   GET struktur-organisasi?id_unit_satker=   bagan ikut templat legacy so_012025: '0' kementerian, '<id_unit>',
 *                                            'sat_<id_satker>' (hanya opsi aktif; selain itu 422); semua pengguna login
 */
class StrukturController extends ApiController
{
    protected bool $castNumericParams = false;

    public function index(): ResponseInterface
    {
        $query = $this->request->getGet();

        return $this->respondSuccess(service('strukturService')->pohon(service('authContext'), is_array($query) ? $query : []));
    }
}
