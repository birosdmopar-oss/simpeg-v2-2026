<?php

declare(strict_types=1);

namespace App\Libraries\Auth;

use App\Models\Auth\LoginAttemptModel;
use Config\Auth as AuthConfig;

/**
 * Login attempts lockout (A-04).
 *
 * N = Config\Auth::$lockoutMaxAttempts (default 5, dari MTC-001). Akun terkunci kalau ada N kegagalan
 * berturut-turut (tanpa sukses di antaranya) dalam jendela lockoutWindowMinutes, dan kegagalan terakhir
 * masih dalam lockoutDurationMinutes. Sukses login mereset hitungan (record success).
 */
class LockoutService
{
    private ?int $now = null;

    public function __construct(
        private LoginAttemptModel $attempts,
        private ?AuthConfig $config = null,
    ) {
        $this->config ??= config(AuthConfig::class);
    }

    /**
     * Override waktu (test).
     */
    public function setNow(?int $now): void
    {
        $this->now = $now;
    }

    public function maxAttempts(): int
    {
        return $this->config->lockoutMaxAttempts;
    }

    /**
     * Sisa detik terkunci; 0 kalau tidak terkunci.
     */
    public function lockedForSeconds(string $username): int
    {
        $now  = $this->now ?? time();
        $info = $this->attempts->consecutiveFailures($username, $this->config->lockoutWindowMinutes, $now);

        if ($info['count'] < $this->config->lockoutMaxAttempts || $info['last_failed_at'] === null) {
            return 0;
        }

        $unlockAt = strtotime($info['last_failed_at']) + $this->config->lockoutDurationMinutes * 60;

        return max(0, $unlockAt - $now);
    }

    public function isLocked(string $username): bool
    {
        return $this->lockedForSeconds($username) > 0;
    }

    public function failedCount(string $username): int
    {
        return $this->attempts->consecutiveFailures($username, $this->config->lockoutWindowMinutes, $this->now ?? time())['count'];
    }

    public function recordFailure(string $username, ?string $ip): void
    {
        $this->attempts->record($username, $ip, false, $this->now ?? time());
    }

    /**
     * Sukses login → hitungan gagal berturut-turut kembali 0.
     */
    public function recordSuccess(string $username, ?string $ip): void
    {
        $this->attempts->record($username, $ip, true, $this->now ?? time());
    }
}
