<?php

declare(strict_types=1);

namespace App\Controllers\Api\Layanan;

use App\Controllers\Api\ApiController;
use App\Libraries\Layanan\Cuti\SaldoCutiService;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Kelola saldo cuti tahunan (PRD Fase 4 §6.3.6) — role route 1 dan 3; kontrak: README.md bagian "Cuti".
 */
final class CutiSaldoController extends ApiController
{
    public function index(): ResponseInterface
    {
        $query = $this->request->getGet();

        return $this->respondSuccess($this->saldo()->daftar(service('authContext'), is_array($query) ? $query : []));
    }

    public function create(): ResponseInterface
    {
        return $this->respondSuccess($this->saldo()->tambah(service('authContext'), $this->payload()), 201);
    }

    public function update(int $id): ResponseInterface
    {
        return $this->respondSuccess($this->saldo()->ubah(service('authContext'), $id, $this->payload()));
    }

    public function delete(int $id): ResponseInterface
    {
        return $this->respondSuccess($this->saldo()->hapus(service('authContext'), $id));
    }

    private function saldo(): SaldoCutiService
    {
        return service('saldoCutiService');
    }
}
