<?php

declare(strict_types=1);

namespace Tests\Auth;

use App\Exceptions\TooManyRequestsException;
use App\Exceptions\ValidationException;
use App\Libraries\Auth\PasswordService;
use App\Libraries\Auth\PasswordVerifier;
use App\Libraries\Auth\ResetPasswordService;
use App\Models\Auth\ForgotAttemptModel;
use App\Models\Auth\PenggunaModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Auth as AuthConfig;
use Tests\Support\AuthTestTrait;
use Tests\Support\Database\Seeds\AuthSeeder;

/**
 * A-07 — lupa/reset password (MTC-006): token expired ditolak, token dipakai ulang ditolak, rate limit forgot_attempts.
 *
 * @internal
 */
final class ResetPasswordTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use AuthTestTrait;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = null;
    protected $seed      = AuthSeeder::class;

    private const NIP = '199002152015022002';
    private const NEW = 'PasswordReset789';

    private AuthConfig $config;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clearAuthState();

        $this->config                             = config(AuthConfig::class);
        $this->config->exposeResetTokenInResponse = true; // agar token bisa dipakai di test end-to-end
        $this->config->forgotMaxPerWindow         = 3;
    }

    protected function tearDown(): void
    {
        $this->clearAuthState();
        parent::tearDown();
    }

    private function service(?int $now = null): ResetPasswordService
    {
        $pengguna = new PenggunaModel($this->db);
        $verifier = new PasswordVerifier($pengguna, $this->config);
        $service  = new ResetPasswordService(
            $pengguna,
            new ForgotAttemptModel($this->db),
            $verifier,
            new PasswordService($pengguna, $verifier, service('jwt')),
            service('jwt'),
            $this->config,
        );
        $service->setNow($now);

        return $service;
    }

    public function testForgotThenResetEndToEnd(): void
    {
        $session = $this->issueTokensFor(self::NIP);

        $forgot = $this->withBodyFormat('json')->post('api/v1/auth/forgot-password', ['username' => self::NIP]);
        $forgot->assertStatus(200);
        $token = $this->json($forgot)['data']['token'];
        $this->assertNotEmpty($token);

        // DB hanya menyimpan hash.
        $this->seeInDatabase('forgot_attempts', ['username' => self::NIP, 'token_hash' => hash('sha256', $token), 'used_at' => null]);
        $this->dontSeeInDatabase('forgot_attempts', ['token_hash' => $token]);

        $reset = $this->withBodyFormat('json')->post('api/v1/auth/reset-password', [
            'token'                     => $token,
            'new_password'              => self::NEW,
            'new_password_confirmation' => self::NEW,
        ]);
        $reset->assertStatus(200);

        $row = $this->db->table('pengguna')->where('nip', self::NIP)->get()->getRowArray();
        $this->assertTrue(password_verify(self::NEW, (string) $row['password']));
        $this->assertNull($row['password_legacy']);

        // Sesi lama dicabut; login dengan password baru sukses.
        $this->clearAuthState();
        $this->setRefreshCookie($session['refresh_token']);
        $this->post('api/v1/auth/refresh')->assertStatus(401);

        $this->clearAuthState();
        $this->login(self::NIP, self::NEW)->assertStatus(200);
    }

    public function testReusedResetTokenIsRejected(): void
    {
        $token = $this->json($this->withBodyFormat('json')->post('api/v1/auth/forgot-password', ['username' => self::NIP]))['data']['token'];

        $this->withBodyFormat('json')->post('api/v1/auth/reset-password', [
            'token' => $token, 'new_password' => self::NEW, 'new_password_confirmation' => self::NEW,
        ])->assertStatus(200);

        $second = $this->withBodyFormat('json')->post('api/v1/auth/reset-password', [
            'token' => $token, 'new_password' => 'PasswordLain999', 'new_password_confirmation' => 'PasswordLain999',
        ]);

        $second->assertStatus(422);
        $this->assertStringContainsString('sudah pernah dipakai', $this->json($second)['errors']['token'][0]);

        // Password tidak berubah oleh percobaan kedua.
        $this->assertTrue(password_verify(self::NEW, (string) $this->db->table('pengguna')->where('nip', self::NIP)->get()->getRowArray()['password']));
    }

    public function testExpiredResetTokenIsRejected(): void
    {
        $base   = time();
        $result = $this->service($base)->request(self::NIP, '10.0.0.1');
        $token  = (string) $result['token'];

        // Tepat setelah TTL habis.
        $later = $this->service($base + $this->config->resetTokenTtl + 1);

        try {
            $later->reset($token, self::NEW, self::NEW);
            $this->fail('Token expired harus ditolak');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('kedaluwarsa', $e->getErrors()['token'][0]);
        }

        // Sesaat sebelum habis masih valid.
        $result2 = $this->service($base)->request(self::NIP, '10.0.0.1');
        $this->service($base + $this->config->resetTokenTtl - 1)->reset((string) $result2['token'], self::NEW, self::NEW);
        $this->assertTrue(password_verify(self::NEW, (string) $this->db->table('pengguna')->where('nip', self::NIP)->get()->getRowArray()['password']));
    }

    public function testExpiredResetTokenIsRejectedViaEndpoint(): void
    {
        $token = $this->json($this->withBodyFormat('json')->post('api/v1/auth/forgot-password', ['username' => self::NIP]))['data']['token'];

        $this->db->table('forgot_attempts')
            ->where('token_hash', hash('sha256', $token))
            ->update(['expires_at' => date('Y-m-d H:i:s', time() - 1)]);

        $result = $this->withBodyFormat('json')->post('api/v1/auth/reset-password', [
            'token' => $token, 'new_password' => self::NEW, 'new_password_confirmation' => self::NEW,
        ]);

        $result->assertStatus(422);
        $this->assertStringContainsString('kedaluwarsa', $this->json($result)['errors']['token'][0]);

        // Password tidak berubah, token tidak ditandai terpakai.
        $row = $this->db->table('pengguna')->where('nip', self::NIP)->get()->getRowArray();
        $this->assertNull($row['password']);
        $this->assertSame(md5(AuthSeeder::PASSWORD), $row['password_legacy']);
        $this->seeInDatabase('forgot_attempts', ['token_hash' => hash('sha256', $token), 'used_at' => null]);
    }

    public function testInvalidTokenIsRejected(): void
    {
        $result = $this->withBodyFormat('json')->post('api/v1/auth/reset-password', [
            'token' => 'bukan-token', 'new_password' => self::NEW, 'new_password_confirmation' => self::NEW,
        ]);

        $result->assertStatus(422);
        $this->assertArrayHasKey('token', $this->json($result)['errors']);
    }

    public function testRateLimitViaForgotAttempts(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->withBodyFormat('json')->post('api/v1/auth/forgot-password', ['username' => self::NIP])->assertStatus(200);
        }

        $fourth = $this->withBodyFormat('json')->post('api/v1/auth/forgot-password', ['username' => self::NIP]);
        $fourth->assertStatus(429);

        $this->assertSame(3, $this->db->table('forgot_attempts')->where('username', self::NIP)->countAllResults());

        // Username lain tidak terpengaruh.
        $this->withBodyFormat('json')->post('api/v1/auth/forgot-password', ['username' => AuthSeeder::NIP_ARGON])->assertStatus(200);
    }

    public function testRateLimitResetsAfterWindow(): void
    {
        $base = time();

        for ($i = 0; $i < 3; $i++) {
            $this->service($base)->request(self::NIP, null);
        }

        try {
            $this->service($base)->request(self::NIP, null);
            $this->fail('Harus 429');
        } catch (TooManyRequestsException) {
            $this->addToAssertionCount(1);
        }

        $this->service($base + $this->config->forgotWindowMinutes * 60 + 1)->request(self::NIP, null);
        $this->addToAssertionCount(1);
    }

    public function testUnknownUsernameGetsGenericResponseWithoutToken(): void
    {
        $result = $this->withBodyFormat('json')->post('api/v1/auth/forgot-password', ['username' => '000000000000000000']);

        $result->assertStatus(200);
        $json = $this->json($result);
        $this->assertTrue($json['data']['accepted']);
        $this->assertArrayNotHasKey('token', $json['data']);

        // Tetap dicatat untuk rate limit, tanpa token.
        $this->seeInDatabase('forgot_attempts', ['username' => '000000000000000000', 'token_hash' => null]);
    }

    public function testTokenIsNotExposedWhenConfigDisabled(): void
    {
        $this->config->exposeResetTokenInResponse = false;

        $result = $this->withBodyFormat('json')->post('api/v1/auth/forgot-password', ['username' => self::NIP]);

        $result->assertStatus(200);
        $this->assertArrayNotHasKey('token', $this->json($result)['data']);
        $this->assertSame(1, $this->db->table('forgot_attempts')->where('username', self::NIP)->where('token_hash IS NOT NULL')->countAllResults());
    }
}
