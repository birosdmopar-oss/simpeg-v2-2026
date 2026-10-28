<?php

declare(strict_types=1);

namespace App\Controllers\Api\Presensi;

use App\Controllers\Api\ApiController;
use App\Libraries\Presensi\Laporan\DaftarPegawaiTukinService;
use App\Libraries\Presensi\Laporan\LaporanTukinService;
use App\Libraries\Presensi\Laporan\RekapTukinService;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Laporan Tukin (D-08, MAKE-041, PRD 5B §6.1) — controller tipis; akses, lingkup, dan validasi di service. Kontrak:
 * README.md bagian "Laporan Tukin".
 */
final class LaporanTukinController extends ApiController
{
    protected bool $castNumericParams = false;

    public function pegawai(): ResponseInterface
    {
        return $this->respondSuccess((new DaftarPegawaiTukinService())->daftar(service('authContext'), $this->query()));
    }

    public function pegawaiIl(): ResponseInterface
    {
        return $this->respondSuccess((new DaftarPegawaiTukinService())->daftar(service('authContext'), $this->query(), true));
    }

    public function laporan(string $nip): ResponseInterface
    {
        return $this->respondSuccess((new LaporanTukinService())->laporan(service('authContext'), $nip, $this->query()));
    }

    public function laporanPdf(string $nip): ResponseInterface
    {
        return $this->berkas((new LaporanTukinService())->pdf(service('authContext'), $nip, $this->query()));
    }

    public function rekap(): ResponseInterface
    {
        return $this->respondSuccess((new RekapTukinService())->daftar(service('authContext'), $this->query()));
    }

    public function rekapXlsx(): ResponseInterface
    {
        return $this->berkas((new RekapTukinService())->xlsx(service('authContext'), $this->query()));
    }

    public function rekapPdf(): ResponseInterface
    {
        return $this->berkas((new RekapTukinService())->pdf(service('authContext'), $this->query()));
    }

    public function rekapIl(): ResponseInterface
    {
        return $this->respondSuccess((new RekapTukinService())->daftar(service('authContext'), $this->query(), true));
    }

    public function rekapIlXlsx(): ResponseInterface
    {
        return $this->berkas((new RekapTukinService())->xlsx(service('authContext'), $this->query(), true));
    }

    /**
     * @return array<string, mixed>
     */
    private function query(): array
    {
        $query = $this->request->getGet();

        return is_array($query) ? $query : [];
    }

    /**
     * @param array{isi: string, nama: string, mime: string} $berkas
     */
    private function berkas(array $berkas): ResponseInterface
    {
        return $this->response
            ->setStatusCode(200)
            ->setHeader('Content-Type', $berkas['mime'])
            ->setHeader('Content-Disposition', 'attachment; filename="' . $berkas['nama'] . '"')
            ->setHeader('X-Content-Type-Options', 'nosniff')
            ->setHeader('Cache-Control', 'private, no-store')
            ->setBody($berkas['isi']);
    }
}
