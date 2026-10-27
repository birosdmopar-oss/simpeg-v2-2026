<?php

declare(strict_types=1);

namespace App\Libraries\Layanan\Cuti;

/**
 * MAKE-022 M3 — pembentukan saldo tahun baru (PRD Fase 4 v1.3 §6.3.6, Q-02): rumus `calculate_yearly_sc_2`
 * (`Cron.php:2534-2663`) dengan satu perapian (lantai N ≥ 0, DQ-23). Murni: baris tahun lalu dan {@see AturanCuti}
 * diterima sebagai parameter.
 *
 * ```
 * pool  = min(12, ditangguhkan_lalu + min(6, N_lalu) + min(6, N1_lalu))   -- N-2 tahun lalu kedaluwarsa
 * N     = max(0, 12 − min(12, utang_lalu))
 * N-1   = min(6, pool);  N-2 = pool − N-1;  ditangguhkan = 0;  utang = 0
 * ```
 *
 * Batas D8 terpenuhi secara konstruksi (N ≤ 12, N-1 ≤ 6, N-2 ≤ 6, total ≤ 24). Perilaku [K] yang diwarisi: *pool*
 * menghilangkan asal-usul N-1/N-2 (N lalu = 2, N-1 lalu = 6 → N-1 = 6, N-2 = 2).
 */
final class SaldoTahunanCuti
{
    private function __construct()
    {
    }

    public static function bentuk(SaldoCuti $tahunLalu, AturanCuti $aturan): SaldoCuti
    {
        $batasPool = $aturan->maksN1 + $aturan->maksN2;
        $pool      = min(
            $batasPool,
            max(0, $tahunLalu->ditangguhkan)
            + min($aturan->maksN1, max(0, $tahunLalu->n))
            + min($aturan->maksN2, max(0, $tahunLalu->n1)),
        );

        $n  = max(0, $aturan->kuotaN - min($aturan->maksUtang, max(0, $tahunLalu->utang)));
        $n1 = min($aturan->maksN1, $pool);

        return new SaldoCuti($n, $n1, $pool - $n1, 0, 0);
    }

    /** Pegawai tanpa baris tahun lalu: pool 0, N penuh. */
    public static function tanpaTahunLalu(AturanCuti $aturan): SaldoCuti
    {
        return self::bentuk(new SaldoCuti(0), $aturan);
    }
}
