<?php

declare(strict_types=1);

namespace App\Libraries\Presensi\Geo;

/**
 * Satu lokasi presensi berlaku (`lokasi_presensi`, G-03) dalam bentuk siap dinilai geofence. Murni.
 *
 * `lokasi_presensi.latitude`/`longitude` bertipe VARCHAR(50) [K] (data impor bisa berisi teks bukan angka) dan
 * `radius` DOUBLE meter. {@see self::dariBaris()} mengembalikan null untuk baris yang koordinatnya bukan angka/di luar
 * rentang atau radiusnya bukan angka ≥ 0; pemanggil (resolver ber-DB) mengabaikan baris itu dan mencatat log, dan
 * menjawab 422 `lokasi_tidak_valid` bila SEMUA lokasi berlaku tidak sah (Q5A-11, fail-closed). Radius < 10 m hasil impor
 * dipakai apa adanya (G-03 K-3).
 */
final readonly class LokasiGeofence
{
    public function __construct(
        public int $id,
        public string $nama,
        public Koordinat $titik,
        public float $radius,
    ) {
    }

    /**
     * @param array<string, mixed> $baris kolom `id_lokasi_presensi`, `nama_lokasi`, `latitude`, `longitude`, `radius`
     */
    public static function dariBaris(array $baris): ?self
    {
        $id     = $baris['id_lokasi_presensi'] ?? null;
        $titik  = Koordinat::dariInput(self::teks($baris['latitude'] ?? null), self::teks($baris['longitude'] ?? null));
        $radius = $baris['radius'] ?? null;
        $radius = is_numeric($radius) ? (float) $radius : null;

        if (! is_numeric($id) || (int) $id <= 0 || $titik === null || $radius === null || ! is_finite($radius) || $radius < 0.0) {
            return null;
        }

        return new self((int) $id, (string) ($baris['nama_lokasi'] ?? ''), $titik, $radius);
    }

    /** Spasi tepi pada VARCHAR impor tidak mengubah arti angka. */
    private static function teks(mixed $nilai): mixed
    {
        return is_string($nilai) ? trim($nilai) : $nilai;
    }
}
