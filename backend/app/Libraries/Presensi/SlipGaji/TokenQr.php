<?php

declare(strict_types=1);

namespace App\Libraries\Presensi\SlipGaji;

use InvalidArgumentException;

/**
 * CR-027 — token QR verifikasi slip: 64 karakter heksadesimal huruf kecil (256 bit) [K]
 * (`controllers/hr/Sl_gaji.php:127`, `bin2hex(random_bytes(32))`), dibuat baru setiap kali slip dibuka (Q-1).
 *
 * Kelas ini tidak membangkitkan angka acak: service memanggil `TokenQr::dariByteAcak(random_bytes(32))`. Endpoint
 * verifikasi publik memeriksa {@see self::valid()} sebelum query; format salah dijawab sama dengan "tidak ditemukan"
 * (Q-3).
 */
final class TokenQr
{
    public const PANJANG = 64;

    public const PANJANG_BYTE = 32;

    private function __construct()
    {
    }

    /**
     * Tepat 64 karakter `[0-9a-f]`; huruf besar, spasi, dan newline di akhir ditolak.
     */
    public static function valid(string $token): bool
    {
        return preg_match('/^[0-9a-f]{' . self::PANJANG . '}\z/', $token) === 1;
    }

    /**
     * Token dari tepat 32 byte acak (dari service); panjang lain → `InvalidArgumentException`.
     */
    public static function dariByteAcak(string $byte): string
    {
        if (strlen($byte) !== self::PANJANG_BYTE) {
            throw new InvalidArgumentException(sprintf('Token QR butuh tepat %d byte acak, diterima %d.', self::PANJANG_BYTE, strlen($byte)));
        }

        return bin2hex($byte);
    }
}
