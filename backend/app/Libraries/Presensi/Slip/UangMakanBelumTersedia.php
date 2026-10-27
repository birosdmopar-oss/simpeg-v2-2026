<?php

declare(strict_types=1);

namespace App\Libraries\Presensi\Slip;

use App\Libraries\Presensi\SlipGaji\PeriodeSlip;

/**
 * Implementasi 5A: selalu null (sumber belum tersedia) — 5A DILARANG menghitung uang makan (PRD 5A §7.3.7). Akibatnya
 * kolom uang makan slip tidak disentuh dan respons buka memuat `uang_makan.tersedia = false`.
 */
final class UangMakanBelumTersedia implements UangMakanSlipInterface
{
    public function hitung(string $nip, PeriodeSlip $periodeKehadiran): ?UangMakanSlip
    {
        return null;
    }
}
