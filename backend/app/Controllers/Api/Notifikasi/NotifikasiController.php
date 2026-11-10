<?php

declare(strict_types=1);

namespace App\Controllers\Api\Notifikasi;

use App\Controllers\Api\ApiController;
use App\Libraries\Notifikasi\Pusat\PusatNotifikasiService;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * F-08 Pusat Notifikasi & Inbox (MAKE-085) — padanan legacy `hr/notification/{index,need_approval,ah,delete}` dan
 * penghitung lonceng `MY_Controller:20-175`. Kontrak: README modul ini. Role lewat filter route + service.
 */
final class NotifikasiController extends ApiController
{
    private ?PusatNotifikasiService $service = null;

    public function ringkasan(): ResponseInterface
    {
        return $this->tanpaCache($this->respondSuccess($this->service()->ringkasan(service('authContext'))));
    }

    public function index(): ResponseInterface
    {
        return $this->tanpaCache($this->respondSuccess($this->service()->daftar(service('authContext'), $this->query())));
    }

    public function baca(): ResponseInterface
    {
        $payload = $this->request->getJSON(true);

        return $this->respondSuccess($this->service()->baca(service('authContext'), is_array($payload) ? $payload : []));
    }

    public function hapus(string $sumber, int|string $id): ResponseInterface
    {
        return $this->respondSuccess($this->service()->hapus(service('authContext'), $sumber, (string) $id));
    }

    public function butuhApproval(): ResponseInterface
    {
        return $this->tanpaCache($this->respondSuccess($this->service()->butuhApproval(service('authContext'), $this->query())));
    }

    public function historiApproval(): ResponseInterface
    {
        return $this->tanpaCache($this->respondSuccess($this->service()->historiApproval(service('authContext'), $this->query())));
    }

    private function tanpaCache(ResponseInterface $response): ResponseInterface
    {
        return $response->setHeader('Cache-Control', 'private, no-store');
    }

    /**
     * @return array<string, mixed>
     */
    private function query(): array
    {
        $query = $this->request->getGet();

        return is_array($query) ? $query : [];
    }

    private function service(): PusatNotifikasiService
    {
        return $this->service ??= new PusatNotifikasiService();
    }
}
