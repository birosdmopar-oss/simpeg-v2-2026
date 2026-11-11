<?php

declare(strict_types=1);

namespace App\Controllers\Api\Dashboard;

use App\Controllers\Api\ApiController;
use App\Libraries\Dashboard\Interaksi\Survei\RekapSkl;
use App\Libraries\Dashboard\Interaksi\Survei\RingkasanSkl;
use App\Libraries\Dashboard\Interaksi\Survei\SurveiLayananService;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * F-11 Survei Kepuasan Layanan / SKL (MAKE-084, Jalur B) — padanan legacy `hr/survey` + `hr/announcement` submit SKL.
 * Aturan bisnis di `Libraries/Dashboard/Interaksi/Survei`.
 */
final class SurveyController extends ApiController
{
    public function saya(): ResponseInterface
    {
        return $this->respondSuccess((new SurveiLayananService(db_connect()))->saya(service('authContext')));
    }

    public function kirim(): ResponseInterface
    {
        $hasil = (new SurveiLayananService(db_connect()))->kirim(service('authContext'), $this->payload());

        return $this->respondSuccess($hasil, $hasil['status'] === 'tercatat' ? 201 : 200);
    }

    public function index(): ResponseInterface
    {
        $query = $this->request->getGet();
        $query = is_array($query) ? $query : [];
        $rekap = new RekapSkl(db_connect());

        if (($query['format'] ?? null) === 'xlsx') {
            $berkas = $rekap->xlsx(service('authContext'), $query);

            return $this->response
                ->setStatusCode(200)
                ->setHeader('Content-Type', $berkas['mime'])
                ->setHeader('Content-Disposition', 'attachment; filename="' . $berkas['nama'] . '"')
                ->setHeader('X-Content-Type-Options', 'nosniff')
                ->setHeader('Cache-Control', 'private, no-store')
                ->setBody($berkas['isi']);
        }

        return $this->respondSuccess($rekap->halaman(service('authContext'), $query));
    }

    public function ringkasan(): ResponseInterface
    {
        $query = $this->request->getGet();

        return $this->respondSuccess((new RingkasanSkl(db_connect()))->ringkasan(service('authContext'), is_array($query) ? $query : []));
    }
}
