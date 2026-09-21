<?php

declare(strict_types=1);

namespace App\Libraries\Auth;

use App\Interfaces\CaptchaVerifierInterface;

/**
 * Captcha mock untuk lokal/test (auth.captchaDriver = mock). Tanpa network.
 * Token kosong atau bernilai 'invalid' → ditolak; selain itu diterima.
 * JANGAN dipakai di production.
 */
class MockCaptchaVerifier implements CaptchaVerifierInterface
{
    public const REJECT_TOKEN = 'invalid';

    public function verify(string $token, ?string $remoteIp = null): bool
    {
        $token = trim($token);

        return $token !== '' && $token !== self::REJECT_TOKEN;
    }
}
