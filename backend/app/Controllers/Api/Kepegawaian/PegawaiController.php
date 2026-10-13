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
 *   GET  pegawai/export                 ekspor csv|xlsx daftar terfilter (role 1, 3, 4, 5, 8)  — B-20 penutup
 *   GET  pegawai/pencarian/katalog      katalog pencarian lanjutan (role 1, 3, 4, 5)           — B-20 penutup
 *   POST pegawai/pencarian              pencarian lanjutan `{ kriteria[], page, … }`           — B-20 penutup
 *   POST pegawai/pencarian/export       ekspor hasil pencarian lanjutan `{ kriteria[], format, kolom }`
 *   GET  pegawai/{nip}                  detail + `tabs` descriptor (UL_ALL + PegawaiScope)   — B-20 dasar
 *   POST pegawai                        tambah pegawai + foto + akun (role 1; multipart `foto`) — B-05
 *   DELETE pegawai/{nip}                hapus fisik ikut legacy; 422 bila ada data terkait (role 1) — B-05
 *   PUT  pegawai/{nip}/biodata          ubah biodata dua jalur (role 1, 2, 3, 6, 7)          — B-03
 *        (dengan foto: POST multipart + `_method=PUT`, berkas `foto`)
 *   GET  pegawai/{nip}/foto             foto profil terkini (UL_ALL + PegawaiScope)
 *   POST pegawai/{nip}/koreksi-nip      `{ nip_baru }` (role 1)                              — B-06
 *   GET  biodata/pengajuan              antrean pengajuan menunggu (role 1, 3; lingkup jalur proses) — B-04
 *   GET  biodata/pengajuan/{id}         detail + perubahan (pemroses dalam lingkup / pemilik)
 *   GET  biodata/pengajuan/{id}/foto    foto pada pengajuan
 *   POST biodata/pengajuan/{id}/process `{ aksi, reason_note }` (role 1, 3)
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

    public function ekspor(): ResponseInterface
    {
        $query = $this->request->getGet();

        return $this->kirimEkspor($this->pegawai()->ekspor(service('authContext'), is_array($query) ? $query : []));
    }

    public function katalogPencarian(): ResponseInterface
    {
        return $this->respondSuccess($this->pegawai()->katalogPencarian(service('authContext')));
    }

    public function pencarian(): ResponseInterface
    {
        return $this->respondSuccess($this->pegawai()->pencarian(service('authContext'), $this->payload()));
    }

    public function eksporPencarian(): ResponseInterface
    {
        return $this->kirimEkspor($this->pegawai()->ekspor(service('authContext'), $this->payload(), true));
    }

    /**
     * @param array{isi: string, nama: string, mime: string} $berkas
     */
    private function kirimEkspor(array $berkas): ResponseInterface
    {
        return $this->response
            ->setStatusCode(200)
            ->setHeader('Content-Type', $berkas['mime'])
            ->setHeader('Content-Disposition', 'attachment; filename="' . $berkas['nama'] . '"')
            ->setHeader('X-Content-Type-Options', 'nosniff')
            ->setHeader('Cache-Control', 'no-store')
            ->setBody($berkas['isi']);
    }

    public function show(string $nip): ResponseInterface
    {
        return $this->respondSuccess($this->pegawai()->detail(service('authContext'), $nip));
    }

    public function create(): ResponseInterface
    {
        $data = $this->payload();
        $foto = $this->request->getFile('foto');

        // Respons memuat password awal akun (= NIP): jangan disimpan cache.
        return $this->respondSuccess($this->pegawai()->tambah(service('authContext'), $data, $foto instanceof UploadedFile ? $foto : null), 201)
            ->setHeader('Cache-Control', 'no-store');
    }

    public function delete(string $nip): ResponseInterface
    {
        return $this->respondSuccess(['deleted' => true, 'soft_delete' => false, 'item' => $this->pegawai()->hapus(service('authContext'), $nip)]);
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
        return $this->kirimFoto($this->biodata()->foto(service('authContext'), $nip));
    }

    public function koreksiNip(string $nip): ResponseInterface
    {
        $data = $this->payload();

        return $this->respondSuccess($this->nip()->koreksi(service('authContext'), $nip, $data['nip_baru'] ?? null));
    }

    public function antreanPengajuan(): ResponseInterface
    {
        $query = $this->request->getGet();

        return $this->respondSuccess($this->biodata()->antreanPengajuan(service('authContext'), is_array($query) ? $query : []));
    }

    public function pengajuan(string $id): ResponseInterface
    {
        return $this->respondSuccess($this->biodata()->pengajuan(service('authContext'), (int) $id));
    }

    public function fotoPengajuan(string $id): ResponseInterface
    {
        return $this->kirimFoto($this->biodata()->fotoPengajuan(service('authContext'), (int) $id));
    }

    public function prosesPengajuan(string $id): ResponseInterface
    {
        $data   = $this->payload();
        $aksi   = $data['aksi'] ?? null;
        $alasan = $data['reason_note'] ?? null;

        return $this->respondSuccess($this->biodata()->prosesPengajuan(
            service('authContext'),
            (int) $id,
            is_string($aksi) ? $aksi : '',
            is_string($alasan) ? $alasan : null,
        ));
    }

    /**
     * @param array{isi: string, mime: string, nama: string} $foto
     */
    private function kirimFoto(array $foto): ResponseInterface
    {
        return $this->response
            ->setStatusCode(200)
            ->setContentType($foto['mime'])
            ->setHeader('Content-Disposition', 'inline; filename="' . preg_replace('/[^A-Za-z0-9._-]+/', '_', $foto['nama']) . '"')
            ->setHeader('X-Content-Type-Options', 'nosniff')
            ->setHeader('Cache-Control', 'private, no-store')
            ->setBody($foto['isi']);
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
