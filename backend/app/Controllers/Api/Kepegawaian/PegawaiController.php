<?php

declare(strict_types=1);

namespace App\Controllers\Api\Kepegawaian;

use App\Controllers\Api\ApiController;
use App\Interfaces\Kepegawaian\BiodataServiceInterface;
use App\Interfaces\Kepegawaian\NipCascadeInterface;
use App\Interfaces\Kepegawaian\PegawaiServiceInterface;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Endpoint pegawai WS-2 (MAKE-010) — kontrak: README.md bagian "Kontrak API Modul B" dan "Pegawai, biodata, koreksi NIP".
 *
 *   GET  pegawai                        daftar disaring lingkup (role 1, 3, 4, 5, 8)        — B-20 dasar
 *   GET  pegawai/{nip}                  detail + `tabs` descriptor (UL_ALL + PegawaiScope)   — B-20 dasar
 *   PUT  pegawai/{nip}/biodata          ubah biodata dua jalur (role 1, 2, 3, 6, 7)          — B-03
 *        (dengan foto: POST multipart + `_method=PUT`, berkas `foto`)
 *   GET  pegawai/{nip}/foto             foto profil terkini (UL_ALL + PegawaiScope)
 *   POST pegawai/{nip}/koreksi-nip      `{ nip_baru }` (role 1)                              — B-06
 *
 * NIP dari route selalu string (castNumericParams mati: NIP 18 digit tidak boleh menjadi int).
 */
class PegawaiController extends ApiController
{
    protected bool $castNumericParams = false;

    public function index(): ResponseInterface
    {
        // Query string sudah dijaga UTF-8 oleh ApiController::_remap().
        $query = $this->request->getGet();

        return $this->respondSuccess($this->pegawai()->daftar(service('authContext'), is_array($query) ? $query : []));
    }

    public function show(string $nip): ResponseInterface
    {
        return $this->respondSuccess($this->pegawai()->detail(service('authContext'), $nip));
    }

    public function updateBiodata(string $nip): ResponseInterface
    {
        $data = $this->payload();
        unset($data['_method']);
        $foto = $this->request->getFile('foto');

        return $this->respondSuccess($this->biodata()->ubah(service('authContext'), $nip, $data, $foto instanceof UploadedFile ? $foto : null));
    }

    public function foto(string $nip): ResponseInterface
    {
        $foto = $this->biodata()->foto(service('authContext'), $nip);

        return $this->response
            ->setStatusCode(200)
            ->setContentType($foto['mime'])
            ->setHeader('Content-Disposition', 'inline; filename="' . preg_replace('/[^A-Za-z0-9._-]+/', '_', $foto['nama']) . '"')
            ->setHeader('X-Content-Type-Options', 'nosniff')
            ->setHeader('Cache-Control', 'private, no-store')
            ->setBody($foto['isi']);
    }

    public function koreksiNip(string $nip): ResponseInterface
    {
        $data = $this->payload();

        return $this->respondSuccess($this->nip()->koreksi(service('authContext'), $nip, $data['nip_baru'] ?? null));
    }

    private function pegawai(): PegawaiServiceInterface
    {
        return service('pegawaiService');
    }

    private function biodata(): BiodataServiceInterface
    {
        return service('biodataService');
    }

    private function nip(): NipCascadeInterface
    {
        return service('nipCascade');
    }
}
