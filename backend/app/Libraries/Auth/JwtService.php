<?php

declare(strict_types=1);

namespace App\Libraries\Auth;

use App\Exceptions\AuthException;
use App\Models\Auth\TokenModel;
use CodeIgniter\Cookie\Cookie;
use CodeIgniter\Database\Exceptions\DatabaseException;
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
     * Token lama di-revoke. Token yang sudah revoked/expired/tidak dikenal → AuthException; token expired sekaligus
     * dihapus dari DB (bukan revoked=1) agar pengiriman ulangnya tidak terbaca reuse.
     *
     * @return array{access_token: string, refresh_token: string, access_expires_at: int, refresh_expires_at: int}
     *
     * @throws AuthException
     * @throws DatabaseException error DB (bukan reuse) — rotasi di-rollback, token lama tetap berlaku
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
            // Dihapus seperti logout, BUKAN revoked=1: token kedaluwarsa yang dikirim lagi (retry klien body, tab
            // paralel, jam klien tertinggal) → unknownToken, bukan reuse yang ikut mencabut sesi baru di perangkat
            // lain (T-01). revoked=1 tetap khusus rotasi dan reuse detection.
            $this->tokens->deleteExpired((int) $row['id'], $this->now());

            throw AuthException::expiredToken();
        }

        /** @var array<string, mixed> $claims */
        $claims = json_decode((string) $row['claims_json'], true, 512, JSON_THROW_ON_ERROR);

        $pair = $this->rotate((int) $row['id'], $claims);

        if ($pair !== null) {
            return $pair;
        }

        // Kalah race (affected rows 0). Transaksi sudah di-rollback, jadi baca ulang melihat data ter-commit terbaru.
        // Baris hilang = token dihapus logout atau pencabutan massal (revokeAllForNip) di antara SELECT dan UPDATE →
        // sama dengan jalur berurutan (unknownToken), bukan reuse. Baris masih ada = sudah dirotasi request lain → reuse.
        if ($this->tokens->find((int) $row['id']) === null) {
            throw AuthException::unknownToken();
        }

        $this->handleReuse((string) $row['nip']);
    }

    /**
     * Logout: hapus row refresh token dari DB (A-05). Replay token setelahnya → unknownToken (401).
     */
    public function deleteRefreshToken(string $refreshToken): bool
    {
        return $this->tokens->deleteByHash(self::hash($refreshToken));
    }

    /**
     * Cabut seluruh sesi milik satu NIP (force logout semua perangkat): ganti/reset password, perubahan atau
     * penghapusan akun oleh admin. Baris token DIHAPUS seperti logout (bukan `revoked=1`), sehingga refresh token lama
     * di perangkat lain → unknownToken (401) tanpa reuse detection — sesi baru setelah login ulang tidak ikut dicabut
     * (T-01). Reuse detection sendiri tetap menandai `revoked=1` (handleReuse()).
     *
     * @return int jumlah baris token yang dihapus
     *
     * @throws DatabaseException penghapusan gagal
     */
    public function revokeAllForNip(string $nip): int
    {
        return $this->tokens->deleteAllForNip($nip);
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
            $this->expiredRefreshCookie(),
        ];
    }

    /**
     * Cookie kedaluwarsa khusus refresh token — dikirim saat refresh ditolak (401) agar browser berhenti mengirim
     * refresh token yang sudah mati (T-01).
     */
    public function expiredRefreshCookie(): Cookie
    {
        return $this->makeCookie($this->config->refreshCookie, '', -3600, $this->config->refreshCookiePath);
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
     * Rotasi atomik (DEV-002 Bagian 8 #1): cabut token lama lalu terbitkan pasangan baru dalam SATU transaksi.
     * Mengembalikan null bila kalah race (affected rows 0); transaksinya sudah di-rollback.
     *
     * Cek revoked di refresh() hanya jalur cepat; UPDATE bersyarat revoked=0 yang menentukan pemenang. Lock baris
     * token lama dari UPDATE itu ditahan sampai token baru ter-commit, sehingga UPDATE request paralel yang kalah
     * menunggu lock, lalu mendapat affected rows 0 dan baru menjalankan reuse detection SETELAH token baru pemenang
     * ada — revokeAllForNip ikut mencabutnya. Tanpa transaksi, pencabutan massal pihak kalah bisa jatuh di antara
     * UPDATE dan INSERT pemenang sehingga sesi pemenang lolos.
     *
     * Query gagal di dalam transaksi CI4 tidak melempar exception (apa pun DBDebug-nya), hanya mengembalikan false
     * dan menandai transStatus, sedangkan transCommit() tidak memeriksa transStatus. Karena itu transStatus dicek
     * eksplisit sebelum commit.
     *
     * @param array<string, mixed> $claims
     *
     * @return array{access_token: string, refresh_token: string, access_expires_at: int, refresh_expires_at: int}|null
     *
     * @throws DatabaseException penulisan/commit gagal; transaksi di-rollback, token lama tetap berlaku
     */
    private function rotate(int $tokenId, array $claims): ?array
    {
        $db    = $this->tokens->connection();
        $depth = $db->transDepth;

        if ($depth === 0) {
            // Status gagal sisa transaksi lain di koneksi bersama tidak boleh menggagalkan rotasi ini.
            $db->resetTransStatus();
        }

        if (! $db->transBegin()) {
            throw new DatabaseException('Gagal memulai transaksi rotasi refresh token.');
        }

        try {
            if (! $this->tokens->revoke($tokenId, $this->now())) {
                $db->transRollback();
                $pair = null;
            } else {
                $pair = $this->issueTokenPair($claims);

                if ($db->transStatus() === false) {
                    throw new DatabaseException('Rotasi refresh token gagal: penulisan token baru gagal.');
                }

                if (! $db->transCommit()) {
                    throw new DatabaseException('Rotasi refresh token gagal di-commit.');
                }
            }
        } catch (Throwable $e) {
            if ($db->transDepth > $depth) {
                $db->transRollback();
            }

            if ($depth === 0) {
                $db->resetTransStatus();
            }

            throw $e;
        }

        return $pair;
    }

    /**
     * Reuse detection (A-05): token yang sudah dirotasi dipakai lagi → indikasi pencurian token.
     * Seluruh sesi (refresh token aktif) milik nip tersebut ikut dicabut dengan `revoked=1` (bukan dihapus), agar
     * token hasil rotasi pihak lain tetap terbaca reuse bila dipakai (kontrak race CR-004).
     *
     * @throws AuthException selalu (reusedToken → 401)
     */
    private function handleReuse(string $nip): never
    {
        $this->tokens->revokeAllForNip($nip, $this->now());

        throw AuthException::reusedToken();
    }
}
