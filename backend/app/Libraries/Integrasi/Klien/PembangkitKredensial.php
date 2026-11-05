<?php

declare(strict_types=1);

namespace App\Libraries\Integrasi\Klien;

use InvalidArgumentException;

/**
 * MAKE-058 — pembangkit rahasia klien dan token SIMAPI (BP-8, PRD Fase 6 §2.2): `random_bytes`/`random_int` saja (legacy
 * `mt_rand`), rahasia di-hash `password_hash` (Argon2id; bcrypt bila tidak tersedia), token disimpan sebagai SHA-256 heks
 * huruf kecil. Nilai mentah tidak pernah disimpan atau dicatat; pemanggil menampilkannya sekali.
 */
final class PembangkitKredensial
{
    private const ALFANUMERIK = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';

    private function __construct()
    {
    }

    /**
     * Rahasia klien acak (`[A-Za-z0-9_-]`, entropi ≈ 6 bit per karakter).
     */
    public static function rahasia(int $panjang): string
    {
        if ($panjang < 32) {
            throw new InvalidArgumentException('Rahasia klien minimal 32 karakter.');
        }

        return substr(rtrim(strtr(base64_encode(random_bytes(intdiv($panjang * 3, 4) + 3)), '+/', '-_'), '='), 0, $panjang);
    }

    /**
     * Token opak SIMAPI (`[A-Za-z0-9]`), tiap karakter dari `random_int` (tanpa bias modulo).
     */
    public static function token(int $panjang): string
    {
        if ($panjang < 20) {
            throw new InvalidArgumentException('Token minimal 20 karakter.');
        }

        $token = '';

        for ($i = 0; $i < $panjang; $i++) {
            $token .= self::ALFANUMERIK[random_int(0, strlen(self::ALFANUMERIK) - 1)];
        }

        return $token;
    }

    public static function hashRahasia(#[\SensitiveParameter] string $rahasia): string
    {
        return password_hash($rahasia, defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT);
    }

    /**
     * SHA-256 heks huruf kecil dari token mentah (kunci pencarian `simapi_token.token_hash`).
     */
    public static function hashToken(#[\SensitiveParameter] string $token): string
    {
        return hash('sha256', $token);
    }
}
