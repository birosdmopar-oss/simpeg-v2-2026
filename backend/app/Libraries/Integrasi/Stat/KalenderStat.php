<?php

declare(strict_types=1);

namespace App\Libraries\Integrasi\Stat;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * MAKE-061 (H-08) — pembantu tanggal murni untuk port `services/s_stat` dengan semantik legacy CodeIgniter 3:
 *
 * - `date("Y"/"m", strtotime($s))` pada string kosong/tidak valid: `strtotime` mengembalikan `false`, `date(..., false)`
 *   membaca epoch 1970-01-01 → tahun 1970, bulan 1 ({@see self::tahunLegacy()}, {@see self::bulanLegacy()}). Perilaku ini
 *   dipertahankan [K] agar deteksi `get_all_kp` untuk pegawai tanpa TMT pangkat sama dengan legacy.
 * - `new DateTime("$s 00:00:00")` pada string kosong = hari ini ({@see self::tanggalAtauHariIni()}).
 *
 * Tidak membaca jam sistem: "hari ini" selalu diterima dari pemanggil (tanggal bisnis WIB, Q4).
 */
final class KalenderStat
{
    public const EPOCH = '1970-01-01';

    private function __construct()
    {
    }

    public static function valid(?string $ymd): bool
    {
        return is_string($ymd) && preg_match('/^(\d{4})-(\d{2})-(\d{2})\z/', $ymd, $m) === 1
            && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    /**
     * Parse `YYYY-MM-DD` dengan jam nol; tidak valid → `InvalidArgumentException` (kesalahan program, bukan input klien).
     */
    public static function parse(string $ymd): DateTimeImmutable
    {
        $hasil = self::valid($ymd) ? DateTimeImmutable::createFromFormat('!Y-m-d', $ymd) : false;

        if ($hasil === false) {
            throw new InvalidArgumentException("Tanggal tidak valid: {$ymd}");
        }

        return $hasil;
    }

    /** `date("Y", strtotime($s))` legacy: kosong/tidak valid → 1970. */
    public static function tahunLegacy(?string $ymd): int
    {
        return (int) self::parse(self::valid($ymd) ? (string) $ymd : self::EPOCH)->format('Y');
    }

    /** `date("m", strtotime($s))` legacy: kosong/tidak valid → 1. */
    public static function bulanLegacy(?string $ymd): int
    {
        return (int) self::parse(self::valid($ymd) ? (string) $ymd : self::EPOCH)->format('n');
    }

    /** `new DateTime("$s 00:00:00")` legacy: kosong → hari ini. */
    public static function tanggalAtauHariIni(?string $ymd, string $hariIni): DateTimeImmutable
    {
        return self::parse(self::valid($ymd) ? (string) $ymd : $hariIni);
    }

    /**
     * Selisih `y*12 + m` seperti `DateInterval` legacy (`$a->diff($b)`), tanpa tanda.
     */
    public static function selisihBulan(DateTimeImmutable $a, DateTimeImmutable $b): int
    {
        $d = $a->diff($b);

        return $d->y * 12 + $d->m;
    }
}
