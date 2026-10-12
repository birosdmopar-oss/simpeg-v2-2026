<?php

declare(strict_types=1);

namespace App\Controllers\Api\Kepegawaian;

use App\Controllers\Api\ApiController;
use App\Exceptions\ValidationException;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Kepegawaian\Riwayat\AnakKeluarga;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * `pegawai/{nip}/riwayat/keluarga/{id}/anak[/{idAnak}]` (WS-1 M4 MAKE-007, aditif README kontrak). Tipis: request →
 * service AnakKeluarga. Akte lahir = berkas multipart `berkas` (satu berkas; 422 `errors.berkas`); ubah dengan berkas =
 * `POST` multipart + `_method=PUT`.
 */
final class AnakKeluargaController extends ApiController
{
    protected bool $castNumericParams = false;

    public function index(string $nip, string $id): ResponseInterface
    {
        return $this->respondSuccess($this->service()->daftar($this->auth(), $nip, (int) $id));
    }

    public function create(string $nip, string $id): ResponseInterface
    {
        return $this->respondSuccess($this->service()->tambah($this->auth(), $nip, (int) $id, $this->payload(), $this->akte()), 201);
    }

    public function update(string $nip, string $id, string $idAnak): ResponseInterface
    {
        return $this->respondSuccess($this->service()->ubah($this->auth(), $nip, (int) $id, (int) $idAnak, $this->payload(), $this->akte()));
    }

    public function delete(string $nip, string $id, string $idAnak): ResponseInterface
    {
        $this->service()->hapus($this->auth(), $nip, (int) $id, (int) $idAnak);

        return $this->respondSuccess(['deleted' => true, 'soft_delete' => false]);
    }

    private function akte(): ?UploadedFile
    {
        $file = $this->request->getFile('berkas');

        if ($file === null || $file->getError() === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        if (! $file->isValid()) {
            throw ValidationException::forField('berkas', 'Berkas gagal diunggah: ' . $file->getErrorString());
        }

        return $file;
    }

    private function service(): AnakKeluarga
    {
        return new AnakKeluarga();
    }

    private function auth(): AuthContext
    {
        return service('authContext');
    }
}
