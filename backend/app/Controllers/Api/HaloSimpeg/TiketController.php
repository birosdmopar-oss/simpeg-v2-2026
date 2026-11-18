<?php

declare(strict_types=1);

namespace App\Controllers\Api\HaloSimpeg;

use App\Libraries\Auth\AuthContext;
use App\Libraries\HaloSimpeg\TiketService;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Arsip;

/**
 * Tiket Halo Simpeg (E-03..E-05, MAKE-071): role per route di `Config/RoutesHaloSimpeg.php`; kewenangan objek di service
 * (`AksesTiket`, BP8-2). Kontrak: README folder ini bagian tiket.
 */
final class TiketController extends HaloSimpegController
{
    public function index(): ResponseInterface
    {
        return $this->tanpaCache($this->respondSuccess($this->service()->daftar($this->auth(), $this->query())));
    }

    public function ringkasan(): ResponseInterface
    {
        return $this->tanpaCache($this->respondSuccess($this->service()->ringkasan($this->auth())));
    }

    public function show(int|string $id): ResponseInterface
    {
        return $this->tanpaCache($this->respondSuccess($this->service()->detail($this->auth(), $this->idValid($id))));
    }

    public function create(): ResponseInterface
    {
        return $this->respondSuccess($this->service()->buat($this->auth(), $this->payload(), $this->berkas()), 201);
    }

    public function pesan(int|string $id): ResponseInterface
    {
        return $this->respondSuccess($this->service()->balas($this->auth(), $this->idValid($id), $this->payload(), $this->berkas()), 201);
    }

    public function baca(int|string $id): ResponseInterface
    {
        return $this->respondSuccess($this->service()->baca($this->auth(), $this->idValid($id)));
    }

    public function tutup(int|string $id): ResponseInterface
    {
        return $this->respondSuccess($this->service()->tutup($this->auth(), $this->idValid($id)));
    }

    public function delete(int|string $id): ResponseInterface
    {
        return $this->respondSuccess($this->service()->hapus($this->auth(), $this->idValid($id)));
    }

    /** Unduh lampiran lama/baru sesudah cek akses (AC-E04.7, F8-17): header aman, nama `lampiran-{id}-{idPesan}.{ext}`. */
    public function lampiran(int|string $id, int|string $idPesan): ResponseInterface
    {
        $b = $this->service()->lampiran($this->auth(), $this->idValid($id), $this->idValid($idPesan));

        return $this->response
            ->setStatusCode(200)
            ->setContentType($b['mime'], '')
            ->setHeader('Content-Disposition', ($b['inline'] ? 'inline' : 'attachment') . '; filename="' . $b['nama'] . '"')
            ->setHeader('X-Content-Type-Options', 'nosniff')
            ->setHeader('Content-Security-Policy', Arsip::CSP_BERKAS)
            ->setHeader('Cache-Control', 'private, no-store')
            ->setBody($b['isi']);
    }

    private function auth(): AuthContext
    {
        return service('authContext');
    }

    private function berkas(): ?UploadedFile
    {
        $f = $this->request->getFile('arsip');

        return $f instanceof UploadedFile ? $f : null;
    }

    private function service(): TiketService
    {
        return new TiketService();
    }
}
