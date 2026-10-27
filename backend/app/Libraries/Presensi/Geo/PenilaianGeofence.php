<?php

declare(strict_types=1);

namespace App\Libraries\Presensi\Geo;

/**
 * Penilaian geofence server (PRD 5A §7.5.4, F5-9/F5-10). Murni; dipakai bersama oleh `POST presensi/cek-lokasi` dan
 * rekam masuk/pulang sehingga putusannya selalu sama untuk masukan yang sama (AC-D02.9).
 *
 * - Sah ⇔ ada lokasi berlaku dengan jarak haversine **≤ radius** (inklusif) — **salah satu** lokasi cukup [V2]
 *   (legacy klien memakai lokasi terakhir, P5-03).
 * - Lokasi pengesah = lokasi sah dengan jarak terkecil; seri → `id_lokasi_presensi` terkecil.
 * - Lokasi terdekat (untuk pesan "di luar radius") = jarak terkecil di antara semua lokasi; seri → id terkecil.
 * - Akurasi GPS tidak ikut menilai (F5-10).
 *
 * Daftar lokasi kosong = pegawai tidak tercakup aturan; penentuannya milik pemanggil (resolver), di sini hasilnya
 * "tidak sah, tanpa lokasi terdekat".
 */
final class PenilaianGeofence
{
    private function __construct()
    {
    }

    /**
     * @param list<LokasiGeofence> $lokasi
     */
    public static function nilai(Koordinat $posisi, array $lokasi): HasilGeofence
    {
        $pengesah = null;
        $jarakSah = null;
        $terdekat = null;
        $jarakMin = null;

        foreach ($lokasi as $l) {
            $jarak = Haversine::antara($posisi, $l->titik);

            if ($terdekat === null || self::lebihDulu($jarak, $l->id, $jarakMin, $terdekat->id)) {
                $terdekat = $l;
                $jarakMin = $jarak;
            }

            if ($jarak <= $l->radius && ($pengesah === null || self::lebihDulu($jarak, $l->id, $jarakSah, $pengesah->id))) {
                $pengesah = $l;
                $jarakSah = $jarak;
            }
        }

        return new HasilGeofence($pengesah, $jarakSah, $terdekat, $jarakMin);
    }

    private static function lebihDulu(float $jarak, int $id, ?float $jarakLama, int $idLama): bool
    {
        return $jarakLama === null || $jarak < $jarakLama || ($jarak === $jarakLama && $id < $idLama);
    }
}
