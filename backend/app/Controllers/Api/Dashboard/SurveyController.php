<?php

declare(strict_types=1);

namespace App\Controllers\Api\Dashboard;

use App\Controllers\Api\ApiController;
use App\Libraries\Dashboard\Interaksi\Survei\RingkasanSkl;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * F-11 Survei Kepuasan Layanan / SKL (MAKE-084, Jalur B) — padanan legacy `hr/survey` + `hr/announcement` submit SKL.
 * Aturan bisnis di `Libraries/Dashboard/Interaksi/Survei`.
 */
final class SurveyController extends ApiController
{
    public function ringkasan(): ResponseInterface
    {
        $query = $this->request->getGet();

        return $this->respondSuccess((new RingkasanSkl(db_connect()))->ringkasan(service('authContext'), is_array($query) ? $query : []));
    }
}
