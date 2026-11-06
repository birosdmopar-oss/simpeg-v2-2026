<?php

declare(strict_types=1);

namespace App\Controllers\Simapi;

use App\Controllers\BaseController;
use App\Libraries\Integrasi\Klien\HakKlien;
use App\Libraries\Integrasi\Simapi\PenyajiDm;
use App\Libraries\Integrasi\Simapi\ResponsSimapi;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * MAKE-059 (H-07) — `simapi/v1/a_dm/*` [K `A_dm.php`]. Semua method lewat filter `simapiToken`, termasuk `jabatan` yang di
 * legacy tanpa auth (F6-03, AC-07.1). Selain `jabatan`, klien bukan type 2 menerima `status "01"` data kosong [K `:48-50`].
 */
class ADm extends BaseController
{
    public function groupJabatan(): ResponseInterface
    {
        return $this->khususType2() ?? ResponsSimapi::sukses((new PenyajiDm(db_connect()))->groupJabatan());
    }

    public function subGroupJabatan(): ResponseInterface
    {
        return $this->khususType2() ?? ResponsSimapi::sukses((new PenyajiDm(db_connect()))->subGroupJabatan());
    }

    public function jabatan(): ResponseInterface
    {
        return ResponsSimapi::sukses((new PenyajiDm(db_connect()))->jabatan());
    }

    public function strukturJabatan(): ResponseInterface
    {
        return $this->khususType2() ?? ResponsSimapi::sukses((new PenyajiDm(db_connect()))->strukturJabatan());
    }

    private function khususType2(): ?ResponseInterface
    {
        return service('klienSaatIni')->jenis() === HakKlien::JENIS_SIMAPI_BARU ? null : ResponsSimapi::kosong();
    }
}
