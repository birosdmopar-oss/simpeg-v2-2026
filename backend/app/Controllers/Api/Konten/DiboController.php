<?php

declare(strict_types=1);

namespace App\Controllers\Api\Konten;

use App\Controllers\Api\ApiController;
use App\Exceptions\ValidationException;
use App\Libraries\Dashboard\Konten\Dibo\DiboService;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * F-12 DIBO / Kamus Digital Kepegawaian (MAKE-082; PRD Fase 7 v2.0 §5.6). Padanan legacy `hr/dibo/*`. Baca = semua role login
 * (hanya status aktif); kelola = role 1 (`?kelola=1`, tambah/ubah multipart `file_1..3`). Aturan di {@see DiboService}.
 */
final class DiboController extends ApiController
{
    /** Daftar baca (bawaan) atau daftar kelola bila `?kelola=1` (role 1). */
    public function index(): ResponseInterface
    {
        $q    = $this->query();
        $data = ($q['kelola'] ?? '') === '1' ? $this->service()->kelola(service('authContext'), $q) : $this->service()->baca($q);

        return $this->respondSuccess($data)->setHeader('Cache-Control', 'private, no-store');
    }

    public function create(): ResponseInterface
    {
        return $this->respondSuccess($this->service()->buat(service('authContext'), $this->payload(), $this->berkas()), 201);
    }

    /** Ubah lewat POST ke id (multipart tidak terurai pada PUT PHP). */
    public function update(int|string $id): ResponseInterface
    {
        return $this->respondSuccess($this->service()->ubah(service('authContext'), $this->idValid($id), $this->payload(), $this->berkas()));
    }

    public function status(int|string $id): ResponseInterface
    {
        $this->service()->setStatus(service('authContext'), $this->idValid($id), $this->payload()['status'] ?? null);

        return $this->respondSuccess(null);
    }

    public function delete(int|string $id): ResponseInterface
    {
        $this->service()->hapus(service('authContext'), $this->idValid($id));

        return $this->respondSuccess(null);
    }

    /** Unduh berkas ke-`$no` (1..3) terautentikasi; non-admin hanya status aktif; tanpa cache. */
    public function berkasUnduh(int|string $id, int|string $no): ResponseInterface
    {
        $hasil = $this->service()->bacaBerkas(service('authContext'), $this->idValid($id), (int) $no);

        return $this->response
            ->setStatusCode(200)
            ->setContentType($hasil['mime'], '')
            ->setHeader('Content-Disposition', 'attachment; filename="' . $hasil['nama'] . '"')
            ->setHeader('X-Content-Type-Options', 'nosniff')
            ->setHeader('Cache-Control', 'private, no-store')
            ->setBody($hasil['isi']);
    }

    /**
     * @return array<int, \CodeIgniter\HTTP\Files\UploadedFile|null>
     */
    private function berkas(): array
    {
        return [1 => $this->request->getFile('file_1'), 2 => $this->request->getFile('file_2'), 3 => $this->request->getFile('file_3')];
    }

    private function service(): DiboService
    {
        return new DiboService();
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
