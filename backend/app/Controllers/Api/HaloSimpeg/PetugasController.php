<?php

declare(strict_types=1);

namespace App\Controllers\Api\HaloSimpeg;

use App\Libraries\HaloSimpeg\PetugasService;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Master petugas Halo Simpeg (E-02, MAKE-070): `petugas/saya` role 1–7; selebihnya role 1. Kontrak: README folder ini.
 */
final class PetugasController extends HaloSimpegController
{
    public function index(): ResponseInterface
    {
        return $this->tanpaCache($this->respondSuccess($this->service()->daftar($this->query())));
    }

    public function kandidat(): ResponseInterface
    {
        return $this->tanpaCache($this->respondSuccess($this->service()->kandidat($this->query())));
    }

    public function saya(): ResponseInterface
    {
        return $this->tanpaCache($this->respondSuccess($this->service()->saya(service('authContext')->idPengguna())));
    }

    public function create(): ResponseInterface
    {
        return $this->respondSuccess($this->service()->buat($this->payload()), 201);
    }

    public function update(int|string $id): ResponseInterface
    {
        return $this->respondSuccess($this->service()->ubah($this->idValid($id), $this->payload()));
    }

    public function status(int|string $id): ResponseInterface
    {
        return $this->respondSuccess($this->service()->setStatus($this->idValid($id), $this->payload()['status'] ?? null));
    }

    public function delete(int|string $id): ResponseInterface
    {
        return $this->respondSuccess($this->service()->hapus($this->idValid($id)));
    }

    private function service(): PetugasService
    {
        return new PetugasService();
    }
}
