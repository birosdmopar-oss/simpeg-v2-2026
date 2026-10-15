<?php

declare(strict_types=1);

namespace App\Libraries\Kepegawaian\Konket;

use DateTimeImmutable;

/**
 * Aturan tanggal murni Konket (B-13, WS-2 M5 MAKE-013) — tanpa DB/jam, mudah diuji.
 *
 * Sumber [K]: `libraries/hr/rwy/L_konket.php` konket_min_date() (:981) dan perhitungan lama konket sakit
 * (validate_param() :1606-1625; form() :112-135).
 */
final class KonketAturan
{
    private function __construct()
    {
    }

    /**
     * Tanggal minimal mulai konket untuk $hariIni (Y-m-d, zona WIB) — port persis konket_min_date() legacy, termasuk
     * pengecualian bulan tertentu (Des 2021, Des 2023, Jun 2024, Des 2024, Des 2025). Aturan umum: tanggal 1–3 → tanggal 16
     * bulan lalu; 4–18 → tanggal 1 bulan ini; 19 ke atas → tanggal 16 bulan ini.
     */
    public static function tanggalMinimal(string $hariIni): string
    {
        $hari  = new DateTimeImmutable($hariIni);
        $tgl   = (int) $hari->format('j');
        $bulan = $hari->format('Y-m');
        $lalu  = $hari->modify('first day of last month')->format('Y-m');

        return match ($bulan) {
            '2021-12' => '2021-12-01',
            '2023-12' => $tgl < 3 ? "{$lalu}-01" : "{$bulan}-01",
            '2024-06' => $tgl < 19 ? "{$bulan}-01" : "{$bulan}-16",
            '2024-12' => $tgl <= 3 ? "{$lalu}-16" : ($tgl <= 16 ? "{$bulan}-01" : "{$bulan}-16"),
            '2025-12' => $tgl <= 3 ? "{$lalu}-16" : ($tgl <= 15 ? "{$bulan}-01" : "{$bulan}-16"),
            default   => $tgl <= 3 ? "{$lalu}-16" : ($tgl <= 18 ? "{$bulan}-01" : "{$bulan}-16"),
        };
    }

    /**
     * Hari kerja dalam [$mulai, $akhir] (Y-m-d): bukan Sabtu/Minggu dan bukan hari libur.
     *
     * @param array<string, true> $libur tanggal libur (Y-m-d) => true
     *
     * @return list<string>
     */
    public static function hariKerja(string $mulai, string $akhir, array $libur): array
    {
        $hasil = [];
        $hari  = new DateTimeImmutable($mulai);
        $batas = new DateTimeImmutable($akhir);

        while ($hari <= $batas) {
            $tanggal = $hari->format('Y-m-d');

            if ((int) $hari->format('N') < 6 && ! isset($libur[$tanggal])) {
                $hasil[] = $tanggal;
            }

            $hari = $hari->modify('+1 day');
        }

        return $hasil;
    }

    /**
     * Hari kerja terdekat sebelum $tanggal (legacy: mundur sampai bukan akhir pekan/libur).
     *
     * @param array<string, true> $libur
     */
    public static function hariKerjaSebelum(string $tanggal, array $libur): string
    {
        $hari = new DateTimeImmutable($tanggal);

        do {
            $hari = $hari->modify('-1 day');
        } while ((int) $hari->format('N') >= 6 || isset($libur[$hari->format('Y-m-d')]));

        return $hari->format('Y-m-d');
    }
}
