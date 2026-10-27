<?php

declare(strict_types=1);

namespace App\Controllers\Api\Presensi;

use App\Controllers\Api\ApiController;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Presensi\Kehadiran\PresensiManualService;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * D-03 presensi manual akun khusus (PRD 5A §6.5, §7.6) — controller tipis; otorisasi akun khusus & validasi di
 * {@see PresensiManualService}. Hapus lewat method DELETE [V2] (P5-13/P5-24).
 */
final class PresensiManualController extends ApiController
{
    protected bool $castNumericParams = false;

    public function tambah(): ResponseInterface
    {
        return $this->respondSuccess($this->service()->tambah($this->auth(), $this->payload()), 201);
    }

    public function ubah(string $nip, string $tgl): ResponseInterface
    {
        return $this->respondSuccess($this->service()->ubah($this->auth(), $nip, $tgl, $this->payload()));
    }

    public function hapus(string $nip, string $tgl): ResponseInterface
    {
        $this->service()->hapus($this->auth(), $nip, $tgl);

        return $this->respondSuccess(null);
    }

    private function service(): PresensiManualService
    {
        return new PresensiManualService();
    }

    private function auth(): AuthContext
    {
        return service('authContext');
    }
}
