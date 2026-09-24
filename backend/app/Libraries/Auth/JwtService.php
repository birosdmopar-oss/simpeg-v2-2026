<?php

declare(strict_types=1);

namespace App\Libraries\Auth;

use App\Exceptions\AuthException;
use App\Models\Auth\TokenModel;
use CodeIgniter\Cookie\Cookie;
use Config\Jwt as JwtConfig;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use InvalidArgumentException;
use Throwable;

/**
 * JWT Auth Skeleton (F0-06) — ADR-003/ADR-004.
 *
 * - Access token  : JWT HS256, TTL 1 jam, dikirim sebagai cookie httpOnly.
 * - Refresh token : string acak 256-bit, TTL 7 hari, cookie httpOnly; di DB hanya disimpan hash SHA-256.
 * - Refresh bersifat rotating: token lama langsung di-revoke saat dipakai; pemakaian ulang ditolak.
 *
 * Claims wajib: 'sub' (nip) dan 'role'. Claims opsional: 'id_unit', 'id_satker'.
 * Catatan: claims id_unit/id_satker disalin dari row refresh token saat refresh, sehingga bisa stale
 * selama window 7 hari kalau pegawai dimutasi (lihat catatan 00-INDEX.md).
 */
class JwtService
{
    private JwtConfig $config;

    private TokenModel $tokens;

    /**
     * Override waktu "sekarang" (epoch) — hanya untuk test.
     */
    private ?int $now = null;

    public function __construct(?JwtConfig $config = null, ?TokenModel $tokens = null)
    {
        $this->config = $config ?? config(JwtConfig::class);
        $this->tokens = $tokens ?? new TokenModel();

        if (strlen($this->config->secret) < 32) {
            throw new InvalidArgumentException('jwt.secret wajib diisi minimal 32 karakter (lihat .env.example).');
        }
    }

    public function setNow(?int $now): void
    {
        $this->now = $now;
    }

    public function now(): int
    {
        return $this->now ?? time();
    }

    // ------------------------------------------------------------------
    // Access token
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $claims Wajib berisi 'sub' (nip) dan 'role'.
     */
    public function issueAccessToken(array $claims): string
    {
        $this->assertClaims($claims);

        $now     = $this->now();
        $payload = [
            'iss'       => $this->config->issuer,
            'iat'       => $now,
            'nbf'       => $now,
            'exp'       => $now + $this->config->accessTtl,
            'jti'       => bin2hex(random_bytes(8)),
            'sub'       => (string) $claims['sub'],
            'role'      => (int) $claims['role'],
            'id_unit'   => $claims['id_unit'] ?? null,
            'id_satker' => $claims['id_satker'] ?? null,
        ];

        return JWT::encode($payload, $this->config->secret, $this->config->algorithm);
    }

    /**
     * @return array<string, mixed> claims
     *
     * @throws AuthException
     */
    public function verifyAccessToken(string $jwt): array
    {
        JWT::$leeway    = $this->config->leeway;
        JWT::$timestamp = $this->now;

        try {
            $decoded = JWT::decode($jwt, new Key($this->config->secret, $this->config->algorithm));
        } catch (ExpiredException) {
            throw AuthException::expiredToken();
        } catch (Throwable) {
            throw AuthException::invalidToken();
        } finally {
            JWT::$timestamp = null;
        }

        /** @var array<string, mixed> $claims */
        $claims = json_decode(json_encode($decoded, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);

        if (($claims['iss'] ?? null) !== $this->config->issuer || ! isset($claims['sub'], $claims['role'])) {
            throw AuthException::invalidToken();
        }

        return $claims;
    }

    // ------------------------------------------------------------------
    // Refresh token
    // ------------------------------------------------------------------

    /**
     * Terbitkan refresh token baru. Plaintext dikembalikan SEKALI ke pemanggil (untuk cookie);
     * DB hanya menyimpan hash-nya.
     *
     * @param array<string, mixed> $claims
     *
     * @return array{token: string, expires_at: int}
     */
    public function issueRefreshToken(array $claims): array
    {
        $this->assertClaims($claims);

        $plain     = bin2hex(random_bytes(32));
        $expiresAt = $this->now() + $this->config->refreshTtl;

        $this->tokens->insert([
            'nip'         => (string) $claims['sub'],
            'token_hash'  => self::hash($plain),
            'claims_json' => json_encode([
                'sub'       => (string) $claims['sub'],
                'role'      => (int) $claims['role'],
                'id_unit'   => $claims['id_unit'] ?? null,
                'id_satker' => $claims['id_satker'] ?? null,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'expires_at' => date('Y-m-d H:i:s', $expiresAt),
            'revoked'    => 0,
            'created_at' => date('Y-m-d H:i:s', $this->now()),
        ]);

        return ['token' => $plain, 'expires_at' => $expiresAt];
    }

    /**
     * Terbitkan pasangan access + refresh token (dipakai saat login di Fase 1).
     *
     * @param array<string, mixed> $claims
     *
     * @return array{access_token: string, refresh_token: string, access_expires_at: int, refresh_expires_at: int}
     */
    public function issueTokenPair(array $claims): array
    {
        $access  = $this->issueAccessToken($claims);
        $refresh = $this->issueRefreshToken($claims);

        return [
            'access_token'       => $access,
            'refresh_token'      => $refresh['token'],
            'access_expires_at'  => $this->now() + $this->config->accessTtl,
            'refresh_expires_at' => $refresh['expires_at'],
        ];
    }

    /**
     * Tukar refresh token dengan pasangan token baru (rotation).
     * Token lama di-revoke. Token yang sudah revoked/expired/tidak dikenal → AuthException.
     *
     * @return array{access_token: string, refresh_token: string, access_expires_at: int, refresh_expires_at: int}
     *
     * @throws AuthException
     */
    public function refresh(string $refreshToken): array
    {
        $row = $this->tokens->findByHash(self::hash($refreshToken));

        if ($row === null) {
            throw AuthException::unknownToken();
        }

        if ((int) $row['revoked'] === 1) {
            $this->handleReuse((string) $row['nip']);
        }

        if (strtotime((string) $row['expires_at']) <= $this->now()) {
            $this->tokens->revoke((int) $row['id'], $this->now());

            throw AuthException::expiredToken();
        }

        // Invalidasi token lama SEBELUM menerbitkan yang baru (single-use). Cek di atas hanya jalur cepat:
        // UPDATE bersyarat revoked=0 yang menentukan pemenang. Dua request paralel dengan token yang sama
        // sama-sama lolos cek di atas, tetapi hanya satu yang mendapat affected rows = 1; yang kalah
        // diperlakukan sebagai reuse (DEV-002 Bagian 8 #1).
        if (! $this->tokens->revoke((int) $row['id'], $this->now())) {
            $this->handleReuse((string) $row['nip']);
        }

        /** @var array<string, mixed> $claims */
        $claims = json_decode((string) $row['claims_json'], true, 512, JSON_THROW_ON_ERROR);

        return $this->issueTokenPair($claims);
    }

    /**
     * Logout: hapus row refresh token dari DB (A-05). Replay token setelahnya → unknownToken (401).
     */
    public function deleteRefreshToken(string $refreshToken): bool
    {
        return $this->tokens->deleteByHash(self::hash($refreshToken));
    }

    /**
     * Cabut seluruh refresh token milik satu NIP (force logout semua perangkat).
     */
    public function revokeAllForNip(string $nip): int
    {
        return $this->tokens->revokeAllForNip($nip, $this->now());
    }

    // ------------------------------------------------------------------
    // Cookie helpers (httpOnly — ADR-004)
    // ------------------------------------------------------------------

    public function accessCookie(string $accessToken): Cookie
    {
        return $this->makeCookie(
            $this->config->accessCookie,
            $accessToken,
            $this->config->accessTtl,
            $this->config->accessCookiePath,
        );
    }

    public function refreshCookie(string $refreshToken): Cookie
    {
        return $this->makeCookie(
            $this->config->refreshCookie,
            $refreshToken,
            $this->config->refreshTtl,
            $this->config->refreshCookiePath,
        );
    }

    /**
     * Cookie kedaluwarsa untuk menghapus token di browser (logout).
     *
     * @return list<Cookie>
     */
    public function expiredCookies(): array
    {
        return [
            $this->makeCookie($this->config->accessCookie, '', -3600, $this->config->accessCookiePath),
            $this->makeCookie($this->config->refreshCookie, '', -3600, $this->config->refreshCookiePath),
        ];
    }

    public static function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }

    private function makeCookie(string $name, string $value, int $ttl, string $path): Cookie
    {
        return new Cookie($name, $value, [
            'expires'  => $ttl > 0 ? $this->now() + $ttl : 1,
            'path'     => $path,
            'domain'   => $this->config->cookieDomain,
            'secure'   => $this->config->cookieSecure,
            'httponly' => true,
            'samesite' => $this->config->cookieSameSite,
            'raw'      => false,
        ]);
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function assertClaims(array $claims): void
    {
        if (! isset($claims['sub']) || (string) $claims['sub'] === '' || ! isset($claims['role'])) {
            throw new InvalidArgumentException("Claims wajib berisi 'sub' (nip) dan 'role'.");
        }
    }

    /**
     * Reuse detection (A-05): token yang sudah dipakai/dicabut dipakai lagi → indikasi pencurian token.
     * Seluruh sesi (refresh token aktif) milik nip tersebut ikut dicabut.
     *
     * @throws AuthException selalu (reusedToken → 401)
     */
    private function handleReuse(string $nip): never
    {
        $this->tokens->revokeAllForNip($nip, $this->now());

        throw AuthException::reusedToken();
    }
}
