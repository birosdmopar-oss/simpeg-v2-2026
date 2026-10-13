<?php

declare(strict_types=1);

namespace App\Controllers\Api\Kepegawaian;

use App\Controllers\Api\ApiController;
use App\Interfaces\Kepegawaian\LkhServiceInterface;
use App\Libraries\Pdf\Pdf;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * B-12b Laporan Kerja Harian (WS-2 MAKE-013). Hak akses & lingkup di LkhService.
 *
 *   GET    lkh?nip=&page=&per_page=          LKH pegawai (bawaan: milik sendiri)
 *   GET    lkh/opsi?nip=                     rentang tanggal & atasan langsung
 *   GET    lkh/verifikasi?status=&ds=&de=    antrean atasan langsung (bawaan status 0)
 *   POST   lkh                               `{ nip?, tgl_laporan, kegiatan: [{ kegiatan, output, jumlah_diselesaikan, jam_mulai, jam_selesai }] }`
 *   GET    lkh/{id}                          detail + kegiatan
 *   PUT    lkh/{id}                          ubah
 *   DELETE lkh/{id}                          hapus (status 10)
 *   POST   lkh/{id}/process                  `{ aksi: setujui|tolak|revisi, catatan }` (atasan langsung)
 *   GET    lkh/{id}/pdf                      PDF (TCPDF, ADR-033)
 */
class LkhController extends ApiController
{
    protected bool $castNumericParams = false;

    public function index(): ResponseInterface
    {
        $query = $this->request->getGet();

        return $this->respondSuccess($this->service()->daftar(service('authContext'), is_array($query) ? $query : []));
    }

    public function opsi(): ResponseInterface
    {
        $nip = $this->request->getGet('nip');

        return $this->respondSuccess($this->service()->opsi(service('authContext'), is_string($nip) ? $nip : null));
    }

    public function verifikasi(): ResponseInterface
    {
        $query = $this->request->getGet();

        return $this->respondSuccess($this->service()->verifikasi(service('authContext'), is_array($query) ? $query : []));
    }

    public function show(string $id): ResponseInterface
    {
        return $this->respondSuccess($this->service()->detail(service('authContext'), (int) $id));
    }

    public function create(): ResponseInterface
    {
        return $this->respondSuccess($this->service()->tambah(service('authContext'), $this->payload()), 201);
    }

    public function update(string $id): ResponseInterface
    {
        return $this->respondSuccess($this->service()->ubah(service('authContext'), (int) $id, $this->payload()));
    }

    public function delete(string $id): ResponseInterface
    {
        return $this->respondSuccess($this->service()->hapus(service('authContext'), (int) $id));
    }

    public function process(string $id): ResponseInterface
    {
        $data    = $this->payload();
        $aksi    = $data['aksi'] ?? null;
        $catatan = $data['catatan'] ?? null;

        return $this->respondSuccess($this->service()->proses(service('authContext'), (int) $id, is_string($aksi) ? $aksi : '', is_string($catatan) ? $catatan : null));
    }

    public function pdf(string $id): ResponseInterface
    {
        $pdf = $this->service()->pdf(service('authContext'), (int) $id);

        return Pdf::respon($this->response, $pdf['isi'], $pdf['nama'], $this->request->getGet('inline') === '1');
    }

    private function service(): LkhServiceInterface
    {
        return service('lkhService');
    }
}
