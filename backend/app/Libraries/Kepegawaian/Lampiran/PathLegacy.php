<?php

declare(strict_types=1);

namespace App\Libraries\Kepegawaian\Lampiran;

/**
 * Normalisasi path berkas legacy (MAKE-069 K-M3 opsi A): path di DB tetap seperti legacy, berkas disalin dengan struktur
 * folder relatif yang sama. Mengikuti `fixArsipPath()` legacy (`\` dan `./` → `/`, `/` di awal dibuang) dan menolak
 * semua yang bisa keluar dari akar penyimpanan atau dipakai untuk SSRF saat ditarik lewat URL: segmen `..`, skema/huruf
 * drive (`x:`), `%` (traversal ter-encode), byte kontrol/tak terlihat, NUL. Spasi di dalam segmen diizinkan (path legacy).
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

        if ($p === '' || preg_match('/[\p{C}%]/u', $p) === 1 || preg_match('#^[A-Za-z][A-Za-z0-9+.\-]*:#', $p) === 1) {
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
