<?php

declare(strict_types=1);

namespace App\Libraries\Presensi\SlipGaji;

/**
 * CR-027 — utilitas nilai mentah sel/masukan yang dipakai bersama parser Slip Gaji: perapian tepi (spasi ASCII dan NBSP),
 * deteksi sel kosong, dan uraian nilai untuk pesan galat.
 */
final class NilaiSel
{
    /**
     * Karakter yang dibuang di tepi: karakter bawaan `trim` PHP ditambah NBSP (U+00A0). Legacy memakai `trim` biasa,
     * sehingga sel ber-NBSP di tepi gagal sebagai "bukan angka" (N-4b, PERBAIKI).
     */
    private const POLA_TEPI = '/^[ \t\n\r\0\x0B\x{00A0}]+|[ \t\n\r\0\x0B\x{00A0}]+$/u';

    /**
     * Panjang maksimal nilai mentah yang dikutip di pesan galat (karakter).
     */
    private const PANJANG_KUTIPAN = 60;

    private function __construct()
    {
    }

    /**
     * Buang spasi ASCII dan NBSP di kedua tepi. Teks yang bukan UTF-8 valid dirapikan dengan `trim` biasa.
     */
    public static function rapikan(string $teks): string
    {
        return preg_replace(self::POLA_TEPI, '', $teks) ?? trim($teks);
    }

    /**
     * Sel kosong: `null`, atau teks yang kosong setelah {@see self::rapikan()}.
     */
    public static function kosong(mixed $nilai): bool
    {
        return $nilai === null || (is_string($nilai) && self::rapikan($nilai) === '');
    }

    /**
     * Uraian nilai mentah untuk pesan galat (`'abc'`, `12.5`, `TRUE`, `kosong`); teks panjang dipotong per karakter.
     */
    public static function uraikan(mixed $nilai): string
    {
        return match (true) {
            $nilai === null   => 'kosong',
            is_bool($nilai)   => $nilai ? 'TRUE' : 'FALSE',
            is_int($nilai)    => (string) $nilai,
            is_float($nilai)  => is_nan($nilai) ? 'NAN' : (is_infinite($nilai) ? ($nilai > 0 ? 'INF' : '-INF') : (string) $nilai),
            is_string($nilai) => "'" . self::potong($nilai) . "'",
            is_array($nilai)  => 'array',
            default           => 'objek',
        };
    }

    private static function potong(string $teks): string
    {
        if (preg_match('/^.{0,' . self::PANJANG_KUTIPAN . '}/us', $teks, $m) !== 1) {
            // Bukan UTF-8 valid: jangan kutip byte mentah ke pesan/JSON.
            return '(teks tidak terbaca)';
        }

        return $m[0] === $teks ? $teks : $m[0] . '…';
    }
}
