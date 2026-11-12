<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Interfaces\Kepegawaian\StorageAdapterInterface;
use App\Libraries\Kepegawaian\Arsip\HeaderBerkas;
use App\Libraries\Kepegawaian\Arsip\PenyajiUrlBerkas;
use App\Libraries\Kepegawaian\Lampiran\PathLegacy;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

/**
 * Rute KHUSUS MESIN DEV `GET berkas/<path>` (PRD F-10 §7.4; MAKE-090) — pengganti blok Nginx `/berkas/` saat backend
 * dijalankan tanpa Nginx. Didaftarkan di `RoutesPegawai.php` HANYA bila ENVIRONMENT === 'development' DAN
 * `arsip.sajiBerkasDev = true`; tidak pernah ada di testing/production.
 *
 * Meniru Nginx: hanya DUA pola legacy `Config\Arsip` (`arsip/…`, `assets/upload/…`), kunci v2 dan lainnya → 404; tanpa
 * cek izin (F10-18 (a)); tipe dari daftar tertutup; header sama dengan snippet §7.3 (`Cache-Control: no-store`, tanpa
 * nama berkas di `Content-Disposition`). Dibaca lewat `StorageAdapterInterface::baca()` (LocalStorageAdapter: realpath
 * wajib di dalam akar). Nama berkas dengan karakter di luar `permittedURIChars` tidak bisa dibuka lewat rute ini
 * (batasan yang diterima, QA memakai Nginx).
 */
class BerkasDevController extends BaseController
{
    public function saji(string ...$segmen): ResponseInterface
    {
        $normal = PathLegacy::normalisasi(implode('/', array_map('rawurldecode', $segmen)));

        if ($normal === null || ! PenyajiUrlBerkas::polaLegacy($normal)) {
            return $this->tidakAda();
        }

        try {
            $isi = $this->storage()->baca($normal);
        } catch (Throwable) {
            return $this->tidakAda();
        }

        $ekstensi = PenyajiUrlBerkas::ekstensi($normal);
        $tipe     = PenyajiUrlBerkas::tipe($ekstensi);
        $this->response->setStatusCode(200)->setBody($isi);

        foreach (HeaderBerkas::untuk($tipe, $tipe !== \Config\Arsip::TIPE_UNDUH, null, 'no-store') as $nama => $nilai) {
            $this->response->removeHeader($nama)->setHeader($nama, $nilai);
        }

        return $this->response;
    }

    private function tidakAda(): ResponseInterface
    {
        return $this->response->setStatusCode(404)->removeHeader('Cache-Control')->setHeader('Cache-Control', 'no-store')->setBody('');
    }

    private function storage(): StorageAdapterInterface
    {
        return service('storageAdapter');
    }
}
