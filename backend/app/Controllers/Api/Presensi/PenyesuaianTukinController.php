<?php

declare(strict_types=1);

namespace App\Controllers\Api\Presensi;

use App\Controllers\Api\ApiController;
use App\Libraries\Presensi\Laporan\PenyesuaianTukinService;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * CRUD penyesuaian Tukin per NIP (PRD 5B §6.1, §7.3.10, F5B-14) — controller tipis, role route 1; validasi, kunci per NIP
 * (C-1), dan audit di {@see PenyesuaianTukinService}. Kontrak: README.md bagian "Penyesuaian Tukin".
 */
final class PenyesuaianTukinController extends ApiController
{
    public function index(): ResponseInterface
    {
        $query = $this->request->getGet();

        return $this->respondSuccess($this->service()->daftar(service('authContext'), is_array($query) ? $query : []));
    }

    public function create(): ResponseInterface
    {
        return $this->respondSuccess($this->service()->tambah(service('authContext'), $this->payload()), 201);
    }

    public function update(int $id): ResponseInterface
    {
        return $this->respondSuccess($this->service()->ubah(service('authContext'), $id, $this->payload()));
    }

    public function delete(int $id): ResponseInterface
    {
        return $this->respondSuccess($this->service()->hapus(service('authContext'), $id));
    }

    private function service(): PenyesuaianTukinService
    {
        return new PenyesuaianTukinService();
    }
}
