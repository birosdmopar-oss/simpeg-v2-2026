<?php

declare(strict_types=1);

namespace App\Controllers\Api\Presensi;

use App\Controllers\Api\ApiController;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Presensi\Kehadiran\DataPresensiService;
use App\Libraries\Presensi\Kehadiran\PresensiOnlineService;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * D-02 presensi online + data presensi (PRD 5A §6.4, §7.5, §7.6.4) — controller tipis; aturan di
 * {@see PresensiOnlineService} dan {@see DataPresensiService}. Koordinat dibaca dari body JSON, tidak pernah dari query.
 */
final class PresensiController extends ApiController
{
    protected bool $castNumericParams = false;

    public function hariIni(): ResponseInterface
    {
        return $this->respondSuccess($this->online()->hariIni($this->auth()));
    }

    public function cekLokasi(): ResponseInterface
    {
        return $this->respondSuccess($this->online()->cekLokasi($this->auth(), $this->payload()));
    }

    public function masuk(): ResponseInterface
    {
        [$status, $data] = $this->online()->masuk($this->auth(), $this->payload());

        return $this->respondSuccess($data, $status);
    }

    public function pulang(): ResponseInterface
    {
        [$status, $data] = $this->online()->pulang($this->auth(), $this->payload());

        return $this->respondSuccess($data, $status);
    }

    public function saya(): ResponseInterface
    {
        return $this->respondSuccess($this->online()->riwayatSaya($this->auth(), $this->query()));
    }

    public function pegawai(): ResponseInterface
    {
        return $this->respondSuccess((new DataPresensiService())->daftarPegawai($this->auth(), $this->query()));
    }

    public function pegawaiDetail(string $nip): ResponseInterface
    {
        return $this->respondSuccess((new DataPresensiService())->detailPegawai($this->auth(), $nip, $this->query()));
    }

    /**
     * @return array<string, mixed>
     */
    private function query(): array
    {
        $q = $this->request->getGet();

        return is_array($q) ? $q : [];
    }

    private function online(): PresensiOnlineService
    {
        return new PresensiOnlineService();
    }

    private function auth(): AuthContext
    {
        return service('authContext');
    }
}
