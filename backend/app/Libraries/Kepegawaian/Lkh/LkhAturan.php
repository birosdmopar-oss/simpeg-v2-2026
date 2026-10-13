<?php

declare(strict_types=1);

namespace App\Libraries\Kepegawaian\Lkh;

use DateTimeImmutable;

/**
 * Aturan tanggal murni LKH (B-12b, WS-2 M5 MAKE-013) — tanpa DB/jam.
 *
 * Sumber [K]: `libraries/hr/rwy/L_lkh.php` lkh_min_date() (:699), `controllers/hr/rwy/Lkh.php` edit() (:210-229) dan
 * delete() (:444-451).
 */
final class LkhAturan
{
    /** Banyak hari kerja terakhir (termasuk hari ini bila hari kerja) yang boleh dilaporkan. */
    public const HARI_KERJA = 4;

    private function __construct()
    {
    }

    /**
     * Tanggal minimal laporan untuk $hariIni (WIB): hari kerja ke-4 terakhir (dihitung mundur dari hari ini, melewati
     * akhir pekan dan hari libur, paling jauh 2 bulan). Mulai tanggal 5 tidak boleh sebelum tanggal 1 bulan berjalan.
     * Pengecualian legacy: 1–6 Des 2021 → 2021-12-01; Des 2023 batas tanggal 3; 2024-02-05 → 2024-01-29.
     * [V2] batas tanggal dibaca dari hari ini (legacy memakai tanggal hari ini setelah dimundurkan 2 bulan, sama kecuali
     * di akhir bulan pendek).
     *
     * @param array<string, true> $libur tanggal libur (Y-m-d) => true
     */
    public static function tanggalMinimal(string $hariIni, array $libur): string
    {
        if ($hariIni >= '2021-12-01' && $hariIni <= '2021-12-06') {
            return '2021-12-01';
        }

        $hari    = new DateTimeImmutable($hariIni);
        $batas   = $hari->modify('-2 month');
        $minimal = $hariIni;
        $hitung  = 0;

        for ($d = $hari; $d >= $batas && $hitung < self::HARI_KERJA; $d = $d->modify('-1 day')) {
            $tanggal = $d->format('Y-m-d');

            if ((int) $d->format('N') >= 6 || isset($libur[$tanggal])) {
                continue;
            }

            $minimal = $tanggal;
            $hitung++;
        }

        if ($hariIni === '2024-02-05') {
            return '2024-01-29';
        }

        $awalBulan = $hari->format('Y-m-01');
        $batasHari = $hari->format('Y-m') === '2023-12' ? 3 : 5;

        if ((int) $hari->format('j') >= $batasHari && $minimal < $awalBulan) {
            return $awalBulan;
        }

        return $minimal;
    }

    /**
     * LKH berstatus 0/1/3 dengan tanggal sebelum batas ini sudah masuk perhitungan tukin → tidak bisa diubah (legacy
     * edit(): mulai tanggal 5 = tanggal 1 bulan berjalan; sebelumnya = tanggal 1 bulan lalu).
     */
    public static function batasUbah(string $hariIni): string
    {
        $hari = new DateTimeImmutable($hariIni);

        return (int) $hari->format('j') >= 5 ? $hari->format('Y-m-01') : $hari->modify('first day of last month')->format('Y-m-d');
    }

    /**
     * LKH berstatus 0/1 dengan tanggal ≤ akhir bulan lalu tidak bisa dihapus (legacy delete()).
     */
    public static function batasHapus(string $hariIni): string
    {
        return (new DateTimeImmutable($hariIni))->modify('last day of last month')->format('Y-m-d');
    }
}
