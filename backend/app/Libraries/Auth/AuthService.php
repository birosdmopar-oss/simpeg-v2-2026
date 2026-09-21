<?php

declare(strict_types=1);

namespace App\Libraries\Auth;

use App\Exceptions\AuthException;
use App\Exceptions\LockedException;
use App\Exceptions\ValidationException;
use App\Interfaces\CaptchaVerifierInterface;
use App\Models\AuditLogModel;
use App\Models\Auth\PenggunaModel;

/**
 * Orkestrasi login / logout / refresh (A-02, A-03, A-04, A-05, A-10).
 *
 * Urutan login: captcha → lockout → cari akun aktif → verifikasi password (lazy rehash) → token → audit.
 * Kredensial salah, username tidak terdaftar, dan akun nonaktif menghasilkan 401 dengan pesan
 * generik yang sama (tidak membocorkan mana yang salah), dan tetap dihitung sebagai kegagalan lockout.
 */
class AuthService
{
    public const EVENT_LOGIN  = 'login';
    public const EVENT_LOGOUT = 'logout';

    public function __construct(
        private PenggunaModel $pengguna,
        private PasswordVerifier $passwords,
        private LockoutService $lockout,
        private CaptchaVerifierInterface $captcha,
        private JwtService $jwt,
        private AuditLogModel $audit,
    ) {
    }

    /**
     * @return array{user: array<string, mixed>, tokens: array{access_token: string, refresh_token: string, access_expires_at: int, refresh_expires_at: int}, password_path: string}
     */
    public function login(string $username, string $password, string $captchaToken, ?string $ip): array
    {
        // A-03: captcha ditolak SEBELUM kredensial dicek.
        if (! $this->captcha->verify($captchaToken, $ip)) {
            throw ValidationException::forField('captcha_token', 'Verifikasi captcha gagal. Silakan ulangi.');
        }

        // A-04: lockout.
        $locked = $this->lockout->lockedForSeconds($username);

        if ($locked > 0) {
            throw new LockedException(
                sprintf('Akun terkunci sementara karena terlalu banyak percobaan gagal. Coba lagi dalam %d menit.', (int) ceil($locked / 60)),
                $locked,
            );
        }

        $user = $this->pengguna->findByUsername($username);

        if ($user === null) {
            $this->lockout->recordFailure($username, $ip);

            throw AuthException::invalidCredentials(AuthException::REASON_NOT_FOUND);
        }

        if ((string) $user['status'] !== PenggunaModel::STATUS_ACTIVE) {
            $this->lockout->recordFailure($username, $ip);

            throw AuthException::invalidCredentials(AuthException::REASON_INACTIVE);
        }

        $result = $this->passwords->verify($user, $password);

        if (! $result['ok']) {
            $this->lockout->recordFailure($username, $ip);

            throw AuthException::invalidCredentials();
        }

        // Sukses: reset lockout, catat last_login (via Model → audit update), audit event login.
        $this->lockout->recordSuccess($username, $ip);
        $this->pengguna->update((int) $user['id_pengguna'], ['last_login_at' => date('Y-m-d H:i:s')]);

        $claims = self::claimsFor($user);
        $tokens = $this->jwt->issueTokenPair($claims);

        $this->audit->record('pengguna', (string) $user['id_pengguna'], self::EVENT_LOGIN, null, [
            'username'      => $user['username'],
            'user_level'    => (int) $user['user_level'],
            'ip_address'    => $ip,
            'password_path' => $result['path'],
            'rehashed'      => $result['rehashed'],
        ], (string) $user['nip']);

        $fresh = $this->pengguna->find((int) $user['id_pengguna']) ?? $user;

        return ['user' => $fresh, 'tokens' => $tokens, 'password_path' => $result['path']];
    }

    /**
     * Refresh rotating (A-05). Reuse → JwtService mencabut SELURUH sesi nip tsb lalu 401.
     *
     * @return array{access_token: string, refresh_token: string, access_expires_at: int, refresh_expires_at: int}
     */
    public function refresh(string $refreshToken): array
    {
        return $this->jwt->refresh($refreshToken);
    }

    /**
     * Logout: hapus refresh token dari DB (bukan cuma hapus cookie) + audit logout.
     */
    public function logout(?string $refreshToken, ?string $actorNip, ?string $ip): bool
    {
        $deleted = false;

        if ($refreshToken !== null && $refreshToken !== '') {
            $deleted = $this->jwt->deleteRefreshToken($refreshToken);
        }

        if ($actorNip !== null) {
            $user = $this->pengguna->findByNip($actorNip);

            $this->audit->record(
                'pengguna',
                $user !== null ? (string) $user['id_pengguna'] : $actorNip,
                self::EVENT_LOGOUT,
                null,
                ['ip_address' => $ip, 'refresh_token_deleted' => $deleted],
                $actorNip,
            );
        }

        return $deleted;
    }

    /**
     * @param array<string, mixed> $user
     *
     * @return array<string, mixed>
     */
    public static function claimsFor(array $user): array
    {
        return [
            'sub'       => (string) $user['nip'],
            'role'      => (int) $user['user_level'],
            'id_unit'   => $user['id_unit'] ?? null,
            'id_satker' => $user['id_satker'] ?? null,
        ];
    }
}
