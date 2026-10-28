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

    /**
     * [V2] Ekspor CSV/XLSX data Status Layanan sesuai filter (MAKE-046); role & lingkup sama dengan `status()`.
     */
    public function ekspor(): ResponseInterface
    {
        $query  = $this->request->getGet();
        $berkas = (new LayananDashboardService())->ekspor(service('authContext'), is_array($query) ? $query : []);

        return $this->response
            ->setStatusCode(200)
            ->setHeader('Content-Type', $berkas['mime'])
            ->setHeader('Content-Disposition', 'attachment; filename="' . $berkas['nama'] . '"')
            ->setHeader('X-Content-Type-Options', 'nosniff')
            ->setHeader('Cache-Control', 'no-store')
            ->setBody($berkas['isi']);
    }

    public function jenis(): ResponseInterface
    {
        return $this->respondSuccess((new PetugasLayananService())->jenisAktif(service('authContext')));
    }
}
