<?php

declare(strict_types=1);

namespace App\Controllers\Api\Integrasi;

use App\Controllers\Api\ApiController;
use App\Libraries\Integrasi\Log\DaftarLogIntegrasi;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * `GET api/v1/integrasi/log` (role 1, PRD Fase 6 §6.2, F6-24; MAKE-078 ISSUE-058): daftar `integrasi_log` terbaru dulu,
 * filter `layanan`, `status`, `tanggal_mulai`/`tanggal_selesai` (WIB), paginasi `page`/`per_page`. Amplop v2
 * `{items, total, page, per_page, layanan_tersedia}`; filter salah → 422 per parameter. Rincian: {@see DaftarLogIntegrasi}.
 */
final class LogIntegrasiController extends ApiController
{
    public function index(): ResponseInterface
    {
        /** @var array<string, mixed> $filters */
        $filters = $this->request->getGet() ?? [];

        return $this->respondSuccess((new DaftarLogIntegrasi(db_connect()))->daftar($filters));
    }
}
