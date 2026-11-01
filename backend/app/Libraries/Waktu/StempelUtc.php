<?php

declare(strict_types=1);

namespace App\Libraries\Waktu;

use App\Libraries\Kepegawaian\Kalkulasi\TanggalBisnis;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Batas tanggal bisnis (WIB) untuk kolom DATETIME v2 yang berisi stempel **UTC** (`created_at` dsb.; `appTimezone = UTC`,
 * `SiklusPegawai` menulis `gmdate`, impor DBV-011 R1a mengubah WIB → UTC). Tanggal bisnis v2 = WIB
 * ({@see TanggalBisnis::ZONA}); legacy membandingkan jam dinding WIB (`DATE(created_at)`, `created_at < 'Y-m-d'`).
 * Perbandingan dibentuk sebagai rentang setengah terbuka atas kolom mentah (ramah indeks), bukan `DATE(kolom)`
 * (CR-083, keputusan Horii 30-10). Bergantung pada GAP-2 (nilai `created_at` impor legacy = WIB yang dikonversi ke UTC).
 *
 * Bukan untuk kolom yang memang menyimpan waktu kejadian lokal (mis. `absen_ijin.date_start`/`date_end`, GAP-9).
 */
final class StempelUtc
{
    /**
     * Awal tanggal `$tanggal` (00:00 WIB) sebagai stempel UTC `Y-m-d H:i:s`: `2026-09-15` → `2026-09-14 17:00:00`.
     * Padanan legacy `kolom < 'Y-m-d'` = `kolom < StempelUtc::awalHari('Y-m-d')`.
     */
    public static function awalHari(string $tanggal): string
    {
        TanggalBisnis::wajibValid($tanggal, 'tanggal');

        return (new DateTimeImmutable($tanggal . ' 00:00:00', new DateTimeZone(TanggalBisnis::ZONA)))
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');
    }

    /**
     * Awal hari sesudah `$tanggal` (batas eksklusif): padanan legacy `DATE(kolom) <= 'Y-m-d'` =
     * `kolom < StempelUtc::awalHariBerikut('Y-m-d')`.
     */
    public static function awalHariBerikut(string $tanggal): string
    {
        TanggalBisnis::wajibValid($tanggal, 'tanggal');

        return (new DateTimeImmutable($tanggal . ' 00:00:00', new DateTimeZone(TanggalBisnis::ZONA)))
            ->modify('+1 day')
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');
    }

    /**
     * Stempel UTC `Y-m-d H:i:s` → jam dinding WIB, dalam `$format` (default `Y-m-d H:i:s`): `2026-09-14 17:00:00` →
     * `2026-09-15 00:00:00`. Nilai kosong/tak terbaca → `null`. Dipakai pembaca yang memotong atau mem-parse stempel
     * sebagai tanggal/jam bisnis (CR-086).
     */
    public static function keWib(?string $utc, string $format = 'Y-m-d H:i:s'): ?string
    {
        $waktu = self::parse($utc);

        return $waktu?->setTimezone(new DateTimeZone(TanggalBisnis::ZONA))->format($format);
    }

    /** Tanggal bisnis (WIB, `Y-m-d`) dari stempel UTC; `null` bila kosong/tak terbaca. */
    public static function tanggalWib(?string $utc): ?string
    {
        return self::keWib($utc, 'Y-m-d');
    }

    /** ISO-8601 dengan offset WIB (`2026-09-15T00:00:00+07:00`): instan tetap sama, potongan 10 karakter = tanggal WIB. */
    public static function keIsoWib(?string $utc): ?string
    {
        return self::keWib($utc, 'Y-m-d\TH:i:sP');
    }

    /** Epoch detik dari stempel UTC (bukan dibaca sebagai zona lain); `null` bila kosong/tak terbaca. */
    public static function epoch(?string $utc): ?int
    {
        return self::parse($utc)?->getTimestamp();
    }

    private static function parse(?string $utc): ?DateTimeImmutable
    {
        $utc = trim((string) $utc);

        if ($utc === '' || str_starts_with($utc, '0000-00-00')) {
            return null;
        }

        try {
            return new DateTimeImmutable($utc, new DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }
    }
}
