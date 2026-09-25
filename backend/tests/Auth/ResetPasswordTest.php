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
use Closure;
use CodeIgniter\Database\ConnectionInterface;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Auth as AuthConfig;
use RuntimeException;
use Tests\Support\AuthTestTrait;
use Tests\Support\Database\Seeds\AuthSeeder;

/**
 * A-07 — lupa/reset password (MTC-006): token expired ditolak, token dipakai ulang ditolak, rate limit forgot_attempts.
 * DEV-002 Bagian 8 #2 (race token reset, token lain dibatalkan) dan #4 / ISSUE-005 (actor audit reset).
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

    private function service(?int $now = null, ?ForgotAttemptModel $attempts = null, ?PenggunaModel $pengguna = null): ResetPasswordService
    {
        $pengguna ??= new PenggunaModel($this->db);
        $verifier = new PasswordVerifier($pengguna, $this->config);
        $service  = new ResetPasswordService(
            $pengguna,
            $attempts ?? new ForgotAttemptModel($this->db),
            $verifier,
            new PasswordService($pengguna, $verifier, service('jwt')),
            service('jwt'),
            $this->config,
            $this->db,
        );
        $service->setNow($now);

        return $service;
    }

    public function testForgotThenResetEndToEnd(): void
    {
        $session = $this->issueTokensFor(self::NIP);

        $forgot = $this->forgotPassword(self::NIP);
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
        $token = $this->json($this->forgotPassword(self::NIP))['data']['token'];

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

    /**
     * DEV-002 Bagian 8 #2 — race: satu token reset dipakai 2x bersamaan. Interleaving dibuat deterministik:
     * request A sudah membaca row (used_at NULL) ketika request B menyelesaikan reset. A harus ditolak dan tidak
     * boleh menimpa password hasil B. B memakai detik berikutnya (now + 1): tanpa syarat `used_at IS NULL`, UPDATE
     * milik A akan mengubah used_at (affected rows 1) sehingga A ikut "menang" dan test ini gagal.
     */
    public function testConcurrentResetWithSameTokenOnlyOneSucceeds(): void
    {
        $base  = time();
        $token = (string) $this->service($base)->request(self::NIP, 'ok', null)['token'];

        $racingAttempts = new class ($this->db, fn () => $this->service($base + 1)->reset($token, self::NEW, self::NEW)) extends ForgotAttemptModel {
            /** @var (Closure(): void)|null */
            private ?Closure $beforeReturn;

            public function __construct(ConnectionInterface $db, Closure $beforeReturn)
            {
                parent::__construct($db);
                $this->beforeReturn = $beforeReturn;
            }

            public function findByTokenHash(string $hash): ?array
            {
                $row = parent::findByTokenHash($hash);

                if ($this->beforeReturn !== null) {
                    $competitor         = $this->beforeReturn;
                    $this->beforeReturn = null;
                    $competitor(); // request B menang di antara SELECT dan UPDATE milik request A
                }

                return $row;
            }
        };

        try {
            $this->service($base, $racingAttempts)->reset($token, 'PasswordLain999', 'PasswordLain999');
            $this->fail('Request kedua dengan token reset yang sama harus ditolak');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('sudah pernah dipakai', $e->getErrors()['token'][0]);
        }

        // Hanya reset B yang berlaku; A tidak menimpa password dan hanya ada satu audit update password.
        $row = $this->db->table('pengguna')->where('nip', self::NIP)->get()->getRowArray();
        $this->assertTrue(password_verify(self::NEW, (string) $row['password']));
        $this->assertFalse(password_verify('PasswordLain999', (string) $row['password']));
        $this->assertSame(1, $this->db->table('audit_logs')->where('entity', 'pengguna')->where('event', 'update')->countAllResults());

        // used_at tetap milik B (A tidak menimpa klaim).
        $this->seeInDatabase('forgot_attempts', ['token_hash' => hash('sha256', $token), 'used_at' => date('Y-m-d H:i:s', $base + 1)]);
    }

    /**
     * ForgotAttemptModel::markUsed() — klaim atomik: hanya panggilan pertama yang menang, walau panggilan berikutnya
     * memakai waktu berbeda (UPDATE tanpa `used_at IS NULL` akan tetap affected rows 1).
     */
    public function testMarkUsedClaimsTokenOnlyOnce(): void
    {
        $base  = time();
        $token = (string) $this->service($base)->request(self::NIP, 'ok', null)['token'];
        $model = new ForgotAttemptModel($this->db);
        $id    = (int) $model->findByTokenHash(hash('sha256', $token))['id'];

        $this->assertTrue($model->markUsed($id, $base));
        $this->assertFalse($model->markUsed($id, $base + 1));
        $this->assertFalse($model->markUsed($id, $base + 3600));

        $this->seeInDatabase('forgot_attempts', ['id' => $id, 'used_at' => date('Y-m-d H:i:s', $base)]);
        $this->assertFalse(ForgotAttemptModel::isInvalidated((array) $model->find($id)));

        // Id yang tidak ada: bukan error, hanya tidak ada yang diklaim.
        $this->assertFalse($model->markUsed($id + 1000, $base));
    }

    public function testSuccessfulResetInvalidatesOtherResetTokensOfSameUser(): void
    {
        $base   = time();
        $first  = (string) $this->service($base)->request(self::NIP, 'ok', null)['token'];
        $second = (string) $this->service($base)->request(self::NIP, 'ok', null)['token'];
        $other  = (string) $this->service($base)->request(AuthSeeder::NIP_ARGON, 'ok', null)['token'];

        $this->service($base + 5)->reset($second, self::NEW, self::NEW);

        try {
            $this->service($base + 10)->reset($first, 'PasswordLain999', 'PasswordLain999');
            $this->fail('Token reset lain milik user yang sama harus ikut dibatalkan');
        } catch (ValidationException $e) {
            // Token yang dibatalkan tidak dilaporkan sebagai "sudah pernah dipakai" (tidak pernah dipakai user).
            $this->assertSame('Token reset sudah tidak berlaku lagi.', $e->getErrors()['token'][0]);
        }

        $this->assertSame(0, $this->db->table('forgot_attempts')->where('username', self::NIP)->where('used_at', null)->countAllResults());

        // Data membedakan token yang dipakai (used_at < expires_at) dari yang dibatalkan (expires_at <= used_at).
        $used      = (array) $this->db->table('forgot_attempts')->where('token_hash', hash('sha256', $second))->get()->getRowArray();
        $cancelled = (array) $this->db->table('forgot_attempts')->where('token_hash', hash('sha256', $first))->get()->getRowArray();
        $this->assertFalse(ForgotAttemptModel::isInvalidated($used));
        $this->assertTrue(ForgotAttemptModel::isInvalidated($cancelled));
        $this->assertSame(date('Y-m-d H:i:s', $base + 5), $cancelled['used_at']);
        $this->assertSame(date('Y-m-d H:i:s', $base + 5), $cancelled['expires_at']);
        $this->assertSame(date('Y-m-d H:i:s', $base + $this->config->resetTokenTtl), $used['expires_at']);

        // Token milik user lain tidak tersentuh.
        $this->seeInDatabase('forgot_attempts', ['token_hash' => hash('sha256', $other), 'used_at' => null]);
    }

    public function testFailedResetRollsBackTokenClaim(): void
    {
        $token = (string) $this->service()->request(self::NIP, 'ok', null)['token'];

        $failing = new class ($this->db) extends PenggunaModel {
            public function update($id = null, $row = null): bool
            {
                throw new RuntimeException('simulasi gagal tulis pengguna');
            }
        };

        try {
            $this->service(null, null, $failing)->reset($token, self::NEW, self::NEW);
            $this->fail('Exception dari update pengguna harus diteruskan');
        } catch (RuntimeException $e) {
            $this->assertSame('simulasi gagal tulis pengguna', $e->getMessage());
        }

        // Transaksi di-rollback: token belum terpakai sehingga user bisa mencoba lagi.
        $this->seeInDatabase('forgot_attempts', ['token_hash' => hash('sha256', $token), 'used_at' => null]);
        $this->service()->reset($token, self::NEW, self::NEW);
        $this->assertTrue(password_verify(self::NEW, (string) $this->db->table('pengguna')->where('nip', self::NIP)->get()->getRowArray()['password']));
    }

    public function testResetAuditRecordsAccountOwnerAsActor(): void
    {
        $token = (string) $this->service()->request(self::NIP, 'ok', null)['token'];

        $this->service()->reset($token, self::NEW, self::NEW);

        $logs = $this->db->table('audit_logs')->where('entity', 'pengguna')->where('event', 'update')->get()->getResultArray();
        $this->assertCount(1, $logs);
        $this->assertSame(self::NIP, $logs[0]['nip_actor'], 'Audit reset password harus mencatat pemilik akun sebagai actor (ISSUE-005)');

        // Masking: hash Argon2id baru dan hash MD5 legacy tidak boleh masuk audit_logs, hanya '***'.
        $hash   = (string) $this->db->table('pengguna')->where('nip', self::NIP)->get()->getRowArray()['password'];
        $before = json_decode((string) $logs[0]['before_json'], true);
        $after  = json_decode((string) $logs[0]['after_json'], true);
        $this->assertStringStartsWith('$argon2id$', $hash);
        $this->assertSame('***', $after['password']);
        $this->assertNull($after['password_legacy']);
        $this->assertSame('***', $before['password_legacy']);
        $this->assertStringNotContainsString($hash, (string) $logs[0]['after_json']);
        $this->assertStringNotContainsString(md5(AuthSeeder::PASSWORD), (string) $logs[0]['before_json']);
    }

    public function testExpiredResetTokenIsRejected(): void
    {
        $base   = time();
        $result = $this->service($base)->request(self::NIP, 'ok', '10.0.0.1');
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
        $result2 = $this->service($base)->request(self::NIP, 'ok', '10.0.0.1');
        $this->service($base + $this->config->resetTokenTtl - 1)->reset((string) $result2['token'], self::NEW, self::NEW);
        $this->assertTrue(password_verify(self::NEW, (string) $this->db->table('pengguna')->where('nip', self::NIP)->get()->getRowArray()['password']));
    }

    public function testExpiredResetTokenIsRejectedViaEndpoint(): void
    {
        $token = $this->json($this->forgotPassword(self::NIP))['data']['token'];

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
            $this->forgotPassword(self::NIP)->assertStatus(200);
        }

        $fourth = $this->forgotPassword(self::NIP);
        $fourth->assertStatus(429);

        $this->assertSame(3, $this->db->table('forgot_attempts')->where('username', self::NIP)->countAllResults());

        // Username lain tidak terpengaruh.
        $this->forgotPassword(AuthSeeder::NIP_ARGON)->assertStatus(200);
    }

    public function testRateLimitResetsAfterWindow(): void
    {
        $base = time();

        for ($i = 0; $i < 3; $i++) {
            $this->service($base)->request(self::NIP, 'ok', null);
        }

        try {
            $this->service($base)->request(self::NIP, 'ok', null);
            $this->fail('Harus 429');
        } catch (TooManyRequestsException) {
            $this->addToAssertionCount(1);
        }

        $this->service($base + $this->config->forgotWindowMinutes * 60 + 1)->request(self::NIP, 'ok', null);
        $this->addToAssertionCount(1);
    }

    public function testUnknownUsernameGetsGenericResponseWithoutToken(): void
    {
        $result = $this->forgotPassword('000000000000000000');

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

        $result = $this->forgotPassword(self::NIP);

        $result->assertStatus(200);
        $this->assertArrayNotHasKey('token', $this->json($result)['data']);
        $this->assertSame(1, $this->db->table('forgot_attempts')->where('username', self::NIP)->where('token_hash IS NOT NULL')->countAllResults());
    }
}
