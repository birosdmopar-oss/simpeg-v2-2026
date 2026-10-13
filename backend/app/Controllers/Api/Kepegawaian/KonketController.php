<?php

declare(strict_types=1);

namespace App\Controllers\Api\Kepegawaian;

use App\Controllers\Api\ApiController;
use App\Interfaces\Kepegawaian\KonketServiceInterface;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * B-13 Konfirmasi Ketidakhadiran (WS-2 MAKE-013). Hak akses & lingkup di KonketService (role 1, 2, 3, 6, 7).
 *
 *   GET    konket?ds=&de=&status=&search=&page=&per_page=  daftar (pegawai: miliknya; role 1/3: antrean rentang tanggal)
 *   GET    konket/opsi                                      jenis per role, tanggal minimal
 *   GET    konket/{id}                                      detail + peserta + aksi
 *   POST   konket                                           tambah (multipart; berkas `file_bukti`, `peserta[]`)
 *   PUT    konket/{id}                                      ubah (dengan berkas: POST multipart + `_method=PUT`)
 *   DELETE konket/{id}                                      hapus (status 10; dinas gabungan bersama pesertanya)
 *   POST   konket/{id}/process                              `{ aksi: setujui|tolak, reason_note }` (role 1, 3)
 *   GET    konket/{id}/berkas                               unduh berkas bukti
 */
class KonketController extends ApiController
{
    protected bool $castNumericParams = false;

    public function index(): ResponseInterface
    {
        $query = $this->request->getGet();

        return $this->respondSuccess($this->service()->daftar(service('authContext'), is_array($query) ? $query : []));
    }

    public function opsi(): ResponseInterface
    {
        return $this->respondSuccess($this->service()->opsi(service('authContext')));
    }

    public function show(string $id): ResponseInterface
    {
        return $this->respondSuccess($this->service()->detail(service('authContext'), (int) $id));
    }

    public function create(): ResponseInterface
    {
        return $this->respondSuccess($this->service()->tambah(service('authContext'), $this->isian(), $this->berkas()), 201);
    }

    public function update(string $id): ResponseInterface
    {
        return $this->respondSuccess($this->service()->ubah(service('authContext'), (int) $id, $this->isian(), $this->berkas()));
    }

    public function delete(string $id): ResponseInterface
    {
        return $this->respondSuccess($this->service()->hapus(service('authContext'), (int) $id));
    }

    public function process(string $id): ResponseInterface
    {
        $data   = $this->payload();
        $aksi   = $data['aksi'] ?? null;
        $alasan = $data['reason_note'] ?? null;

        return $this->respondSuccess($this->service()->proses(service('authContext'), (int) $id, is_string($aksi) ? $aksi : '', is_string($alasan) ? $alasan : null));
    }

    public function berkasBukti(string $id): ResponseInterface
    {
        $berkas = $this->service()->berkas(service('authContext'), (int) $id);
        $mime   = (new \finfo(FILEINFO_MIME_TYPE))->buffer($berkas['isi']);

        return $this->response
            ->setStatusCode(200)
            ->setContentType(is_string($mime) && $mime !== '' ? $mime : 'application/octet-stream')
            ->setHeader('Content-Disposition', 'attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]+/', '_', $berkas['nama']) . '"')
            ->setHeader('X-Content-Type-Options', 'nosniff')
            ->setHeader('Cache-Control', 'private, no-store')
            ->setBody($berkas['isi']);
    }

    /**
     * Isian JSON atau multipart (`peserta[]` tetap array).
     *
     * @return array<string, mixed>
     */
    private function isian(): array
    {
        $data = $this->payload();
        unset($data['_method'], $data['file_bukti']);

        return $data;
    }

    private function berkas(): ?UploadedFile
    {
        $file = $this->request->getFile('file_bukti');

        return $file instanceof UploadedFile && $file->getError() !== UPLOAD_ERR_NO_FILE ? $file : null;
    }

    private function service(): KonketServiceInterface
    {
        return service('konketService');
    }
}
