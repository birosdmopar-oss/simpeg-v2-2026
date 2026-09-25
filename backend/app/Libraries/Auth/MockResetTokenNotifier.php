<?php

declare(strict_types=1);

namespace App\Libraries\Auth;

use App\Interfaces\ResetTokenNotifierInterface;
use CodeIgniter\Exceptions\ConfigException;

/**
 * Notifier reset password untuk test (auth.resetTokenNotifier = mock): tautan disimpan di memori, tanpa log dan
 * tanpa network. Ditolak di production karena tautan tidak pernah sampai ke pengguna.
 */
class MockResetTokenNotifier implements ResetTokenNotifierInterface
{
    /**
     * @var list<array{nip: string, username: string, token: string, reset_link: string, expires_at: string}>
     */
    private array $sent = [];

    public function __construct(string $environment = ENVIRONMENT)
    {
        if ($environment === 'production') {
            throw new ConfigException('auth.resetTokenNotifier = mock tidak boleh dipakai di production.');
        }
    }

    public function send(array $user, string $token, string $resetLink, string $expiresAt): void
    {
        $this->sent[] = [
            'nip'        => (string) ($user['nip'] ?? ''),
            'username'   => (string) ($user['username'] ?? ''),
            'token'      => $token,
            'reset_link' => $resetLink,
            'expires_at' => $expiresAt,
        ];
    }

    /**
     * Seluruh tautan yang "terkirim" — untuk assert di test.
     *
     * @return list<array{nip: string, username: string, token: string, reset_link: string, expires_at: string}>
     */
    public function sent(): array
    {
        return $this->sent;
    }
}
