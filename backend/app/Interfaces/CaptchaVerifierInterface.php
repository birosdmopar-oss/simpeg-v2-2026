<?php

declare(strict_types=1);

namespace App\Interfaces;

/**
 * Kontrak verifikasi captcha (A-03). Implementasi: TurnstileVerifier (Cloudflare), MockCaptchaVerifier (lokal/test).
 */
interface CaptchaVerifierInterface
{
    /**
     * @param string      $token    token dari widget (cf-turnstile-response); string kosong = tidak ada captcha
     * @param string|null $remoteIp IP klien (opsional, ikut dikirim ke siteverify)
     */
    public function verify(string $token, ?string $remoteIp = null): bool;
}
