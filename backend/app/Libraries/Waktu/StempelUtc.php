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
}
