<?php

declare(strict_types=1);

namespace App\Libraries\Presensi\Tukin;

final class KalkulasiTukin
{
    /** @param list<string> $hariLibur @param array<string, array<string, mixed>> $harian */
    public static function hitung(PeriodeTukin $periode, array $hariLibur, array $harian, KonfigurasiTukin $config): HasilTukin
    {
        $jumlah = ['TL1' => 0, 'TL2' => 0, 'TL3' => 0, 'PSW1' => 0, 'PSW2' => 0, 'PSW3' => 0, 'TPM' => 0, 'TPP' => 0, 'TK' => 0];
        $total = 0.0;
        $hariKerja = HariKerja::daftar($periode, $hariLibur);
        foreach ($hariKerja as $tanggal) {
            $hasil = StatusHarian::evaluasi($tanggal, $harian[$tanggal] ?? [], $config);
            $total += $hasil->potongan;
            if ($hasil->status === 'TK') $jumlah['TK']++;
            elseif (in_array($hasil->status, ['TPM', 'TPP'], true)) $jumlah[$hasil->status]++;
            else {
                if ($hasil->tlMenit > 0) $jumlah['TL' . ($hasil->tlMenit <= 30 ? '1' : ($hasil->tlMenit <= 60 ? '2' : '3'))]++;
                if ($hasil->pswMenit > 0) $jumlah['PSW' . ($hasil->pswMenit <= 30 ? '1' : ($hasil->pswMenit <= 60 ? '2' : '3'))]++;
            }
        }
        return new HasilTukin($periode, count($hariKerja), $jumlah, $total);
    }
}
