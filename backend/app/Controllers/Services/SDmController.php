<?php

declare(strict_types=1);

namespace App\Controllers\Services;

use App\Controllers\BaseController;
use App\Libraries\Integrasi\Layanan\PenyajiSDm;
use App\Libraries\Integrasi\Layanan\ResponsLayanan;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * MAKE-059 (H-07, F6-10) — `services/s_dm/*` [K `S_dm.php`]. Semua method lewat filter `klienLayanan:dm` (legacy: `list_*`
 * memakai `sal_user`, `p_*` publik — F6-03). Pesan gagal bisnis persis legacy. `p_web_config` tidak dibuat (F6-10).
 */
class SDmController extends BaseController
{
    private const PESAN_PARAMETER = 'Invalid parameter.'; // [K :77]

    public function listUnit(): ResponseInterface
    {
        return ResponsLayanan::sukses($this->penyaji()->daftarUnit());
    }

    public function pListUnit(): ResponseInterface
    {
        return ResponsLayanan::sukses($this->penyaji()->daftarUnit());
    }

    public function pListUnitWoKep(): ResponseInterface
    {
        $idUnitKep = (string) (service('webConfigService')->value('id_unit_kepegawaian') ?? '');

        if ($idUnitKep === '') {
            return ResponsLayanan::gagal('Unit Kepegawaian tidak ditemukan didalam sistem.'); // [K :137-141]
        }

        return ResponsLayanan::sukses($this->penyaji()->daftarUnit($idUnitKep));
    }

    public function pGetUnit(): ResponseInterface
    {
        $idUnit = $this->parameter('id_unit');

        if ($idUnit === '') {
            return ResponsLayanan::gagal(self::PESAN_PARAMETER);
        }

        $unit = $this->penyaji()->unit($idUnit);

        return $unit === null ? ResponsLayanan::gagal('Unit Eselon I tidak terdaftar didalam sistem.') : ResponsLayanan::sukses($unit); // [K :176-183]
    }

    public function listSatker(): ResponseInterface
    {
        return $this->daftarSatker();
    }

    public function pListSatker(): ResponseInterface
    {
        return $this->daftarSatker();
    }

    public function pGetSatker(): ResponseInterface
    {
        $idSatker = $this->parameter('id_satker');

        if ($idSatker === '') {
            return ResponsLayanan::gagal(self::PESAN_PARAMETER);
        }

        $satker = $this->penyaji()->satker($idSatker);

        return $satker === null ? ResponsLayanan::gagal('Unit Eselon II tidak terdaftar didalam sistem.') : ResponsLayanan::sukses($satker); // [K :249-257]
    }

    private function daftarSatker(): ResponseInterface
    {
        $idUnit = $this->parameter('id_unit');

        if ($idUnit === '') {
            return ResponsLayanan::gagal(self::PESAN_PARAMETER);
        }

        return ResponsLayanan::sukses($this->penyaji()->daftarSatker($idUnit));
    }

    /**
     * Parameter query string legacy (`$_GET[...]`, kosong = tidak ada) [K].
     */
    private function parameter(string $nama): string
    {
        $nilai = $this->request->getGet($nama);

        return is_string($nilai) ? $nilai : '';
    }

    private function penyaji(): PenyajiSDm
    {
        return new PenyajiSDm(db_connect());
    }
}
