<?php

declare(strict_types=1);

namespace Tests\Auth;

use App\Exceptions\ValidationException;
use App\Interfaces\CaptchaVerifierInterface;
use App\Libraries\Auth\MockCaptchaVerifier;
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
use Config\Auth as AuthConfig;
use Config\Email as EmailConfig;
use Tests\Support\AuthTestTrait;
use Tests\Support\Database\Seeds\AuthSeeder;
use Tests\Support\ResetEmailTestTrait;

/**
 * ISSUE-021 (CR-018) — guard konfigurasi production, pola guard notifier log/mock CR-008:
 * - auth.captchaDriver = mock → MockCaptchaVerifier menolak dibangun di production (ConfigException);
 * - auth.exposeResetTokenInResponse = true → forgot-password ditolak di production sesudah captcha, sebelum rate limit
 *   dan lookup username (gagal sama untuk semua username, tanpa baris forgot_attempts); reset-password tidak terpengaruh.
 * - CR-014: auth.resetLinkBase http ditolak di production; guard driver email berjalan sesudah captcha.
 *
 * Konstanta ENVIRONMENT di PHPUnit selalu 'testing' dan tidak bisa didefinisikan ulang, jadi production disimulasikan
 * lewat argumen constructor $environment (default ENVIRONMENT), seperti ResetTokenNotifierTest.
 *
 * @internal
 */
final class ProductionConfigGuardTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use AuthTestTrait;
    use ResetEmailTestTrait;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = null;
    protected $seed      = AuthSeeder::class;

    private const NIP     = '199002152015022002';
    private const UNKNOWN = '000000000000000000';
    private const NEW     = 'PasswordReset789';

    private AuthConfig $config;
    private MockResetTokenNotifier $notifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clearAuthState();

        $this->config                             = config(AuthConfig::class);
        $this->config->exposeResetTokenInResponse = false;
        $this->config->forgotMaxPerWindow         = 3;
        $this->config->resetLinkBase              = 'https://simpeg.example.go.id/reset-password';

        $this->notifier = new MockResetTokenNotifier();
    }

    protected function tearDown(): void
    {
        $this->clearAuthState();
        parent::tearDown();
    }

    /**
     * Captcha dan notifier eksplisit (tidak bergantung .env); $environment menyimulasikan ENVIRONMENT. Argumen
     * posisional mengikuti urutan constructor ResetPasswordService ($environment = parameter terakhir).
     */
    private function service(string $environment): ResetPasswordService
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
            new MockCaptchaVerifier(),
            $this->notifier,
            $environment,
        );
    }

    public function testMockCaptchaIsRejectedInProduction(): void
    {
        try {
            new MockCaptchaVerifier('production');
            $this->fail('MockCaptchaVerifier harus ditolak di production');
        } catch (ConfigException $e) {
            $this->assertStringContainsString('auth.captchaDriver = mock tidak boleh dipakai di production', $e->getMessage());
        }

        foreach (['development', 'testing'] as $environment) {
            $mock = new MockCaptchaVerifier($environment);

            $this->assertInstanceOf(CaptchaVerifierInterface::class, $mock);
            $this->assertTrue($mock->verify('ok'));
            $this->assertFalse($mock->verify(MockCaptchaVerifier::REJECT_TOKEN));
        }
    }

    public function testExposedResetTokenIsRejectedInProductionForEveryUsernameBeforeLookup(): void
    {
        $this->config->exposeResetTokenInResponse = true;
        $outcome                                  = [];

        foreach ([self::NIP, self::UNKNOWN] as $username) {
            try {
                $result             = $this->service('production')->request($username, 'ok', null);
                $outcome[$username] = $result['token'] === null ? 'accepted tanpa token' : 'token bocor di response';
            } catch (ConfigException $e) {
                $this->assertStringContainsString(
                    'auth.exposeResetTokenInResponse = true tidak boleh dipakai di production',
                    $e->getMessage(),
                );
                $outcome[$username] = 'ConfigException';
            }
        }

        // Gagal sama untuk username terdaftar maupun tidak, tanpa jejak di forgot_attempts dan tanpa tautan terkirim.
        $this->assertSame([self::NIP => 'ConfigException', self::UNKNOWN => 'ConfigException'], $outcome);
        $this->assertSame(0, $this->db->table('forgot_attempts')->countAllResults());
        $this->assertSame([], $this->notifier->sent());
    }

    public function testCaptchaIsStillCheckedBeforeProductionGuard(): void
    {
        $this->config->exposeResetTokenInResponse = true;

        try {
            $this->service('production')->request(self::NIP, MockCaptchaVerifier::REJECT_TOKEN, null);
            $this->fail('Captcha invalid harus ditolak lebih dulu');
        } catch (ValidationException $e) {
            $this->assertSame(['Verifikasi captcha gagal. Silakan ulangi.'], $e->getErrors()['captcha_token']);
        }

        $this->assertSame(0, $this->db->table('forgot_attempts')->countAllResults());
    }

    public function testProductionWithoutExposedTokenDeliversThroughChannelOnly(): void
    {
        $result = $this->service('production')->request(self::NIP, 'ok', null);

        $this->assertSame(['accepted' => true, 'token' => null, 'expires_at' => null], $result);
        $this->assertCount(1, $this->notifier->sent());
        $this->assertSame(1, $this->db->table('forgot_attempts')->where('username', self::NIP)->where('token_hash IS NOT NULL')->countAllResults());
    }

    public function testExposedTokenStillReturnedOutsideProduction(): void
    {
        $this->config->exposeResetTokenInResponse = true;

        $result = $this->service('development')->request(self::NIP, 'ok', null);

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $result['token']);
        $this->assertSame($this->notifier->sent()[0]['token'], $result['token']);
    }

    /**
     * CR-014: di production auth.resetLinkBase wajib https (semua driver) — tautan berisi token tidak boleh dibuka
     * lewat http. Gagal sama untuk semua username, sebelum rate limit/lookup; di luar production http tetap boleh.
     */
    public function testHttpResetLinkBaseIsRejectedInProductionForEveryUsername(): void
    {
        $this->config->resetLinkBase = 'http://simpeg.example.go.id/reset-password';
        $outcome                     = [];

        foreach ([self::NIP, self::UNKNOWN] as $username) {
            try {
                $this->service('production')->request($username, 'ok', null);
                $outcome[$username] = 'accepted';
            } catch (ConfigException $e) {
                $this->assertStringContainsString('auth.resetLinkBase wajib https di production', $e->getMessage());
                $outcome[$username] = 'ConfigException';
            }
        }

        $this->assertSame([self::NIP => 'ConfigException', self::UNKNOWN => 'ConfigException'], $outcome);
        $this->assertSame(0, $this->db->table('forgot_attempts')->countAllResults());
        $this->assertSame([], $this->notifier->sent());

        $this->notifier = new MockResetTokenNotifier();
        $this->service('development')->request(self::NIP, 'ok', null);
        $sent = $this->notifier->sent();
        $this->assertCount(1, $sent);
        $this->assertStringStartsWith('http://simpeg.example.go.id/reset-password#token=', $sent[0]['reset_link']);
    }

    /**
     * CR-014: driver email yang salah konfigurasi tetap dicek SESUDAH captcha (captcha invalid → 422 dulu, tanpa baris
     * forgot_attempts), lalu gagal sama untuk semua username sebelum lookup. Notifier di-resolve lewat Services seperti
     * production.
     */
    public function testCaptchaIsCheckedBeforeEmailDriverGuard(): void
    {
        $this->config->resetTokenNotifier = 'email';
        $this->configureEmailDriver();
        config(EmailConfig::class)->SMTPHost = '';

        $pengguna = new PenggunaModel($this->db);
        $verifier = new PasswordVerifier($pengguna, $this->config);
        $service  = new ResetPasswordService(
            $pengguna,
            new ForgotAttemptModel($this->db),
            $verifier,
            new PasswordService($pengguna, $verifier, service('jwt')),
            service('jwt'),
            $this->config,
            $this->db,
            new MockCaptchaVerifier(),
            null,
            'production',
        );

        try {
            $service->request(self::NIP, MockCaptchaVerifier::REJECT_TOKEN, null);
            $this->fail('Captcha invalid harus ditolak lebih dulu');
        } catch (ValidationException $e) {
            $this->assertSame(['Verifikasi captcha gagal. Silakan ulangi.'], $e->getErrors()['captcha_token']);
        }

        foreach ([self::NIP, self::UNKNOWN] as $username) {
            try {
                $service->request($username, 'ok', null);
                $this->fail('Driver email tanpa email.SMTPHost harus ditolak');
            } catch (ConfigException $e) {
                $this->assertStringContainsString('email.SMTPHost', $e->getMessage());
            }
        }

        $this->assertSame(0, $this->db->table('forgot_attempts')->countAllResults());
    }

    public function testResetPasswordIsNotAffectedByExposeFlagInProduction(): void
    {
        $this->service('testing')->request(self::NIP, 'ok', null);
        $token = $this->notifier->sent()[0]['token'];

        $this->config->exposeResetTokenInResponse = true;
        $this->service('production')->reset($token, self::NEW, self::NEW);

        $user = (array) $this->db->table('pengguna')->where('nip', self::NIP)->get()->getRowArray();
        $this->assertTrue(password_verify(self::NEW, (string) $user['password']));

        $row = (array) $this->db->table('forgot_attempts')->where('token_hash', hash('sha256', $token))->get()->getRowArray();
        $this->assertNotNull($row['used_at']);
    }
}
