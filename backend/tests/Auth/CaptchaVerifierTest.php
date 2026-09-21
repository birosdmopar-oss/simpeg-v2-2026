<?php

declare(strict_types=1);

namespace Tests\Auth;

use App\Libraries\Auth\MockCaptchaVerifier;
use App\Libraries\Auth\TurnstileVerifier;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Auth as AuthConfig;

/**
 * A-03 — TurnstileVerifier (transport di-inject, tanpa network) & MockCaptchaVerifier.
 *
 * @internal
 */
final class CaptchaVerifierTest extends CIUnitTestCase
{
    private function config(string $secret = 'test-secret'): AuthConfig
    {
        $config                     = new AuthConfig();
        $config->turnstileSecretKey = $secret;
        $config->turnstileVerifyUrl = 'https://challenges.example/siteverify';

        return $config;
    }

    public function testEmptyTokenIsRejectedWithoutCallingCloudflare(): void
    {
        $called   = false;
        $verifier = new TurnstileVerifier($this->config(), static function () use (&$called): string {
            $called = true;

            return '{"success":true}';
        });

        $this->assertFalse($verifier->verify(''));
        $this->assertFalse($verifier->verify('   '));
        $this->assertFalse($called);
    }

    public function testMissingSecretKeyFailsClosed(): void
    {
        $verifier = new TurnstileVerifier($this->config(''), static fn (): string => '{"success":true}');

        $this->assertFalse($verifier->verify('token'));
        $this->assertLogContains('error', 'turnstileSecretKey kosong');
    }

    public function testSecretKeyAndTokenAreSentToSiteverify(): void
    {
        $captured = [];
        $verifier = new TurnstileVerifier($this->config('sk-abc'), static function (string $url, array $form, int $timeout) use (&$captured): string {
            $captured = ['url' => $url, 'form' => $form, 'timeout' => $timeout];

            return '{"success":true,"challenge_ts":"2026-09-17T00:00:00Z","hostname":"localhost"}';
        });

        $this->assertTrue($verifier->verify('cf-token', '10.0.0.5'));
        $this->assertSame('https://challenges.example/siteverify', $captured['url']);
        $this->assertSame(['secret' => 'sk-abc', 'response' => 'cf-token', 'remoteip' => '10.0.0.5'], $captured['form']);
        $this->assertSame(5, $captured['timeout']);
    }

    public function testCloudflareRejectionReturnsFalse(): void
    {
        $verifier = new TurnstileVerifier($this->config(), static fn (): string => '{"success":false,"error-codes":["invalid-input-response"]}');

        $this->assertFalse($verifier->verify('bad-token'));
    }

    public function testNetworkFailureFailsClosed(): void
    {
        $verifier = new TurnstileVerifier($this->config(), static function (): string {
            throw new \RuntimeException('timeout');
        });

        $this->assertFalse($verifier->verify('token'));
        $this->assertLogContains('error', 'siteverify gagal');
    }

    public function testMalformedResponseFailsClosed(): void
    {
        $verifier = new TurnstileVerifier($this->config(), static fn (): string => 'not json');

        $this->assertFalse($verifier->verify('token'));
    }

    public function testMockVerifier(): void
    {
        $mock = new MockCaptchaVerifier();

        $this->assertTrue($mock->verify('anything'));
        $this->assertFalse($mock->verify(''));
        $this->assertFalse($mock->verify(MockCaptchaVerifier::REJECT_TOKEN));
    }

    public function testServiceResolvesDriverFromConfig(): void
    {
        $config                = config(AuthConfig::class);
        $original              = $config->captchaDriver;
        $config->captchaDriver = 'mock';
        $this->assertInstanceOf(MockCaptchaVerifier::class, service('captchaVerifier', false));

        $config->captchaDriver = 'turnstile';
        $this->assertInstanceOf(TurnstileVerifier::class, service('captchaVerifier', false));
        $config->captchaDriver = $original;
    }
}
