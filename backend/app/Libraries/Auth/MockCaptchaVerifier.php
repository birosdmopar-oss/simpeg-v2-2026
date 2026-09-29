<?php

declare(strict_types=1);

namespace App\Libraries\Auth;

use App\Interfaces\CaptchaVerifierInterface;
use CodeIgniter\Exceptions\ConfigException;

/**
 * Captcha mock untuk lokal/test (auth.captchaDriver = mock). Tanpa network.
 * Token kosong atau bernilai 'invalid' → ditolak; selain itu diterima.
 *
 * Ditolak di production (ConfigException, ISSUE-021): captcha tidak benar-benar diverifikasi, jadi login dan lupa
 * password kehilangan perlindungan A-03.
 */
class MockCaptchaVerifier implements CaptchaVerifierInterface
{
    public const REJECT_TOKEN = 'invalid';

    public function __construct(string $environment = ENVIRONMENT)
    {
        if ($environment === 'production') {
            throw new ConfigException(
                'auth.captchaDriver = mock tidak boleh dipakai di production (captcha tidak diverifikasi).',
            );
        }
    }

    public function verify(string $token, ?string $remoteIp = null): bool
    {
        $token = trim($token);

        return $token !== '' && $token !== self::REJECT_TOKEN;
    }
}
