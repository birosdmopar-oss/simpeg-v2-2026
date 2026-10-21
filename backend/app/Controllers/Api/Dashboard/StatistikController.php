<?php

declare(strict_types=1);

namespace App\Controllers\Api\Dashboard;

use App\Controllers\Api\ApiController;
use App\Libraries\Dashboard\Statistik\StatistikService;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * F-03 Widget Statistik Kepegawaian (MAKE-036) — padanan legacy `hr/chart/*` (`ChartController` di spek).
 * Kontrak endpoint: README modul ini. Role 1, 3, 4, 5, 8 lewat filter route; lingkup & scope di service.
 */
final class StatistikController extends ApiController
{
    private ?StatistikService $service = null;

    public function lingkup(): ResponseInterface
    {
        return $this->respondSuccess($this->service()->lingkupTersedia(service('authContext')));
    }

    public function statistik(string $jenis): ResponseInterface
    {
        return $this->respondSuccess($this->service()->statistik(service('authContext'), $jenis, $this->query()));
    }

    public function xlsx(string $jenis): ResponseInterface
    {
        $berkas = $this->service()->xlsx(service('authContext'), $jenis, $this->query());

        return $this->response
            ->setStatusCode(200)
            ->setHeader('Content-Type', $berkas['mime'])
            ->setHeader('Content-Disposition', 'attachment; filename="' . $berkas['nama'] . '"')
            ->setHeader('X-Content-Type-Options', 'nosniff')
            ->setHeader('Cache-Control', 'private, no-store')
            ->setBody($berkas['isi']);
    }

    /**
     * @return array<string, mixed>
     */
    private function query(): array
    {
        $query = $this->request->getGet();

        return is_array($query) ? $query : [];
    }

    private function service(): StatistikService
    {
        return $this->service ??= new StatistikService();
    }
}
