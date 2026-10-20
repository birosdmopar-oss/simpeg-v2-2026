<?php

declare(strict_types=1);

namespace App\Libraries\Presensi\Geo;

/**
 * Titik lintang/bujur WGS-84 dalam derajat (PRD 5A §7.5.4, D-02). Murni: tanpa DB, jam, atau I/O.
 *
 * Masukan sah: angka JSON (int/float berhingga) atau string desimal (`-6.1754`, `106.8272`, tanpa eksponen/spasi);
 * lintang −90..90, bujur −180..180 (inklusif). Nilai disimpan apa adanya; pembulatan 7 desimal (kolom
 * `dh_online.lat_*`/`lng_*` DECIMAL(10,7)) lewat {@see self::latBulat()}/{@see self::lngBulat()}.
 */
final readonly class Koordinat
{
    public const DESIMAL_SIMPAN = 7;

    public const AKURASI_MAKS = 100000.0;

    private const POLA_DESIMAL = '/^-?\d{1,3}(\.\d{1,15})?$/';

    private function __construct(public float $lat, public float $lng)
    {
    }

    /**
     * null bila salah satu nilai bukan angka sah atau di luar rentang.
     */
    public static function dariInput(mixed $lat, mixed $lng): ?self
    {
        $lat = self::angka($lat);
        $lng = self::angka($lng);

        if ($lat === null || $lng === null || $lat < -90.0 || $lat > 90.0 || $lng < -180.0 || $lng > 180.0) {
            return null;
        }

        return new self($lat, $lng);
    }

    /**
     * Akurasi GPS (meter, `coords.accuracy`) 0..100000; null bila bukan angka sah atau di luar rentang. Pemanggil
     * membedakan "tidak dikirim" (boleh) dari "tidak sah" (422) sebelum memanggil ini.
     */
    public static function akurasiDariInput(mixed $akurasi): ?float
    {
        $nilai = is_string($akurasi) && preg_match('/^\d{1,6}(\.\d{1,15})?$/', $akurasi) === 1
            ? (float) $akurasi
            : ((is_int($akurasi) || is_float($akurasi)) && is_finite((float) $akurasi) ? (float) $akurasi : null);

        return $nilai === null || $nilai < 0.0 || $nilai > self::AKURASI_MAKS ? null : $nilai;
    }

    public function latBulat(): string
    {
        return number_format(round($this->lat, self::DESIMAL_SIMPAN), self::DESIMAL_SIMPAN, '.', '');
    }

    public function lngBulat(): string
    {
        return number_format(round($this->lng, self::DESIMAL_SIMPAN), self::DESIMAL_SIMPAN, '.', '');
    }

    private static function angka(mixed $nilai): ?float
    {
        if (is_int($nilai)) {
            return (float) $nilai;
        }

        if (is_float($nilai)) {
            return is_finite($nilai) ? $nilai : null;
        }

        if (is_string($nilai) && preg_match(self::POLA_DESIMAL, $nilai) === 1) {
            return (float) $nilai;
        }

        return null;
    }
}
