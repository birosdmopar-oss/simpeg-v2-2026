<?php

declare(strict_types=1);

namespace App\Controllers\Api\Layanan;

use App\Controllers\Api\ApiController;
use App\Libraries\Layanan\Studi\AturanStudi;
use App\Libraries\Layanan\Studi\BerkasStudi;
use App\Libraries\Layanan\Tb\PerpanjanganTbService;
use App\Libraries\Layanan\Tb\SuratTbService;
use App\Libraries\Layanan\Tb\TbApprovalService;
use App\Libraries\Layanan\Tb\TbService;
use App\Libraries\Pdf\Pdf;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Tugas Belajar C-07 (PRD Fase 4 §5.2, §6.7) — route `Config/RoutesLayananStudi.php` (role 1, 2, 3, 6; role 7 → 403).
 * Controller tipis: aturan & hak di TbService / TbApprovalService / PerpanjanganTbService / SuratTbService. Multipart
 * ubah = `POST` + `_method=PUT`; berkas per slot dengan nama field = nama slot (`file_sp`, `file_1`, `file_2`, `file_3`),
 * SK proses `file_surat_tb`, perpanjangan `file_sperpan`. Dokumentasi: README.md bagian "Tugas Belajar".
 */
class TbController extends ApiController
{
    public function index(): ResponseInterface
    {
        $query = $this->request->getGet();

        return $this->respondSuccess($this->tb()->daftar(service('authContext'), is_array($query) ? $query : []));
    }

    public function opsi(): ResponseInterface
    {
        $nip = $this->request->getGet('nip');

        return $this->respondSuccess($this->tb()->opsi(service('authContext'), is_string($nip) ? $nip : null));
    }

    public function show(int $id): ResponseInterface
    {
        return $this->respondSuccess($this->tb()->detail(service('authContext'), $id));
    }

    public function create(): ResponseInterface
    {
        return $this->respondSuccess($this->tb()->tambah(service('authContext'), $this->isian(), $this->berkasSlot()), 201);
    }

    public function update(int $id): ResponseInterface
    {
        return $this->respondSuccess($this->tb()->ubah(service('authContext'), $id, $this->isian(), $this->berkasSlot()));
    }

    public function delete(int $id): ResponseInterface
    {
        $this->tb()->hapus(service('authContext'), $id);

        return $this->respondSuccess(['deleted' => true, 'soft_delete' => false]);
    }

    public function process(int $id): ResponseInterface
    {
        $auth = service('authContext');
        (new TbApprovalService())->proses($auth, $id, $this->isian(), $this->berkas('file_surat_tb'));

        return $this->respondSuccess($this->tb()->detail($auth, $id));
    }

    public function tambahPerpanjangan(int $id): ResponseInterface
    {
        $auth = service('authContext');
        (new PerpanjanganTbService())->tambah($auth, $id, $this->isian(), $this->berkas('file_sperpan'));

        return $this->respondSuccess($this->tb()->detail($auth, $id), 201);
    }

    public function ubahPerpanjangan(int $id): ResponseInterface
    {
        $auth = service('authContext');
        $idTb = (new PerpanjanganTbService())->ubah($auth, $id, $this->isian());

        return $this->respondSuccess($this->tb()->detail($auth, $idTb));
    }

    public function hapusPerpanjangan(int $id): ResponseInterface
    {
        $auth = service('authContext');
        $idTb = (new PerpanjanganTbService())->hapus($auth, $id);

        return $this->respondSuccess($this->tb()->detail($auth, $idTb));
    }

    public function lampiran(int $id, int $idAttachment): ResponseInterface
    {
        $hasil = $this->tb()->unduhLampiran(service('authContext'), $id, $idAttachment);
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
        $surat = (new SuratTbService($this->tb()))->surat(service('authContext'), $id);

        return Pdf::respon($this->response, $surat['isi'], $surat['nama'], $this->request->getGet('inline') === '1');
    }

    private function tb(): TbService
    {
        return new TbService();
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

        foreach (array_keys(BerkasStudi::slot(AturanStudi::JENIS_TB)) as $slot) {
            $file = $this->berkas($slot);

            if ($file !== null) {
                $out[$slot] = $file;
            }
        }

        return $out;
    }

    private function berkas(string $field): ?UploadedFile
    {
        $file = $this->request->getFile($field);

        return $file instanceof UploadedFile && $file->getError() !== UPLOAD_ERR_NO_FILE ? $file : null;
    }
}
