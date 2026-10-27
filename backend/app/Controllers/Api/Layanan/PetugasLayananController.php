<?php

declare(strict_types=1);

namespace App\Controllers\Api\Layanan;

use App\Controllers\Api\ApiController;
use App\Libraries\Layanan\PetugasLayananService;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * PIC layanan `layanan/petugas` C-10 (PRD Fase 4 §5.5, §6.10.2) — route role 1–7; tulis role 1 saja (403 di service).
 * Dokumentasi: README.md bagian "Dashboard & PIC".
 */
class PetugasLayananController extends ApiController
{
    public function index(): ResponseInterface
    {
        $query = $this->request->getGet();

        return $this->respondSuccess($this->petugas()->daftar(service('authContext'), is_array($query) ? $query : []));
    }

    public function create(): ResponseInterface
    {
        return $this->respondSuccess($this->petugas()->tambah(service('authContext'), $this->payload()), 201);
    }

    public function update(int $id): ResponseInterface
    {
        return $this->respondSuccess($this->petugas()->ubah(service('authContext'), $id, $this->payload()));
    }

    public function delete(int $id): ResponseInterface
    {
        return $this->respondSuccess($this->petugas()->hapus(service('authContext'), $id));
    }

    private function petugas(): PetugasLayananService
    {
        return new PetugasLayananService();
    }
}
