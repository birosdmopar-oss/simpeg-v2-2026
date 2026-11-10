<?php

declare(strict_types=1);

namespace App\Controllers\Api\Dashboard;

use App\Controllers\Api\ApiController;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Libraries\Dashboard\Beranda\DirekturBeranda;
use App\Libraries\Dashboard\Beranda\RingkasanBeranda;
use App\Libraries\Dashboard\Beranda\UlangTahunBeranda;
use App\Libraries\Kepegawaian\Kalkulasi\TanggalBisnis;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Dashboard;

/**
 * Beranda per role (F-13, MAKE-087; PRD Fase 7 v2.0 §5.10). Milik Jalur D. Kontrak: `README.md` modul ini.
 *
 * - `GET api/v1/dashboard/ringkasan` (jwt, role 1–8): bentuk per keluarga role — lihat {@see RingkasanBeranda}.
 * - `GET api/v1/dashboard/direktur` (role 1, 4): masa kerja direktur politeknik — {@see DirekturBeranda}.
 * - `GET api/v1/dashboard/ulang-tahun?hari=ini|besok` (jwt, role 1–8): {@see UlangTahunBeranda}.
 * - `GET api/v1/dashboard/ulang-tahun/{nip}/foto` (jwt, role 1–8): foto HANYA untuk NIP di daftar ulang tahun hari ini/besok
 *   (Q-D19); selain itu / tanpa foto → 404 seragam. Pembaca foto = `BiodataServiceInterface::fotoSistem` (tanpa cek lingkup).
 */
final class BerandaController extends ApiController
{
    /** NIP dari route tetap string (NIP 18 digit tidak boleh menjadi int; pola PegawaiController). */
    protected bool $castNumericParams = false;

    public function ringkasan(): ResponseInterface
    {
        $layanan = new RingkasanBeranda(db_connect(), config(Dashboard::class), service('cacheService'));

        return $this->respondSuccess($layanan->ringkasan(service('authContext')));
    }

    public function direktur(): ResponseInterface
    {
        $daftar = (new DirekturBeranda(db_connect(), config(Dashboard::class), TanggalBisnis::hariIni()))->daftar();

        return $this->respondSuccess(['items' => $daftar]);
    }

    public function ulangTahun(): ResponseInterface
    {
        $hari = $this->request->getGet('hari') ?? UlangTahunBeranda::HARI_INI;

        if (! in_array($hari, [UlangTahunBeranda::HARI_INI, UlangTahunBeranda::BESOK], true)) {
            throw ValidationException::forField('hari', 'Hari harus ini atau besok.');
        }

        return $this->respondSuccess(['hari' => $hari, 'items' => $this->ulangTahunBeranda()->daftar($hari)]);
    }

    public function fotoUlangTahun(string $nip): ResponseInterface
    {
        $foto = $this->ulangTahunBeranda()->adaDiDaftar($nip) ? service('biodataService')->fotoSistem($nip) : null;

        if ($foto === null) {
            throw new NotFoundException('Foto tidak tersedia.');
        }

        return $this->response
            ->setStatusCode(200)
            ->setContentType($foto['mime'])
            ->setHeader('Content-Disposition', 'inline; filename="' . preg_replace('/[^A-Za-z0-9._-]+/', '_', $foto['nama']) . '"')
            ->setHeader('X-Content-Type-Options', 'nosniff')
            ->setHeader('Cache-Control', 'private, no-store')
            ->setBody($foto['isi']);
    }

    private function ulangTahunBeranda(): UlangTahunBeranda
    {
        return new UlangTahunBeranda(db_connect(), config(Dashboard::class), TanggalBisnis::hariIni());
    }
}
