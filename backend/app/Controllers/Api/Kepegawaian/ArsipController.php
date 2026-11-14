<?php

declare(strict_types=1);

namespace App\Controllers\Api\Kepegawaian;

use App\Controllers\Api\ApiController;
use App\Libraries\Kepegawaian\Arsip\AksiArsip;
use App\Libraries\Kepegawaian\Arsip\ArsipService;
use App\Libraries\Kepegawaian\Arsip\HeaderBerkas;
use App\Libraries\Kepegawaian\Arsip\OtorisasiArsip;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Arsip;

/**
 * Halaman Arsip F-10 Tahap 1 (PRD F-10 §8.1; MAKE-090) — kontrak: README.md bagian "Arsip F-10".
 *
 *   GET pegawai/{nip}/arsip                          folder agregat + identitas ringkas (+ catatan F10-17)
 *   GET pegawai/{nip}/arsip/berkas                   daftar berkas berhalaman (`id_jenis_rwy`, `q`, `sort`, `order`, …)
 *   GET pegawai/{nip}/arsip/berkas/{id}/buka         berkas unggahan v2 (privat) + audit `arsip_unduh` + throttle
 * Tahap 2 (MAKE-092, PRD F-10 §6, §8.1):
 *   GET    pegawai/{nip}/arsip/ekspor?format=xlsx|csv  ekspor (filter sama dengan daftar; role 1–7)
 *   GET    pegawai/{nip}/arsip/opsi-hubungkan?id_riwayat=  calon tujuan Hubungkan (role 1, 2, 3, 6, 7)
 *   PATCH  pegawai/{nip}/arsip/berkas/{id}             `{ tag }` Ubah Nama
 *   POST   pegawai/{nip}/arsip/berkas/{id}/hubungkan   `{ id_riwayat, id_entri? }`
 *   DELETE pegawai/{nip}/arsip/berkas/{id}             hapus baris kode 0 (berkas fisik tertinggal)
 *
 * Route: grup jwt + `Role::filter(1–7)` (baca/ekspor) atau `Role::filter(1, 2, 3, 6, 7)` (tulis); lingkup & kepemilikan
 * di ArsipService/AksiArsip/OtorisasiArsip. NIP dari route selalu string (castNumericParams mati: NIP 18 digit tidak
 * boleh menjadi int).
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

    public function ekspor(string $nip): ResponseInterface
    {
        $berkas = $this->arsip()->ekspor(service('authContext'), $nip, (array) $this->request->getGet());

        return $this->response
            ->setStatusCode(200)
            ->setHeader('Content-Type', $berkas['mime'])
            ->setHeader('Content-Disposition', 'attachment; filename="' . $berkas['nama'] . '"')
            ->setHeader('X-Content-Type-Options', 'nosniff')
            ->removeHeader('Cache-Control')
            ->setHeader('Cache-Control', 'no-store')
            ->setBody($berkas['isi']);
    }

    public function opsiHubungkan(string $nip): ResponseInterface
    {
        return $this->respondSuccess($this->aksi()->opsiHubungkan(service('authContext'), $nip, $this->request->getGet('id_riwayat')));
    }

    public function ubah(string $nip, string $id): ResponseInterface
    {
        $auth = service('authContext');
        $this->aksi()->ubahNama($auth, $nip, (int) $id, $this->payload());

        return $this->respondSuccess($this->arsip()->item($auth, $nip, (int) $id));
    }

    public function hubungkan(string $nip, string $id): ResponseInterface
    {
        $auth = service('authContext');
        $this->aksi()->hubungkan($auth, $nip, (int) $id, $this->payload());

        return $this->respondSuccess($this->arsip()->item($auth, $nip, (int) $id));
    }

    public function hapus(string $nip, string $id): ResponseInterface
    {
        return $this->respondSuccess(['deleted' => true, 'soft_delete' => false, 'item' => $this->aksi()->hapus(service('authContext'), $nip, (int) $id)]);
    }

    private function arsip(): ArsipService
    {
        return new ArsipService(db_connect(), service('pegawaiScope'), service('storageAdapter'), config(Arsip::class), null, null, null, service('riwayatRegistry'));
    }

    private function aksi(): AksiArsip
    {
        return new AksiArsip(db_connect(), new OtorisasiArsip(service('pegawaiScope'), db_connect(), service('riwayatRegistry')));
    }
}
