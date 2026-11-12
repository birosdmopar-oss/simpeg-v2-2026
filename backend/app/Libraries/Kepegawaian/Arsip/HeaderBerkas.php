<?php

declare(strict_types=1);

namespace App\Libraries\Kepegawaian\Arsip;

use Config\Arsip;

/**
 * Header respons berkas Arsip (F10-34 (4), §7.3): satu sumber untuk endpoint privat `buka` dan rute dev
 * `BerkasDevController` (yang meniru snippet Nginx).
 *
 * Content-Type dari daftar tertutup (pemanggil), `inline` hanya untuk pratinjau pdf/jpg/jpeg/png/gif, selain itu
 * `attachment`; `X-Content-Type-Options: nosniff`, `Referrer-Policy: no-referrer`, CSP §7.3, dan Cache-Control dari
 * pemanggil (`private, no-store` untuk `buka`, `no-store` untuk rute dev = Nginx).
 */
final class HeaderBerkas
{
    private function __construct()
    {
    }

    /**
     * @param string|null $nama nama berkas untuk `Content-Disposition` (null = tanpa nama, seperti Nginx)
     *
     * @return array<string, string>
     */
    public static function untuk(string $tipe, bool $inline, ?string $nama, string $cacheControl): array
    {
        $disposisi = $inline ? 'inline' : 'attachment';

        if ($nama !== null) {
            // Byte kontrol & pemisah baris dibuang (header injection); cadangan ASCII + filename* UTF-8 (pola LampiranController).
            $bersih = trim((string) preg_replace('/[\p{C}\p{Zl}\p{Zp}]+/u', ' ', $nama));
            $bersih = $bersih === '' ? 'berkas' : $bersih;
            $ascii  = (string) preg_replace('/[^A-Za-z0-9._-]+/', '_', $bersih);
            $disposisi .= '; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($bersih);
        }

        return [
            'Content-Type'            => $tipe,
            'Content-Disposition'     => $disposisi,
            'X-Content-Type-Options'  => 'nosniff',
            'Cache-Control'           => $cacheControl,
            'Referrer-Policy'         => 'no-referrer',
            'Content-Security-Policy' => Arsip::CSP_BERKAS,
        ];
    }
}
