<?php

declare(strict_types=1);

namespace App\Controllers\Api\Dashboard;

use App\Controllers\Api\ApiController;
use App\Libraries\Dashboard\Interaksi\FormDigital\IsianFormDigital;
use App\Libraries\Dashboard\Interaksi\FormDigital\KelolaFormDigital;
use App\Libraries\Dashboard\Interaksi\FormDigital\RekapRespons;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * F-06 Form Digital (MAKE-083, Jalur B) — padanan legacy `hr/announcement` form digital. Sisi pegawai (role 2/6/7 lewat
 * filter route): `form-digital/saya`, `form-digital/{id}/isi`. Kelola (role 1): CRUD, status, hapus lunak, target.
 * Aturan bisnis di `Libraries/Dashboard/Interaksi/FormDigital`.
 */
final class FormDigitalController extends ApiController
{
    private ?IsianFormDigital $isian = null;

    private ?KelolaFormDigital $kelola = null;

    private ?RekapRespons $rekap = null;

    public function respons(int $id): ResponseInterface
    {
        if ($this->request->getGet('format') === 'xlsx') {
            $berkas = $this->rekap()->xlsx(service('authContext'), $id);

            return $this->unduh($berkas['nama'], $berkas['mime'], $berkas['isi']);
        }

        return $this->respondSuccess($this->rekap()->halaman(service('authContext'), $id, $this->query()));
    }

    public function berkasJawaban(int $id, int $idJawaban, string $idPertanyaan): ResponseInterface
    {
        $berkas = $this->rekap()->berkas(service('authContext'), $id, $idJawaban, $idPertanyaan);
        $mime   = (new \finfo(FILEINFO_MIME_TYPE))->buffer($berkas['isi']);

        return $this->unduh($berkas['nama'], is_string($mime) ? $mime : 'application/octet-stream', $berkas['isi']);
    }

    public function index(): ResponseInterface
    {
        return $this->respondSuccess($this->kelola()->daftar(service('authContext'), $this->query()));
    }

    public function show(int $id): ResponseInterface
    {
        return $this->respondSuccess($this->kelola()->detail(service('authContext'), $id));
    }

    public function create(): ResponseInterface
    {
        return $this->respondSuccess($this->kelola()->buat(service('authContext'), $this->payload()), 201);
    }

    public function update(int $id): ResponseInterface
    {
        return $this->respondSuccess($this->kelola()->ubah(service('authContext'), $id, $this->payload()));
    }

    public function status(int $id): ResponseInterface
    {
        $this->kelola()->setStatus(service('authContext'), $id, $this->payload()['status'] ?? null);

        return $this->respondSuccess(null);
    }

    public function delete(int $id): ResponseInterface
    {
        $this->kelola()->hapus(service('authContext'), $id);

        return $this->respondSuccess(null);
    }

    public function target(int $id): ResponseInterface
    {
        return $this->respondSuccess($this->kelola()->target(service('authContext'), $id, $this->query()));
    }

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

    /**
     * @return array<string, mixed>
     */
    private function query(): array
    {
        $q = $this->request->getGet();

        return is_array($q) ? $q : [];
    }

    private function unduh(string $nama, string $mime, string $isi): ResponseInterface
    {
        $aman = preg_replace('/[^A-Za-z0-9._ -]/', '_', $nama) ?? 'berkas';

        return $this->response
            ->setStatusCode(200)
            ->setHeader('Content-Type', $mime)
            ->setHeader('Content-Disposition', 'attachment; filename="' . $aman . '"')
            ->setHeader('X-Content-Type-Options', 'nosniff')
            ->setHeader('Cache-Control', 'private, no-store')
            ->setBody($isi);
    }

    private function rekap(): RekapRespons
    {
        return $this->rekap ??= new RekapRespons(db_connect(), service('storageAdapter'));
    }

    private function kelola(): KelolaFormDigital
    {
        return $this->kelola ??= new KelolaFormDigital(db_connect());
    }

    private function isian(): IsianFormDigital
    {
        return $this->isian ??= new IsianFormDigital(db_connect(), service('storageAdapter'));
    }
}
