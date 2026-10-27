<?php

declare(strict_types=1);

namespace App\Libraries\Presensi\Slip;

use App\Libraries\Presensi\SlipGaji\PeriodeSlip;
use RuntimeException;

/**
 * Pelanggaran UNIQUE `uk_nip_bulan_tahun` (1062) saat commit impor slip — proses lain menyisipkan kunci yang sama setelah
 * validasi ulang (PRD 5A §7.2.4 butir 5). Ditangkap `ImporSlipService` → rollback + 409 `konflik_data`, tidak pernah 500.
 */
final class KonflikImporSlip extends RuntimeException
{
    public function __construct(public readonly string $nip, public readonly PeriodeSlip $periode)
    {
        parent::__construct("Kunci {$nip} {$periode->kunci()} sudah ada.");
    }
}
