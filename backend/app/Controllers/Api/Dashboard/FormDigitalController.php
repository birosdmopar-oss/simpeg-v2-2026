<?php

declare(strict_types=1);

namespace App\Controllers\Api\Dashboard;

use App\Controllers\Api\ApiController;
use App\Libraries\Dashboard\Interaksi\FormDigital\IsianFormDigital;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * F-06 Form Digital (MAKE-083, Jalur B) — padanan legacy `hr/announcement` form digital. Sisi pegawai (role 2/6/7 lewat
 * filter route): `form-digital/saya`, `form-digital/{id}/isi`. Aturan bisnis di `Libraries/Dashboard/Interaksi/FormDigital`.
 */
final class FormDigitalController extends ApiController
{
    private ?IsianFormDigital $isian = null;

    public function saya(): ResponseInterface
    {
        return $this->respondSuccess($this->isian()->saya(service('authContext')));
    }

    public function isi(int $id): ResponseInterface
    {
        $jawaban = IsianFormDigital::decodeJawaban($this->request->getPost('jawaban'));

        return $this->respondSuccess($this->isian()->isi(service('authContext'), $id, $jawaban, $this->berkas()), 201);
    }

    /**
     * Berkas multipart `berkas_<idPertanyaan>` → id pertanyaan => berkas. Kunci lain diabaikan.
     *
     * @return array<string, UploadedFile>
     */
    private function berkas(): array
    {
        $hasil = [];

        foreach ($this->request->getFiles() as $kunci => $file) {
            if ($file instanceof UploadedFile && preg_match('/^berkas_([a-z0-9_]{1,40})\z/', (string) $kunci, $m) === 1 && $file->getError() !== UPLOAD_ERR_NO_FILE) {
                $hasil[$m[1]] = $file;
            }
        }

        return $hasil;
    }

    private function isian(): IsianFormDigital
    {
        return $this->isian ??= new IsianFormDigital(db_connect(), service('storageAdapter'));
    }
}
