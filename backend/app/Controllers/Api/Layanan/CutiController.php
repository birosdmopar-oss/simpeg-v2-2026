<?php

declare(strict_types=1);

namespace App\Controllers\Api\Layanan;

use App\Controllers\Api\ApiController;
use App\Libraries\Layanan\Cuti\CutiApprovalService;
use App\Libraries\Layanan\Cuti\CutiService;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Cuti online (PRD Fase 4 §5.1, C-02..C-04) — kontrak: README.md bagian "Cuti". Role route 1, 2, 3, 6, 7 (kecuali
 * `hitung-lama`: semua login); hak per baris (D5) di {@see CutiService}.
 */
final class CutiController extends ApiController
{
    public function index(): ResponseInterface
    {
        return $this->respondSuccess($this->cuti()->daftar(service('authContext'), $this->query()));
    }

    public function opsi(): ResponseInterface
    {
        $nip = $this->request->getGet('nip');

        return $this->respondSuccess($this->cuti()->opsi(service('authContext'), is_string($nip) && $nip !== '' ? $nip : null));
    }

    public function hitungLama(): ResponseInterface
    {
        return $this->respondSuccess($this->cuti()->hitungLama(service('authContext'), $this->payload()));
    }

    public function show(int $id): ResponseInterface
    {
        return $this->respondSuccess($this->cuti()->detail(service('authContext'), $id));
    }

    public function create(): ResponseInterface
    {
        return $this->respondSuccess($this->cuti()->tambah(service('authContext'), $this->payload(), $this->berkas()), 201);
    }

    public function update(int $id): ResponseInterface
    {
        return $this->respondSuccess($this->cuti()->ubah(service('authContext'), $id, $this->payload(), $this->berkas()));
    }

    public function delete(int $id): ResponseInterface
    {
        return $this->respondSuccess($this->cuti()->hapus(service('authContext'), $id));
    }

    public function process(int $id): ResponseInterface
    {
        return $this->respondSuccess($this->persetujuan()->process(service('authContext'), $id, $this->payload()));
    }

    public function tunda(int $id): ResponseInterface
    {
        return $this->respondSuccess($this->persetujuan()->tunda(service('authContext'), $id));
    }

    public function pengingat(): ResponseInterface
    {
        return $this->respondSuccess($this->cuti()->pengingat(service('authContext')));
    }

    public function lampiran(int $id): ResponseInterface
    {
        return $this->unduhBerkas($id, 'lampiran');
    }

    public function surat(int $id): ResponseInterface
    {
        return $this->unduhBerkas($id, 'surat');
    }

    private function unduhBerkas(int $id, string $jenis): ResponseInterface
    {
        $berkas = $this->cuti()->unduh(service('authContext'), $id, $jenis);
        $ascii  = (string) preg_replace('/[^A-Za-z0-9._-]+/', '_', $berkas['nama']);

        return $this->response
            ->setStatusCode(200)
            ->setContentType($berkas['mime'])
            ->setHeader('Content-Disposition', 'attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($berkas['nama']))
            ->setHeader('X-Content-Type-Options', 'nosniff')
            ->setHeader('Cache-Control', 'private, no-store')
            ->setBody($berkas['isi']);
    }

    private function berkas(): ?UploadedFile
    {
        $berkas = $this->request->getFile('file_bukti');

        return $berkas instanceof UploadedFile && $berkas->isValid() && $berkas->getError() !== UPLOAD_ERR_NO_FILE ? $berkas : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function query(): array
    {
        $query = $this->request->getGet();

        return is_array($query) ? $query : [];
    }

    private function persetujuan(): CutiApprovalService
    {
        return service('cutiApprovalService');
    }

    private function cuti(): CutiService
    {
        return service('cutiService');
    }
}
