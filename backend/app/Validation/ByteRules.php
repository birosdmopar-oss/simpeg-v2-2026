<?php

declare(strict_types=1);

namespace App\Validation;

/**
 * Rule validasi berbasis BYTE (terdaftar di Config\Validation::$ruleSets).
 *
 * Kolom legacy TINYTEXT (`remark`, `reason`) dibatasi 255 BYTE, bukan karakter: huruf beraksen/emoji memakan 2-4
 * byte UTF-8, sehingga max_length (mb_strlen) bisa lolos padahal kolom tidak muat. Koneksi dengan strictOn=false
 * memotong nilai tersebut diam-diam, jadi batasnya wajib ditegakkan di aplikasi.
 */
class ByteRules
{
    /**
     * Panjang nilai (strlen, byte) tidak lebih dari $max. null/angka diperlakukan sebagai teks; array/objek ditolak.
     *
     * @param mixed $value
     */
    public function max_byte_length($value, string $max): bool
    {
        if (is_int($value) || is_float($value) || $value === null) {
            $value = (string) $value;
        }

        if (! is_string($value) || ! ctype_digit($max)) {
            return false;
        }

        return strlen($value) <= (int) $max;
    }
}
