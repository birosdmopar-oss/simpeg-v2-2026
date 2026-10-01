<?php

declare(strict_types=1);

namespace App\Libraries\MasterData;

/**
 * Aturan murni hari libur (G-08, DBV-003/CR-010) — tanpa DB, dipakai HariLiburService dan diuji unit.
 * Tanggal = string `YYYY-MM-DD`, sehingga perbandingan string sama dengan perbandingan tanggal.
 */
final class HariLiburRules
{
    /**
     * Rentang tahun yang diterima [V2]: menolak salah ketik (mis. 0026, 20260) yang tetap lolos format tanggal.
     */
    public const YEAR_MIN = 1900;
    public const YEAR_MAX = 2100;

    private function __construct()
    {
    }

    /**
     * Tepat `YYYY-MM-DD`, tanggal kalender yang ada (bukan 2026-02-30), tahun 1900-2100. `2026-1-1`, `31-12-2026`,
     * dan string kosong ditolak.
     */
    public static function isValidDate(string $date): bool
    {
        if (preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})\z/', $date, $m) !== 1) {
            return false;
        }

        [$year, $month, $day] = [(int) $m[1], (int) $m[2], (int) $m[3]];

        return $year >= self::YEAR_MIN && $year <= self::YEAR_MAX && checkdate($month, $day, $year);
    }

    /**
     * Tanggal selesai tidak boleh sebelum tanggal mulai (sama = libur satu hari). Sama dengan CHECK
     * `chk_hari_libur_rentang`.
     */
    public static function validRange(string $mulai, string $akhir): bool
    {
        return $akhir >= $mulai;
    }

    /**
     * Dua rentang inklusif beririsan (legacy L_presensi.php:20526): B mulai paling lambat akhir A dan B berakhir paling
     * cepat mulai A. Rentang yang hanya bersebelahan (A berakhir 1 Jan, B mulai 2 Jan) tidak beririsan.
     */
    public static function overlaps(string $mulaiA, string $akhirA, string $mulaiB, string $akhirB): bool
    {
        return $mulaiB <= $akhirA && $akhirB >= $mulaiA;
    }
}
