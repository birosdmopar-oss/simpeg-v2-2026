<?php

declare(strict_types=1);

namespace App\Controllers\Api\Layanan;

use App\Controllers\Api\ApiController;
use App\Libraries\Layanan\Cuti\PetugasApprovalCutiService;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Petugas approval cuti `pa_layanan` (PRD Fase 4 §6.4.8) — role route 1 dan 3; kontrak: README.md bagian "Cuti".
 */
final class CutiPetugasApprovalController extends ApiController
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

    private function petugas(): PetugasApprovalCutiService
    {
        return service('petugasApprovalCutiService');
    }
}
