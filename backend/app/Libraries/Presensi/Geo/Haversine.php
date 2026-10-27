<?php

declare(strict_types=1);

namespace App\Libraries\Presensi\Geo;

/**
 * Jarak lingkaran besar (rumus haversine) antara dua titik lintang/bujur dalam meter, bumi bola R = 6.371.000 m
 * (PRD 5A §7.5.4). Pengganti `calcDistance` legacy di klien (`views/user/dashboard_pns.php:664-743`), kini dihitung
 * server [V2] F5-9. Murni.
 */
final class Haversine
{
    public const JARI_JARI_BUMI_METER = 6371000.0;

    private function __construct()
    {
    }

    public static function jarakMeter(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $phi1 = deg2rad($lat1);
        $phi2 = deg2rad($lat2);
        $dPhi = deg2rad($lat2 - $lat1);
        $dLam = deg2rad($lng2 - $lng1);

        $a = sin($dPhi / 2) ** 2 + cos($phi1) * cos($phi2) * sin($dLam / 2) ** 2;

        // min(1, …): galat pembulatan pada titik antipodal dapat membuat a sedikit > 1 (asin di luar domain → NAN).
        return 2 * self::JARI_JARI_BUMI_METER * asin(min(1.0, sqrt($a)));
    }

    public static function antara(Koordinat $a, Koordinat $b): float
    {
        return self::jarakMeter($a->lat, $a->lng, $b->lat, $b->lng);
    }
}
