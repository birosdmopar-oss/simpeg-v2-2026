<?php

declare(strict_types=1);

namespace App\Controllers\Api\Konten;

use App\Controllers\Api\ApiController;
use App\Exceptions\ValidationException;
use App\Libraries\Dashboard\Konten\Gambar\JenisGambar;
use App\Libraries\Dashboard\Konten\Gambar\KontenGambarService;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Basis controller F-04 Popup / F-05 Banner (PRD Fase 7 v2.0 §5.3/5.4). Kelola = role 1 (filter route); `aktif` dan
 * `gambar` terbuka untuk role ber-NIP (otorisasi penerima di service). Subclass hanya menentukan jenis.
 */
abstract class KontenGambarController extends ApiController
{
    abstract protected function jenis(): JenisGambar;

    public function index(): ResponseInterface
    {
        return $this->respondSuccess($this->service()->daftar($this->query()));
    }

    public function target(int|string $id): ResponseInterface
    {
        return $this->respondSuccess($this->service()->target($this->idValid($id)));
    }

    public function penerima(int|string $id): ResponseInterface
    {
        return $this->respondSuccess($this->service()->penerima($this->idValid($id), $this->query()));
    }

    public function create(): ResponseInterface
    {
        $data = $this->service()->buat($this->payload(), $this->request->getFile('berkas'), $this->idPengguna());

        return $this->respondSuccess($data, 201);
    }

    /** Multipart tidak terurai di PUT PHP → ubah lewat POST ke id (FE mengirim `_method=PUT`, diabaikan). */
    public function update(int|string $id): ResponseInterface
    {
        return $this->respondSuccess($this->service()->ubah($this->idValid($id), $this->payload(), $this->request->getFile('berkas'), $this->idPengguna()));
    }

    public function delete(int|string $id): ResponseInterface
    {
        $this->service()->hapus($this->idValid($id));

        return $this->respondSuccess(null);
    }

    public function urutan(int|string $id): ResponseInterface
    {
        $payload = $this->payload();
        $this->service()->geser($this->idValid($id), is_string($payload['arah'] ?? null) ? $payload['arah'] : '');

        return $this->respondSuccess(null);
    }

    public function aktif(): ResponseInterface
    {
        $auth = service('authContext');

        return $this->respondSuccess($this->service()->aktif($auth->nip()))->setHeader('Cache-Control', 'private, no-store');
    }

    public function gambar(int|string $id): ResponseInterface
    {
        $auth  = service('authContext');
        $hasil = $this->service()->bacaGambar($this->idValid($id), $auth->role(), $auth->nip());

        return $this->response
            ->setStatusCode(200)
            ->setContentType($hasil['mime'], '')
            ->setHeader('Content-Disposition', 'inline; filename="' . $hasil['nama'] . '"')
            ->setHeader('X-Content-Type-Options', 'nosniff')
            ->setHeader('Cache-Control', 'private, no-store')
            ->setBody($hasil['isi']);
    }

    public function opsiUnitSatker(): ResponseInterface
    {
        return $this->respondSuccess($this->service()->opsiUnitSatker());
    }

    private function service(): KontenGambarService
    {
        return new KontenGambarService($this->jenis());
    }

    private function idPengguna(): int
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

    /**
     * @return array<string, mixed>
     */
    private function query(): array
    {
        $q = $this->request->getGet();

        return is_array($q) ? $q : [];
    }
}
