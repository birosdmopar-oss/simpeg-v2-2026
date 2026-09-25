<?php

declare(strict_types=1);

namespace Tests\Auth;

use App\Interfaces\ResetTokenNotifierInterface;
use App\Libraries\Auth\LogResetTokenNotifier;
use App\Libraries\Auth\MockResetTokenNotifier;
use App\Libraries\Auth\PasswordService;
use App\Libraries\Auth\PasswordVerifier;
use App\Libraries\Auth\ResetPasswordService;
use App\Models\Auth\ForgotAttemptModel;
use App\Models\Auth\PenggunaModel;
use CodeIgniter\Exceptions\ConfigException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestLogger;
use Config\Auth as AuthConfig;
use Config\Services;
use RuntimeException;
use Tests\Support\AuthTestTrait;
use Tests\Support\Database\Seeds\AuthSeeder;

/**
 * ISSUE-006 — kanal tautan reset password lewat ResetTokenNotifierInterface (driver mock/log, dipilih lewat
 * auth.resetTokenNotifier; keduanya ditolak di production), tautan dari auth.resetLinkBase, dan captcha Turnstile di
 * forgot-password (pola login: dicek sebelum rate limit).
 *
 * @internal
 */
final class ResetTokenNotifierTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use AuthTestTrait;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = null;
    protected $seed      = AuthSeeder::class;

    private const NIP  = '199002152015022002';
    private const BASE = 'https://simpeg.example.go.id/reset-password';

    private AuthConfig $config;
    private MockResetTokenNotifier $notifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clearAuthState();

        $this->config                             = config(AuthConfig::class);
        $this->config->exposeResetTokenInResponse = false; // seperti production: token hanya lewat kanal
        $this->config->forgotMaxPerWindow         = 3;
        $this->config->resetLinkBase              = self::BASE;
        $this->config->resetTokenNotifier         = 'mock';

        $this->notifier = new MockResetTokenNotifier();
        Services::injectMock('resetTokenNotifier', $this->notifier);
    }

    protected function tearDown(): void
    {
        $this->clearAuthState();
        parent::tearDown();
    }

    private function service(?ResetTokenNotifierInterface $notifier = null): ResetPasswordService
    {
        $pengguna = new PenggunaModel($this->db);
        $verifier = new PasswordVerifier($pengguna, $this->config);

        return new ResetPasswordService(
            $pengguna,
            new ForgotAttemptModel($this->db),
            $verifier,
            new PasswordService($pengguna, $verifier, service('jwt')),
            service('jwt'),
            $this->config,
            $this->db,
            notifier: $notifier,
        );
    }

    public function testForgotSendsResetLinkThroughNotifierWithoutExposingToken(): void
    {
        $result = $this->forgotPassword(self::NIP);

        $result->assertStatus(200);
        $data = $this->json($result)['data'];
        $this->assertTrue($data['accepted']);
        $this->assertArrayNotHasKey('token', $data);

        $sent = $this->notifier->sent();
        $this->assertCount(1, $sent);
        $this->assertSame(self::NIP, $sent[0]['nip']);
        $this->assertSame(self::NIP, $sent[0]['username']);
        $this->assertSame(self::BASE . '?token=' . $sent[0]['token'], $sent[0]['reset_link']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $sent[0]['token']);

        // Token yang dikirim = token yang hash-nya tersimpan, dengan masa berlaku yang sama.
        $row = (array) $this->db->table('forgot_attempts')->where('username', self::NIP)->get()->getRowArray();
        $this->assertSame(hash('sha256', $sent[0]['token']), $row['token_hash']);
        $this->assertSame($row['expires_at'], $sent[0]['expires_at']);

        // Log milik service tidak memuat token (driver mock tidak menulis log sama sekali).
        $this->assertFalse(TestLogger::didLog('info', $sent[0]['token'], false), 'Token reset tidak boleh tertulis di log');

        // Tautan benar-benar bisa dipakai reset.
        $this->withBodyFormat('json')->post('api/v1/auth/reset-password', [
            'token' => $sent[0]['token'], 'new_password' => 'PasswordReset789', 'new_password_confirmation' => 'PasswordReset789',
        ])->assertStatus(200);
    }

    public function testUnknownOrInactiveUsernameSendsNothing(): void
    {
        foreach (['000000000000000000', AuthSeeder::NIP_INACTIVE] as $username) {
            $result = $this->forgotPassword($username);
            $result->assertStatus(200);
            $this->assertTrue($this->json($result)['data']['accepted']);
        }

        $this->assertSame([], $this->notifier->sent());
    }

    public function testCaptchaIsCheckedBeforeRateLimit(): void
    {
        foreach (['', 'invalid'] as $captcha) {
            $result = $this->forgotPassword(self::NIP, $captcha);
            $result->assertStatus(422);
            $this->assertSame(['Verifikasi captcha gagal. Silakan ulangi.'], $this->json($result)['errors']['captcha_token']);
        }

        // Percobaan tanpa captcha valid tidak tercatat dan tidak menghabiskan kuota milik username tsb.
        $this->assertSame(0, $this->db->table('forgot_attempts')->countAllResults());
        $this->assertSame([], $this->notifier->sent());

        for ($i = 0; $i < 3; $i++) {
            $this->forgotPassword(self::NIP)->assertStatus(200);
        }

        $this->assertCount(3, $this->notifier->sent());
    }

    public function testDeliveryFailureKeepsGenericResponseAndIsLogged(): void
    {
        $failing = new class () implements ResetTokenNotifierInterface {
            public function send(array $user, string $token, string $resetLink, string $expiresAt): void
            {
                throw new RuntimeException('SMTP tidak terjangkau');
            }
        };

        $result = $this->service($failing)->request(self::NIP, 'ok', null);

        $this->assertTrue($result['accepted']);
        $this->assertSame(1, $this->db->table('forgot_attempts')->where('username', self::NIP)->where('token_hash IS NOT NULL')->countAllResults());
        $this->assertLogContains('error', 'gagal dikirim: SMTP tidak terjangkau');
    }

    public function testLogDriverWritesResetLinkOutsideProduction(): void
    {
        $this->config->resetTokenNotifier = 'log';
        Services::resetSingle('resetTokenNotifier');

        $notifier = service('resetTokenNotifier', false);
        $this->assertInstanceOf(LogResetTokenNotifier::class, $notifier);

        $this->service($notifier)->request(self::NIP, 'ok', null);

        $this->assertLogContains('info', LogResetTokenNotifier::LOG_PREFIX . ' tautan reset untuk username=' . self::NIP);
        $this->assertLogContains('info', self::BASE . '?token=');
    }

    public function testNonDeliveringDriversAreRejectedInProduction(): void
    {
        foreach ([LogResetTokenNotifier::class, MockResetTokenNotifier::class] as $class) {
            try {
                new $class('production');
                $this->fail("{$class} harus ditolak di production");
            } catch (ConfigException $e) {
                $this->assertStringContainsString('tidak boleh dipakai di production', $e->getMessage());
            }

            $this->assertInstanceOf(ResetTokenNotifierInterface::class, new $class('development'));
        }
    }

    public function testServicesSelectDriverFromConfig(): void
    {
        $this->config->resetTokenNotifier = 'mock';
        $this->assertInstanceOf(MockResetTokenNotifier::class, service('resetTokenNotifier', false));

        $this->config->resetTokenNotifier = 'log';
        $this->assertInstanceOf(LogResetTokenNotifier::class, service('resetTokenNotifier', false));

        $this->config->resetTokenNotifier = 'email';
        $this->expectException(ConfigException::class);
        service('resetTokenNotifier', false);
    }

    /**
     * Salah konfigurasi kanal gagal SAMA untuk username terdaftar maupun tidak (tidak membocorkan username), dan tidak
     * menulis apa pun ke forgot_attempts.
     */
    public function testInvalidResetLinkBaseFailsForEveryUsername(): void
    {
        foreach (['', 'reset-password', 'https://simpeg.example.go.id/reset?x=1', 'https://simpeg.example.go.id/#/reset'] as $base) {
            $this->config->resetLinkBase = $base;

            foreach ([self::NIP, '000000000000000000'] as $username) {
                try {
                    $this->service($this->notifier)->request($username, 'ok', null);
                    $this->fail("resetLinkBase '{$base}' harus ditolak");
                } catch (ConfigException $e) {
                    $this->assertStringContainsString('auth.resetLinkBase', $e->getMessage());
                }
            }
        }

        $this->assertSame(0, $this->db->table('forgot_attempts')->countAllResults());
        $this->assertSame([], $this->notifier->sent());
    }
}
