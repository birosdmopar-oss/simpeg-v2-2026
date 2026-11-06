<?php

declare(strict_types=1);

namespace App\Controllers\Api\Layanan;

use App\Controllers\Api\ApiController;
use App\Libraries\Layanan\Cuti\EsignCutiService;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Penandatanganan surat cuti ber-TTE (PRD Fase 4 §6.5 C-05; PRD Fase 6 F6-18, keputusan Horii 03-11-2026) —
 * `POST cuti/{id}/esign`: semua akun login lolos filter, hanya penanda tangan pemilik tugas yang diterima service (403
 * selainnya), batas 5 percobaan gagal / 15 menit (429). Passphrase hanya dibaca dari body JSON dan tidak pernah dicatat,
 * disimpan, atau dikembalikan. `atas_nama` (Q6-40, MAKE-075): Super Admin pada cuti yang final-nya diproses atas nama boleh
 * menandatangani dengan passphrase pejabat penanda tangan; route & limiter sama dengan alur pejabat.
 */
final class CutiEsignController extends ApiController
{
    public function esign(int $id): ResponseInterface
    {
        $agent = $this->request->getUserAgent();

        return $this->respondSuccess(
            $this->service()->terbitkan(service('authContext'), $id, $this->payload(), $this->request->getIPAddress(), $agent->getAgentString()),
        );
    }

    /**
     * `GET cuti/{id}/esign/status` — cek sertifikat penanda tangan sebelum dialog passphrase (ikut legacy `Cuti.php:687-705`).
     */
    public function status(int $id): ResponseInterface
    {
        // Q6-40 (MAKE-075): `?atas_nama=1` — Super Admin pada cuti yang final-nya diproses atas nama.
        $atasNama = in_array($this->request->getGet('atas_nama'), ['1', 'true'], true);

        return $this->respondSuccess($this->service()->statusSertifikat(service('authContext'), $id, $atasNama));
    }

    private function service(): EsignCutiService
    {
        return service('esignCutiService');
    }
}
