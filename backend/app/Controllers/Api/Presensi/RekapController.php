<?php

declare(strict_types=1);

namespace App\Controllers\Api\Presensi;

use App\Controllers\Api\ApiController;
use App\Libraries\Presensi\Laporan\RekapKehadiranService;
use App\Libraries\Presensi\Laporan\RekapUangMakanService;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Rekap kehadiran & rekap uang makan (D-09b, MAKE-042, PRD 5B §6.2) — controller tipis; lingkup role & validasi di service.
 * Kontrak: README.md bagian "Rekap kehadiran & uang makan".
 */
final class RekapController extends ApiController
{
    public function kehadiran(): ResponseInterface
    {
        return $this->respondSuccess((new RekapKehadiranService())->daftar(service('authContext'), $this->query()));
    }

    public function kehadiranXlsx(): ResponseInterface
    {
        return $this->berkas((new RekapKehadiranService())->xlsx(service('authContext'), $this->query()));
    }

    public function kehadiranXlsxHarian(): ResponseInterface
    {
        return $this->berkas((new RekapKehadiranService())->xlsxHarian(service('authContext'), $this->query()));
    }

    public function uangMakan(): ResponseInterface
    {
        return $this->respondSuccess((new RekapUangMakanService())->daftar(service('authContext'), $this->query()));
    }

    public function uangMakanXlsx(): ResponseInterface
    {
        return $this->berkas((new RekapUangMakanService())->xlsx(service('authContext'), $this->query()));
    }

    public function uangMakanIl(): ResponseInterface
    {
        return $this->respondSuccess((new RekapUangMakanService())->daftar(service('authContext'), $this->query(), true));
    }

    public function uangMakanIlXlsx(): ResponseInterface
    {
        return $this->berkas((new RekapUangMakanService())->xlsx(service('authContext'), $this->query(), true));
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
