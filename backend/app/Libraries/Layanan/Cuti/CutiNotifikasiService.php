<?php

declare(strict_types=1);

namespace App\Libraries\Layanan\Cuti;

use App\Libraries\Auth\AuthContext;

/**
 * Titik kirim notifikasi cuti (PRD Fase 4 §6.6.2). Kerangka tahap A; diisi penuh pada tahap notifikasi.
 */
class CutiNotifikasiService
{
    /**
     * @param array<string, mixed> $cuti
     */
    public function suratTerbit(array $cuti, AuthContext $auth): void
    {
    }
}
