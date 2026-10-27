<?php

declare(strict_types=1);

namespace App\Libraries\Presensi\Slip;

/**
 * Hasil uang makan satu bulan kehadiran (PRD 5A §7.3.7), int sen tanpa float.
 */
final readonly class UangMakanSlip
{
    public function __construct(public int $totalSen, public int $hari, public int $tarifSen)
    {
    }
}
