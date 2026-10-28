<?php

declare(strict_types=1);

namespace App\Controllers\Api\Layanan;

use App\Controllers\Api\ApiController;
use App\Libraries\Layanan\LayananDashboardService;
use App\Libraries\Layanan\PetugasLayananService;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Dashboard Status Layanan C-10 (PRD Fase 4 §5.5, §6.10.1) — route `Config/RoutesLayananDashboard.php` (role 1–7; role 8
 * → 403). Controller tipis: lingkup & tampilan di LayananDashboardService. Dokumentasi: README.md bagian "Dashboard & PIC".
 */
class LayananDashboardController extends ApiController
{
    public function status(): ResponseInterface
    {
        $query = $this->request->getGet();

        return $this->respondSuccess((new LayananDashboardService())->status(service('authContext'), is_array($query) ? $query : []));
    }

    public function jenis(): ResponseInterface
    {
        return $this->respondSuccess((new PetugasLayananService())->jenisAktif(service('authContext')));
    }
}
