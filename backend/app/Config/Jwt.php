<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Konfigurasi JWT (ADR-003, ADR-004).
 * Nilai rahasia diisi lewat .env (jwt.secret), JANGAN hardcode di sini.
 */
class Jwt extends BaseConfig
{
    /**
     * Secret HS256. WAJIB diisi lewat .env, minimal 32 karakter.
     */
    public string $secret = '';

    public string $algorithm = 'HS256';

    public string $issuer = 'simpeg-v2';

    /**
     * Masa berlaku access token dalam detik (default 1 jam).
     */
    public int $accessTtl = 3600;

    /**
     * Masa berlaku refresh token dalam detik (default 7 hari).
     */
    public int $refreshTtl = 604800;

    /**
     * Toleransi clock skew (detik) saat verifikasi.
     */
    public int $leeway = 0;

    /**
     * Nama cookie httpOnly.
     */
    public string $accessCookie = 'access_token';

    public string $refreshCookie = 'refresh_token';

    /**
     * Path cookie. Refresh cookie sengaja dibatasi ke path endpoint auth.
     */
    public string $accessCookiePath = '/';

    public string $refreshCookiePath = '/api/v1/auth';

    public string $cookieDomain = '';

    public bool $cookieSecure = false;

    /**
     * Lax | Strict | None.
     */
    public string $cookieSameSite = 'Lax';
}
