<?php

declare(strict_types=1);

namespace App\Libraries\Presensi\Geo;

/**
 * Hasil {@see PenilaianGeofence::nilai()}. Jarak dalam meter (belum dibulatkan; pesan galat membulatkan ke meter).
 */
final readonly class HasilGeofence
{
    public function __construct(
        /** Lokasi sah terdekat (jarak ≤ radius); null = di luar radius semua lokasi. */
        public ?LokasiGeofence $pengesah,
        public ?float $jarakPengesah,
        /** Lokasi terdekat di antara semua lokasi (sah atau tidak); null bila daftar lokasi kosong. */
        public ?LokasiGeofence $terdekat,
        public ?float $jarakTerdekat,
    ) {
    }

    public function dalamRadius(): bool
    {
        return $this->pengesah !== null;
    }

    /** Jarak terdekat dibulatkan ke meter (pesan `di_luar_radius` dan respons `cek-lokasi`). */
    public function jarakTerdekatMeter(): ?int
    {
        return $this->jarakTerdekat === null ? null : (int) round($this->jarakTerdekat);
    }
}
