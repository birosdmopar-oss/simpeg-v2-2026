<?php

declare(strict_types=1);

namespace App\Libraries\Presensi\Slip;

use App\Libraries\Presensi\SlipGaji\PeriodeSlip;

/**
 * Kontrak uang makan N−1 untuk slip gaji (PRD 5A §7.3.7, F5-1). 5A hanya menyediakan kontrak + aturan snapshot di
 * `SlipGajiService`; nilainya dihitung implementasi D-09 (5B) yang dipilih lewat `Config\Presensi::$sumberUangMakan`.
 * **Go-live dilarang** sebelum 5B mengganti implementasi bawaan {@see UangMakanBelumTersedia}.
 */
interface UangMakanSlipInterface
{
    /** Uang makan presensi bulan $periodeKehadiran (= periode slip ->sebelumnya()); null = sumber belum tersedia. */
    public function hitung(string $nip, PeriodeSlip $periodeKehadiran): ?UangMakanSlip;
}
