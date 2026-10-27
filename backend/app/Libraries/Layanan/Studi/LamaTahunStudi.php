<?php

declare(strict_types=1);

namespace App\Libraries\Layanan\Studi;

/**
 * MAKE-024 (bagian murni) — `lama_tahun` TB/IB (DECIMAL(10,2), DDL `riwayat_tb` :6559, `riwayat_ib` :4911), dihitung
 * server [V2 Q-03] dengan rumus legacy [K TB-17, `controllers/hr/services/Local.php:3203-3214` `calculate_ltb`]:
 *
 *     diff = DateTime(tgl_mulai)->diff(DateTime(tgl_akhir));
 *     lama = diff.y + (diff.m > 0 ? diff.m / 12 : 0);   // sisa hari dibuang, tanggal akhir tidak inklusif
 *     number_format(lama, 1)                             // 1 desimal, setengah menjauhi nol
 *
 * Contoh [K]: 29-02-2024 s.d. 28-02-2025 = 0,9; 01-09-2024 s.d. 31-08-2028 = 3,9 (bukan 4,0); 31-01-2024 s.d.
 * 01-03-2024 = 0. Disimpan dua desimal (`3.90`) sesuai tipe kolom; nilai klien diabaikan.
 */
final class LamaTahunStudi
{
    private function __construct()
    {
    }

    /**
     * Lama tahun 1 desimal (float). Pemanggil wajib memastikan `tgl_akhir >= tgl_mulai` (ValidasiStudi).
     */
    public static function hitung(string $tglMulai, string $tglAkhir): float
    {
        $s    = RentangTanggal::selisih($tglMulai, $tglAkhir);
        $lama = $s['tahun'] + ($s['bulan'] > 0 ? $s['bulan'] / 12 : 0);

        // number_format() legacy membulatkan setengah menjauhi nol = round(..., 1) PHP_ROUND_HALF_UP.
        return round($lama, 1);
    }

    /**
     * Nilai untuk kolom DECIMAL(10,2): "3.90".
     */
    public static function nilaiKolom(string $tglMulai, string $tglAkhir): string
    {
        return number_format(self::hitung($tglMulai, $tglAkhir), 2, '.', '');
    }
}
