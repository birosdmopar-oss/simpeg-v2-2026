<?php

declare(strict_types=1);

namespace App\Controllers\Api\Konten;

use App\Controllers\Api\ApiController;
use App\Exceptions\ValidationException;
use App\Libraries\Dashboard\Konten\JenisMaster;
use App\Libraries\Dashboard\Konten\MasterKontenService;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Basis controller master sederhana konten: kategori berita dan jenis DIBO (PRD Fase 7 v2.0 Q7-07/Q7-11). Baca pilihan aktif =
 * semua role login; daftar kelola (`?kelola=1`) dan tulis di service/route (role 1; role 3 membaca kategori berita yang
 * boleh dipakainya). Subclass hanya menentukan jenis.
 */
abstract class MasterKontenController extends ApiController
{
    abstract protected function jenis(): JenisMaster;

    public function index(): ResponseInterface
    {
        $kelola = ($this->request->getGet('kelola') ?? '') === '1';
        $data   = $kelola ? $this->service()->daftar(service('authContext')) : $this->service()->aktif();

        return $this->respondSuccess($data)->setHeader('Cache-Control', 'private, no-store');
    }

    public function create(): ResponseInterface
    {
        $p = $this->payload();

        return $this->respondSuccess($this->service()->simpan(null, $p['nama'] ?? null, $p['allow_ap'] ?? 0, $this->aktor()), 201);
    }

    public function update(int|string $id): ResponseInterface
    {
        $p = $this->payload();

        return $this->respondSuccess($this->service()->simpan($this->idValid($id), $p['nama'] ?? null, $p['allow_ap'] ?? 0, $this->aktor()));
    }

    public function status(int|string $id): ResponseInterface
    {
        $this->service()->setStatus($this->idValid($id), $this->payload()['status'] ?? null, $this->aktor());

        return $this->respondSuccess(null);
    }

    public function urutan(int|string $id): ResponseInterface
    {
        $this->service()->geser($this->idValid($id), $this->payload()['arah'] ?? null, $this->aktor());

        return $this->respondSuccess(null);
    }

    public function delete(int|string $id): ResponseInterface
    {
        $this->service()->hapus($this->idValid($id), $this->aktor());

        return $this->respondSuccess(null);
    }

    private function service(): MasterKontenService
    {
        return new MasterKontenService($this->jenis());
    }

    private function aktor(): int
    {
        return (int) service('authContext')->idPengguna();
    }

    private function idValid(int|string $id): int
    {
        $s = (string) $id;

        if (preg_match('/^[1-9][0-9]{0,9}\z/', $s) !== 1) {
            throw ValidationException::forField('id', 'ID tidak valid.');
        }

        return (int) $s;
    }
}
