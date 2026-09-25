<?php

declare(strict_types=1);

namespace App\Libraries\Auth;

use App\Interfaces\ResetTokenNotifierInterface;
use CodeIgniter\Exceptions\ConfigException;

/**
 * Notifier reset password untuk development (auth.resetTokenNotifier = log): tautan reset — BERISI TOKEN — ditulis ke
 * log lokal (writable/logs) supaya alur lupa/reset password bisa diuji end-to-end tanpa SMTP.
 *
 * Ditolak di production (ConfigException): log production tidak boleh memuat token reset, dan tautan yang hanya
 * ditulis ke log tidak pernah sampai ke pengguna.
 */
class LogResetTokenNotifier implements ResetTokenNotifierInterface
{
    public const LOG_PREFIX = '[ResetPassword][log-driver]';

    public function __construct(string $environment = ENVIRONMENT)
    {
        if ($environment === 'production') {
            throw new ConfigException(
                'auth.resetTokenNotifier = log tidak boleh dipakai di production (token reset akan tertulis di log).',
            );
        }
    }

    public function send(array $user, string $token, string $resetLink, string $expiresAt): void
    {
        log_message('info', self::LOG_PREFIX . ' tautan reset untuk username={username} (berlaku s.d. {exp}): {link}', [
            'username' => (string) ($user['username'] ?? ''),
            'exp'      => $expiresAt,
            'link'     => $resetLink,
        ]);
    }
}
