<?php

declare(strict_types=1);

namespace App\Libraries\Kepegawaian\Kalkulasi;

use InvalidArgumentException;

/**
 * CR-025 — masa kerja golongan (MKG) kalender murni, tanpa hari libur (legacy `libraries/hr/L_employee.php:2673-2698`
 * proyeksi mudig, masa kerja total :5724-5738). Bulan penuh memakai {@see TanggalBisnis::selisihBulanPenuh()}.
 */
final class MasaKerja
{
    private function __construct()
    {
    }

    /**
     * Masa dari `$dari` sampai `$sampai` (`$dari` ≤ `$sampai`) dalam tahun + bulan penuh.
     *
     * @return array{tahun: int, bulan: int}
     */
    public static function selisih(string $dari, string $sampai): array
    {
        return self::pecah(TanggalBisnis::selisihBulanPenuh($dari, $sampai));
    }

    /**
     * MKG baru = MKG lama + bulan penuh dari TMT lama ke TMT baru (legacy `L_employee.php:2674-2686`); kelebihan bulan
     * dibawa ke tahun.
     *
     * @return array{tahun: int, bulan: int}
     */
    public static function tambah(int $tahun, int $bulan, string $dari, string $sampai): array
    {
        if ($tahun < 0 || $bulan < 0) {
            throw new InvalidArgumentException("Masa kerja tidak boleh negatif: {$tahun} tahun {$bulan} bulan");
        }

        return self::pecah($tahun * 12 + $bulan + TanggalBisnis::selisihBulanPenuh($dari, $sampai));
    }

    /**
     * Perkiraan TMT CPNS dari NIP 18 digit: digit 9–14 = `YYYYMM`, tanggal 1 (legacy `L_employee.php:2689-2691`,
     * `kd_rkgb` :10381-10385). `null` bila NIP bukan 18 digit atau bulannya tidak valid.
     */
    public static function tmtCpnsDariNip(string $nip): ?string
    {
        if (preg_match('/^[0-9]{18}\z/', $nip) !== 1) {
            return null;
        }

        $tanggal = substr($nip, 8, 4) . '-' . substr($nip, 12, 2) . '-01';

        return TanggalBisnis::valid($tanggal) ? $tanggal : null;
    }

    /**
     * Format legacy `formatMker` (`helpers/function_helper.php:270-275`): (2, 5) → "02 Tahun 05 Bulan".
     */
    public static function format(int $tahun, int $bulan): string
    {
        if ($tahun < 0 || $bulan < 0) {
            throw new InvalidArgumentException("Masa kerja tidak boleh negatif: {$tahun} tahun {$bulan} bulan");
        }

        return sprintf('%02d Tahun %02d Bulan', $tahun, $bulan);
    }

    /**
     * @return array{tahun: int, bulan: int}
     */
    private static function pecah(int $bulan): array
    {
        return ['tahun' => intdiv($bulan, 12), 'bulan' => $bulan % 12];
    }
}
