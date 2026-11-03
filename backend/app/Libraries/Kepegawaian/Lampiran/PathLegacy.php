<?php

declare(strict_types=1);

namespace App\Libraries\Kepegawaian\Lampiran;

/**
 * Normalisasi path berkas legacy (MAKE-069 K-M3 opsi A): path di DB tetap seperti legacy, berkas disalin dengan struktur
 * folder relatif yang sama. Mengikuti `fixArsipPath()` legacy (`\` dan `./` → `/`, `/` di awal dibuang) dan menolak
 * semua yang bisa keluar dari akar penyimpanan atau dipakai untuk SSRF saat ditarik lewat URL: segmen `..`, `:` di mana
 * pun (skema/huruf drive), `%` (traversal ter-encode), UTF-8 tidak sah, byte kontrol/tak terlihat, NUL, pemisah baris
 * U+2028/U+2029, NBSP. Spasi biasa di dalam segmen diizinkan (path legacy).
 */
final class PathLegacy
{
    private function __construct()
    {
    }

    /**
     * Path relatif bersih (segmen dipisah `/`), atau null bila tidak sah.
     */
    public static function normalisasi(string $path): ?string
    {
        $p = trim($path);

        // preg_match() = false pada UTF-8 tidak sah → `!== 0` ikut menolaknya (review S-1).
        if ($p === '' || ! mb_check_encoding($p, 'UTF-8') || preg_match('/[\p{C}\p{Zl}\p{Zp}\x{00A0}%:]/u', $p) !== 0) {
            return null;
        }

        $segmen = [];

        foreach (explode('/', str_replace('\\', '/', $p)) as $s) {
            if ($s === '' || $s === '.') {
                continue;
            }

            if ($s === '..' || trim($s) !== $s && trim($s) === '' || str_ends_with($s, '.') && trim($s, '.') === '') {
                return null;
            }

            $segmen[] = $s;
        }

        return $segmen === [] ? null : implode('/', $segmen);
    }

    /**
     * `arsip/…` dilayani server arsip legacy, selain itu server aplikasi (`pathToSrc()`, `function_helper.php:314`).
     */
    public static function sumber(string $normal): string
    {
        return str_starts_with($normal, 'arsip/') ? 'arsip' : 'aplikasi';
    }
}
