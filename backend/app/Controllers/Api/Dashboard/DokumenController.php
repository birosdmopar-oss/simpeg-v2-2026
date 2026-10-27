<?php

declare(strict_types=1);

namespace App\Controllers\Api\Dashboard;

use App\Controllers\Api\ApiController;
use App\Libraries\Dashboard\Dokumen\DokumenService;
use App\Libraries\Pdf\Pdf;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * F-09 Generator Dokumen (MAKE-037) — padanan legacy `hr/document/*` (`DocumentController` di spek): DUK, Nominatif,
 * Proyeksi Pensiun, Baperjakat. Kontrak endpoint: README modul ini. Role 1, 3, 4, 5, 8 lewat filter route; lingkup,
 * throttle, dan audit di service.
 */
final class DokumenController extends ApiController
{
    private ?DokumenService $service = null;

    public function daftar(string $jenis): ResponseInterface
    {
        return $this->respondSuccess($this->service()->daftar(service('authContext'), $jenis, $this->query()));
    }

    public function pdf(string $jenis): ResponseInterface
    {
        $berkas = $this->service()->unduh(service('authContext'), $jenis, 'pdf', $this->query());

        return Pdf::respon($this->response, $berkas['isi'], $berkas['nama'], $this->request->getGet('inline') === '1');
    }

    public function xlsx(string $jenis): ResponseInterface
    {
        return $this->kirim($this->service()->unduh(service('authContext'), $jenis, 'xlsx', $this->query()));
    }

    public function baperjakatPdf(): ResponseInterface
    {
        $berkas = $this->service()->baperjakat(service('authContext'), 'pdf', $this->payload());

        return Pdf::respon($this->response, $berkas['isi'], $berkas['nama'], $this->request->getGet('inline') === '1');
    }

    public function baperjakatXlsx(): ResponseInterface
    {
        return $this->kirim($this->service()->baperjakat(service('authContext'), 'xlsx', $this->payload()));
    }

    /**
     * @param array{isi: string, nama: string, mime: string} $berkas
     */
    private function kirim(array $berkas): ResponseInterface
    {
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

    private function service(): DokumenService
    {
        return $this->service ??= new DokumenService();
    }
}
