<?php

declare(strict_types=1);

namespace App\Controllers\Api\Konten;

use App\Controllers\Api\ApiController;
use App\Exceptions\ValidationException;
use App\Libraries\Dashboard\Konten\Berita\BeritaService;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * F-07 Berita (MAKE-081; PRD Fase 7 v2.0 §5.5). Padanan legacy `hr/news/*`. Baca = semua role login; kelola = role 1, 3;
 * persetujuan = role 1. Aturan visibilitas, kepemilikan role 3, dan validasi di {@see BeritaService}. Route di
 * `Config\RoutesKonten`.
 */
final class BeritaController extends ApiController
{
    /** Portal (bawaan) atau daftar kelola bila `?kelola=1` (role 1, 3). */
    public function index(): ResponseInterface
    {
        $q    = $this->query();
        $auth = service('authContext');
        $data = ($q['kelola'] ?? '') === '1' ? $this->service()->kelola($auth, $q) : $this->service()->portal($auth, $q);

        return $this->respondSuccess($data)->setHeader('Cache-Control', 'private, no-store');
    }

    public function show(int|string $id): ResponseInterface
    {
        return $this->respondSuccess($this->service()->detail(service('authContext'), $this->idValid($id)))->setHeader('Cache-Control', 'private, no-store');
    }

    public function create(): ResponseInterface
    {
        return $this->respondSuccess($this->service()->buat(service('authContext'), $this->payload()), 201);
    }

    public function update(int|string $id): ResponseInterface
    {
        return $this->respondSuccess($this->service()->ubah(service('authContext'), $this->idValid($id), $this->payload()));
    }

    public function delete(int|string $id): ResponseInterface
    {
        $this->service()->hapus(service('authContext'), $this->idValid($id));

        return $this->respondSuccess(null);
    }

    public function proses(int|string $id): ResponseInterface
    {
        return $this->respondSuccess($this->service()->proses(service('authContext'), $this->idValid($id), $this->payload()));
    }

    /** Pembaca membuka portal → penanda berita miliknya dihapus (Q7-09). */
    public function dibuka(): ResponseInterface
    {
        $this->service()->dibuka(service('authContext'));

        return $this->respondSuccess(null);
    }

    private function service(): BeritaService
    {
        return new BeritaService();
    }

    private function idValid(int|string $id): int
    {
        $s = (string) $id;

        if (preg_match('/^[1-9][0-9]{0,9}\z/', $s) !== 1) {
            throw ValidationException::forField('id', 'ID tidak valid.');
        }

        return (int) $s;
    }

    /**
     * @return array<string, mixed>
     */
    private function query(): array
    {
        $q = $this->request->getGet();

        return is_array($q) ? $q : [];
    }
}
