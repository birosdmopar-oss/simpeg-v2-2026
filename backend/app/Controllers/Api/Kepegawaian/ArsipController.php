<?php

declare(strict_types=1);

namespace App\Controllers\Api\Kepegawaian;

use App\Controllers\Api\ApiController;
use App\Libraries\Kepegawaian\Arsip\ArsipService;
use App\Libraries\Kepegawaian\Arsip\HeaderBerkas;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Arsip;

/**
 * Halaman Arsip F-10 Tahap 1 (PRD F-10 §8.1; MAKE-090) — kontrak: README.md bagian "Arsip F-10".
 *
 *   GET pegawai/{nip}/arsip                          folder agregat + identitas ringkas (+ catatan F10-17)
 *   GET pegawai/{nip}/arsip/berkas                   daftar berkas berhalaman (`id_jenis_rwy`, `q`, `sort`, `order`, …)
 *   GET pegawai/{nip}/arsip/berkas/{id}/buka         berkas unggahan v2 (privat) + audit `arsip_unduh` + throttle
 *
 * Route: grup jwt + `Role::filter(1–7)`; lingkup & kepemilikan di ArsipService/OtorisasiArsip. NIP dari route selalu
 * string (castNumericParams mati: NIP 18 digit tidak boleh menjadi int). Endpoint Tahap 2 (aksi, ekspor, opsi
 * Hubungkan) milik MAKE-092.
 */
class ArsipController extends ApiController
{
    protected bool $castNumericParams = false;

    public function folder(string $nip): ResponseInterface
    {
        return $this->respondSuccess($this->arsip()->folder(service('authContext'), $nip));
    }

    public function berkas(string $nip): ResponseInterface
    {
        return $this->respondSuccess($this->arsip()->daftar(service('authContext'), $nip, (array) $this->request->getGet()));
    }

    public function buka(string $nip, string $id): ResponseInterface
    {
        $berkas = $this->arsip()->buka(service('authContext'), $nip, (int) $id);
        $this->response->setStatusCode(200)->setBody($berkas['isi']);

        foreach (HeaderBerkas::untuk($berkas['tipe'], $berkas['inline'], $berkas['nama'], 'private, no-store') as $nama => $nilai) {
            // removeHeader dulu: Cache-Control bawaan CI4 berupa daftar, setHeader akan menambah, bukan mengganti.
            $this->response->removeHeader($nama)->setHeader($nama, $nilai);
        }

        return $this->response;
    }

    private function arsip(): ArsipService
    {
        return new ArsipService(db_connect(), service('pegawaiScope'), service('storageAdapter'), config(Arsip::class));
    }
}
