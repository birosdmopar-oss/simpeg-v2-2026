<?php

declare(strict_types=1);

namespace App\Libraries\Dashboard\Konten;

/**
 * Nama berkas aman untuk header `Content-Disposition` unduhan konten Fase 7 (popup/banner, lampiran berita, DIBO).
 * Nama unduhan HANYA dibentuk dari id angka dan ekstensi; ekstensi diambil dari kunci penyimpanan (yang dibuat aplikasi:
 * `<jenis>/<hex32>.<ext>` dengan ekstensi dari daftar putih). Penjaga berlapis bila kunci di DB sampai rusak/diubah: ekstensi
 * wajib `[a-z0-9]{1,5}`, selain itu `bin` — sehingga CR/LF, kutip, titik-koma, garis miring, dan spasi tidak pernah masuk header.
 */
final class NamaBerkas
{
    private function __construct()
    {
    }

    public static function ekstensi(string $kunci): string
    {
        $ext = strtolower(pathinfo($kunci, PATHINFO_EXTENSION));

        return preg_match('/^[a-z0-9]{1,5}\z/', $ext) === 1 ? $ext : 'bin';
    }
}
