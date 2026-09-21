<?php

declare(strict_types=1);

namespace App\Libraries\Auth;

use App\Interfaces\CaptchaVerifierInterface;
use Closure;
use Config\Auth as AuthConfig;
use Throwable;

/**
 * Cloudflare Turnstile server-side verification (A-03).
 * Secret key dibaca dari Config\Auth::$turnstileSecretKey (.env auth.turnstileSecretKey) — tidak hardcode.
 * Kegagalan jaringan/gateway → dianggap captcha gagal (fail-closed) dan di-log.
 */
class TurnstileVerifier implements CaptchaVerifierInterface
{
    /**
     * @var Closure(string $url, array<string, string> $form, int $timeout): string  HTTP POST → body JSON
     */
    private Closure $httpPost;

    /**
     * @param (Closure(string, array<string, string>, int): string)|null $httpPost override transport (test)
     */
    public function __construct(private AuthConfig $config, ?Closure $httpPost = null)
    {
        $this->httpPost = $httpPost ?? static function (string $url, array $form, int $timeout): string {
            $client = service('curlrequest', ['timeout' => $timeout, 'http_errors' => false]);

            return (string) $client->post($url, ['form_params' => $form])->getBody();
        };
    }

    public function verify(string $token, ?string $remoteIp = null): bool
    {
        if (trim($token) === '') {
            return false;
        }

        if ($this->config->turnstileSecretKey === '') {
            log_message('error', '[Turnstile] auth.turnstileSecretKey kosong — captcha ditolak (fail-closed).');

            return false;
        }

        $form = ['secret' => $this->config->turnstileSecretKey, 'response' => $token];

        if ($remoteIp !== null && $remoteIp !== '') {
            $form['remoteip'] = $remoteIp;
        }

        try {
            $body = ($this->httpPost)($this->config->turnstileVerifyUrl, $form, $this->config->turnstileTimeout);
            $json = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            log_message('error', '[Turnstile] siteverify gagal: {msg}', ['msg' => $e->getMessage()]);

            return false;
        }

        if (! is_array($json) || ($json['success'] ?? false) !== true) {
            log_message('info', '[Turnstile] token ditolak: {codes}', [
                'codes' => implode(',', (array) ($json['error-codes'] ?? [])),
            ]);

            return false;
        }

        return true;
    }
}
