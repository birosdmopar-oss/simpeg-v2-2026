<?php

declare(strict_types=1);

namespace App\Controllers\Api\Layanan;

use App\Controllers\Api\ApiController;
use App\Libraries\Layanan\Pmk\PmkApprovalService;
use App\Libraries\Layanan\Pmk\PmkPengajuanService;
use App\Libraries\Layanan\Pmk\RiwayatPmkService;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * PMK C-09 (PRD Fase 4 §5.4, §6.9) — route `Config/RoutesLayananStudi.php` (role 1, 2, 3; role 4..8 → 403). Controller
 * tipis: aturan & hak di PmkPengajuanService / PmkApprovalService / RiwayatPmkService. Multipart ubah = `POST` +
 * `_method=PUT`; berkas pengajuan per kolom (`file_kontrak`, `file_paklaring`, `file_surat_phk`, `file_slip_gaji`), arsip
 * SK riwayat `arsip`. Dokumentasi: README.md bagian "PMK".
 */
class PmkController extends ApiController
{
    public function index(): ResponseInterface
    {
        $query = $this->request->getGet();

        return $this->respondSuccess((new RiwayatPmkService())->daftar(service('authContext'), is_array($query) ? $query : []));
    }

    public function tambahArsip(int $id): ResponseInterface
    {
        return $this->respondSuccess((new RiwayatPmkService())->tambahArsip(service('authContext'), $id, $this->berkas('arsip')));
    }

    public function delete(int $id): ResponseInterface
    {
        (new RiwayatPmkService())->hapus(service('authContext'), $id);

        return $this->respondSuccess(['deleted' => true, 'soft_delete' => false]);
    }

    public function hapusArsip(int $id, int $idAttachment): ResponseInterface
    {
        (new RiwayatPmkService())->hapusArsip(service('authContext'), $id, $idAttachment);

        return $this->respondSuccess(['deleted' => true, 'soft_delete' => false]);
    }

    public function opsi(): ResponseInterface
    {
        $nip = $this->request->getGet('nip');

        return $this->respondSuccess($this->pengajuan()->opsi(service('authContext'), is_string($nip) ? $nip : null));
    }

    public function hitungMker(): ResponseInterface
    {
        return $this->respondSuccess($this->pengajuan()->hitungMker($this->isian()));
    }

    public function indexPengajuan(): ResponseInterface
    {
        $query = $this->request->getGet();

        return $this->respondSuccess($this->pengajuan()->daftar(service('authContext'), is_array($query) ? $query : []));
    }

    public function createPengajuan(): ResponseInterface
    {
        return $this->respondSuccess($this->pengajuan()->tambah(service('authContext'), $this->isian(), $this->berkasPengajuan()), 201);
    }

    public function showPengajuan(int $id): ResponseInterface
    {
        return $this->respondSuccess($this->pengajuan()->detail(service('authContext'), $id));
    }

    public function updatePengajuan(int $id): ResponseInterface
    {
        return $this->respondSuccess($this->pengajuan()->ubah(service('authContext'), $id, $this->isian(), $this->berkasPengajuan()));
    }

    public function deletePengajuan(int $id): ResponseInterface
    {
        $this->pengajuan()->hapus(service('authContext'), $id);

        return $this->respondSuccess(['deleted' => true, 'soft_delete' => false]);
    }

    public function processPengajuan(int $id): ResponseInterface
    {
        $auth = service('authContext');
        (new PmkApprovalService())->proses($auth, $id, $this->isian());

        return $this->respondSuccess($this->pengajuan()->detail($auth, $id));
    }

    private function pengajuan(): PmkPengajuanService
    {
        return new PmkPengajuanService();
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
    private function berkasPengajuan(): array
    {
        $out = [];

        foreach (array_keys(PmkPengajuanService::BERKAS) as $kolom) {
            $file = $this->berkas($kolom);

            if ($file !== null) {
                $out[$kolom] = $file;
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
