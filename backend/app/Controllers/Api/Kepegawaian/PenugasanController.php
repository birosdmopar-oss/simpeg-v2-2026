<?php

declare(strict_types=1);

namespace App\Controllers\Api\Kepegawaian;

use App\Controllers\Api\ApiController;
use App\Libraries\Kepegawaian\Struktur\PenugasanService;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Penugasan Plt/Plh (B-07, WS-2 MAKE-012); `{jenis}` = plt | plh. Hak akses & lingkup di PenugasanService.
 *
 *   GET    penugasan/{jenis}                  daftar (search, status, page, per_page)
 *   GET    penugasan/{jenis}/{id}             detail
 *   POST   penugasan/{jenis}                  tambah (JSON, atau multipart dengan berkas `lampiran`)
 *   PUT    penugasan/{jenis}/{id}             ubah (dengan berkas: POST multipart + `_method=PUT`)
 *   DELETE penugasan/{jenis}/{id}             hapus lunak (status 10)
 *   GET    penugasan/{jenis}/{id}/lampiran    unduh lampiran
 */
class PenugasanController extends ApiController
{
    protected bool $castNumericParams = false;

    public function index(string $jenis): ResponseInterface
    {
        $query = $this->request->getGet();

        return $this->respondSuccess($this->service()->daftar(service('authContext'), $jenis, is_array($query) ? $query : []));
    }

    public function show(string $jenis, string $id): ResponseInterface
    {
        return $this->respondSuccess($this->service()->detail(service('authContext'), $jenis, (int) $id));
    }

    public function create(string $jenis): ResponseInterface
    {
        return $this->respondSuccess($this->service()->tambah(service('authContext'), $jenis, $this->isian(), $this->lampiran()), 201);
    }

    public function update(string $jenis, string $id): ResponseInterface
    {
        return $this->respondSuccess($this->service()->ubah(service('authContext'), $jenis, (int) $id, $this->isian(), $this->lampiran()));
    }

    public function delete(string $jenis, string $id): ResponseInterface
    {
        return $this->respondSuccess($this->service()->hapus(service('authContext'), $jenis, (int) $id));
    }

    public function unduhLampiran(string $jenis, string $id): ResponseInterface
    {
        $berkas = $this->service()->unduhLampiran(service('authContext'), $jenis, (int) $id);
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
     * @return array<string, mixed>
     */
    private function isian(): array
    {
        $data = $this->payload();
        unset($data['_method'], $data['lampiran']);

        return $data;
    }

    private function lampiran(): ?UploadedFile
    {
        $file = $this->request->getFile('lampiran');

        return $file instanceof UploadedFile && $file->getError() !== UPLOAD_ERR_NO_FILE ? $file : null;
    }

    private function service(): PenugasanService
    {
        return new PenugasanService();
    }
}
