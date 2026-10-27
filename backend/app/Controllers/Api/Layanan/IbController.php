<?php

declare(strict_types=1);

namespace App\Controllers\Api\Layanan;

use App\Controllers\Api\ApiController;
use App\Libraries\Layanan\Ib\IbApprovalService;
use App\Libraries\Layanan\Ib\IbService;
use App\Libraries\Layanan\Ib\SuratIbService;
use App\Libraries\Layanan\Studi\AturanStudi;
use App\Libraries\Layanan\Studi\BerkasStudi;
use App\Libraries\Pdf\Pdf;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Izin Belajar C-08 (PRD Fase 4 §5.3, §6.8) — route `Config/RoutesLayananStudi.php` (role 1, 2, 3, 7; role 6 → 403).
 * Controller tipis: aturan & hak di IbService / IbApprovalService / SuratIbService. Multipart ubah = `POST` +
 * `_method=PUT`; berkas per slot `file_sp`, `file_1`, `file_2`. Dokumentasi: README.md bagian "Izin Belajar".
 */
class IbController extends ApiController
{
    public function index(): ResponseInterface
    {
        $query = $this->request->getGet();

        return $this->respondSuccess($this->ib()->daftar(service('authContext'), is_array($query) ? $query : []));
    }

    public function opsi(): ResponseInterface
    {
        $nip = $this->request->getGet('nip');

        return $this->respondSuccess($this->ib()->opsi(service('authContext'), is_string($nip) ? $nip : null));
    }

    public function show(int $id): ResponseInterface
    {
        return $this->respondSuccess($this->ib()->detail(service('authContext'), $id));
    }

    public function create(): ResponseInterface
    {
        return $this->respondSuccess($this->ib()->tambah(service('authContext'), $this->isian(), $this->berkasSlot()), 201);
    }

    public function update(int $id): ResponseInterface
    {
        return $this->respondSuccess($this->ib()->ubah(service('authContext'), $id, $this->isian(), $this->berkasSlot()));
    }

    public function delete(int $id): ResponseInterface
    {
        $this->ib()->hapus(service('authContext'), $id);

        return $this->respondSuccess(['deleted' => true, 'soft_delete' => false]);
    }

    public function process(int $id): ResponseInterface
    {
        $auth = service('authContext');
        (new IbApprovalService())->proses($auth, $id, $this->isian());

        return $this->respondSuccess($this->ib()->detail($auth, $id));
    }

    public function lampiran(int $id, int $idAttachment): ResponseInterface
    {
        $hasil = $this->ib()->unduhLampiran(service('authContext'), $id, $idAttachment);
        $nama  = (string) ($hasil['lampiran']['display_name'] ?? 'lampiran');

        return $this->response
            ->setStatusCode(200)
            ->setContentType((string) ($hasil['lampiran']['file_type'] ?? '') !== '' ? (string) $hasil['lampiran']['file_type'] : 'application/octet-stream')
            ->setHeader('Content-Disposition', 'attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]+/', '_', $nama) . '"; filename*=UTF-8\'\'' . rawurlencode($nama))
            ->setHeader('X-Content-Type-Options', 'nosniff')
            ->setHeader('Cache-Control', 'private, no-store')
            ->setBody($hasil['isi']);
    }

    public function surat(int $id): ResponseInterface
    {
        $auth  = service('authContext');
        $surat = (new SuratIbService())->unduh($auth, $this->ib()->barisTerlihat($auth, $id));

        return Pdf::respon($this->response, $surat['isi'], $surat['nama'], $this->request->getGet('inline') === '1');
    }

    private function ib(): IbService
    {
        return new IbService();
    }

    /**
     * @return array<string, mixed>
     */
    private function isian(): array
    {
        $data = $this->payload();
        unset($data['_method']);

        return $data;
    }

    /**
     * @return array<string, UploadedFile>
     */
    private function berkasSlot(): array
    {
        $out = [];

        foreach (array_keys(BerkasStudi::slot(AturanStudi::JENIS_IB)) as $slot) {
            $file = $this->request->getFile($slot);

            if ($file instanceof UploadedFile && $file->getError() !== UPLOAD_ERR_NO_FILE) {
                $out[$slot] = $file;
            }
        }

        return $out;
    }
}
