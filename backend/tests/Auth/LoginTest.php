<?php

declare(strict_types=1);

namespace Tests\Auth;

use App\Libraries\Auth\MockCaptchaVerifier;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Auth as AuthConfig;
use Tests\Support\AuthTestTrait;
use Tests\Support\Database\Seeds\AuthSeeder;

/**
 * A-02 login, A-02b lazy rehash (MTC-003), A-03 captcha (MTC-001 langkah 2), A-04 lockout (MTC-001 langkah 3), A-10 audit login.
 *
 * @internal
 */
final class LoginTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use AuthTestTrait;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = null;
    protected $seed      = AuthSeeder::class;

    private const NIP = '199002152015022002'; // Siti Nurhaliza, role 2 (MTC-001/003)

    protected function setUp(): void
    {
        parent::setUp();
        $this->clearAuthState();
    }

    protected function tearDown(): void
    {
        $this->clearAuthState();
        parent::tearDown();
    }

    public function testLoginSuccessReturnsAccessTokenAndSetsHttpOnlyCookies(): void
    {
        $result = $this->login(self::NIP, AuthSeeder::PASSWORD);

        $result->assertStatus(200);
        $json = $this->json($result);

        $this->assertSame('success', $json['status']);
        $this->assertSame(self::NIP, $json['data']['user']['nip']);
        $this->assertSame(2, $json['data']['user']['user_level']);
        $this->assertArrayNotHasKey('password', $json['data']['user']);
        $this->assertArrayNotHasKey('password_legacy', $json['data']['user']);
        $this->assertNotEmpty($json['data']['access_token']);

        $response = $result->response();
        $this->assertTrue($response->hasCookie('access_token'));
        $this->assertTrue($response->hasCookie('refresh_token'));
        $this->assertTrue($response->getCookie('access_token')->isHTTPOnly());
        $this->assertTrue($response->getCookie('refresh_token')->isHTTPOnly());

        // Access token yang dikembalikan valid dan claims sesuai akun.
        $claims = service('jwt')->verifyAccessToken($json['data']['access_token']);
        $this->assertSame(self::NIP, $claims['sub']);
        $this->assertSame(2, $claims['role']);
        $this->assertSame('S01', $claims['id_satker']);

        // Refresh token tersimpan sebagai hash.
        $this->seeInDatabase('token', ['nip' => self::NIP, 'token_hash' => hash('sha256', (string) $this->responseCookie($result, 'refresh_token')), 'revoked' => 0]);
        $this->seeInDatabase('login_attempts', ['username' => self::NIP, 'success' => 1]);
    }

    public function testWrongPasswordAndUnknownUserReturnSameGenericMessage(): void
    {
        $wrong   = $this->login(self::NIP, 'salah-password-1');
        $unknown = $this->login('000000000000000000', AuthSeeder::PASSWORD);

        $wrong->assertStatus(401);
        $unknown->assertStatus(401);

        $this->assertSame($this->json($wrong)['message'], $this->json($unknown)['message']);
        $this->assertSame('Username atau password salah.', $this->json($wrong)['message']);
        $this->assertFalse($wrong->response()->hasCookie('access_token'));

        $this->seeInDatabase('login_attempts', ['username' => self::NIP, 'success' => 0]);
        $this->seeInDatabase('login_attempts', ['username' => '000000000000000000', 'success' => 0]);
    }

    public function testInactiveAccountIsRejectedGenerically(): void
    {
        $result = $this->login(AuthSeeder::NIP_INACTIVE, AuthSeeder::PASSWORD);

        $result->assertStatus(401);
        $this->assertSame('Username atau password salah.', $this->json($result)['message']);
    }

    public function testMissingFieldsReturn422(): void
    {
        $result = $this->withBodyFormat('json')->post('api/v1/auth/login', ['username' => self::NIP]);

        $result->assertStatus(422);
        $this->assertArrayHasKey('password', $this->json($result)['errors']);
    }

    // ------------------------------------------------------------------
    // A-03 Captcha
    // ------------------------------------------------------------------

    public function testEmptyCaptchaIsRejectedBeforeCredentialsAreChecked(): void
    {
        $result = $this->login(self::NIP, AuthSeeder::PASSWORD, '');

        $result->assertStatus(422);
        $this->assertArrayHasKey('captcha_token', $this->json($result)['errors']);

        // Kredensial belum dicek: tidak ada login_attempts, tidak ada token.
        $this->assertSame(0, $this->db->table('login_attempts')->countAllResults());
        $this->assertSame(0, $this->db->table('token')->countAllResults());
    }

    public function testInvalidCaptchaIsRejectedEvenWithWrongPasswordNotCounted(): void
    {
        $result = $this->login(self::NIP, 'password-salah', MockCaptchaVerifier::REJECT_TOKEN);

        $result->assertStatus(422);
        $this->assertSame(0, $this->db->table('login_attempts')->countAllResults(), 'Captcha gagal tidak boleh dihitung sebagai percobaan login');
    }

    public function testCaptchaFailureWithCorrectLegacyPasswordDoesNotRehash(): void
    {
        foreach (['', MockCaptchaVerifier::REJECT_TOKEN] as $captcha) {
            $this->clearAuthState();
            $result = $this->login(self::NIP, AuthSeeder::PASSWORD, $captcha);

            $result->assertStatus(422);
            $this->assertArrayHasKey('captcha_token', $this->json($result)['errors']);
        }

        // Password benar tapi captcha gagal: kredensial tidak disentuh sama sekali.
        $row = $this->db->table('pengguna')->where('nip', self::NIP)->get()->getRowArray();
        $this->assertNull($row['password']);
        $this->assertSame(md5(AuthSeeder::PASSWORD), $row['password_legacy']);
        $this->assertSame(0, $this->db->table('token')->countAllResults());
    }

    public function testInvalidCaptchaOnLockedAccountIsRejectedByCaptchaFirst(): void
    {
        $max = config(AuthConfig::class)->lockoutMaxAttempts;

        for ($i = 1; $i <= $max; $i++) {
            $this->login(self::NIP, 'salah-' . $i)->assertStatus(401);
        }

        $attempts = $this->db->table('login_attempts')->countAllResults();

        // Captcha dicek sebelum status lockout → 422, bukan 423; tidak menambah hitungan.
        $this->login(self::NIP, AuthSeeder::PASSWORD, MockCaptchaVerifier::REJECT_TOKEN)->assertStatus(422);
        $this->assertSame($attempts, $this->db->table('login_attempts')->countAllResults());
    }

    // ------------------------------------------------------------------
    // A-04 Lockout (MTC-001 langkah 3)
    // ------------------------------------------------------------------

    public function testFiveConsecutiveFailuresLockAccountAndSixthCorrectAttemptIsRejected(): void
    {
        $max = config(AuthConfig::class)->lockoutMaxAttempts;
        $this->assertSame(5, $max, 'MTC-001: 5x gagal berturut-turut');

        for ($i = 1; $i <= $max; $i++) {
            $this->login(self::NIP, 'salah-' . $i)->assertStatus(401);
        }

        $sixth = $this->login(self::NIP, AuthSeeder::PASSWORD);

        $sixth->assertStatus(423);
        $this->assertStringContainsString('terkunci', $this->json($sixth)['message']);
        $this->assertFalse($sixth->response()->hasCookie('access_token'));
    }

    public function testFourFailuresThenCorrectPasswordStillLogsInAndResetsCounter(): void
    {
        for ($i = 1; $i <= 4; $i++) {
            $this->login(self::NIP, 'salah-' . $i)->assertStatus(401);
        }

        $this->login(self::NIP, AuthSeeder::PASSWORD)->assertStatus(200);

        // Counter reset: 4 gagal lagi setelah sukses masih boleh login.
        for ($i = 1; $i <= 4; $i++) {
            $this->login(self::NIP, 'salah-lagi-' . $i)->assertStatus(401);
        }

        $this->login(self::NIP, AuthSeeder::PASSWORD)->assertStatus(200);
    }

    // ------------------------------------------------------------------
    // A-02b Lazy rehash MD5 -> Argon2id (MTC-003)
    // ------------------------------------------------------------------

    public function testFirstLoginRehashesLegacyMd5ToArgon2idAndClearsLegacy(): void
    {
        // SEBELUM: password NULL, password_legacy MD5.
        $before = $this->db->table('pengguna')->where('nip', self::NIP)->get()->getRowArray();
        $this->assertNull($before['password']);
        $this->assertSame(md5(AuthSeeder::PASSWORD), $before['password_legacy']);

        $this->login(self::NIP, AuthSeeder::PASSWORD)->assertStatus(200);

        // SESUDAH: password Argon2id, legacy kosong.
        $after = $this->db->table('pengguna')->where('nip', self::NIP)->get()->getRowArray();
        $this->assertStringStartsWith('$argon2id$', (string) $after['password']);
        $this->assertNull($after['password_legacy']);
        $this->assertTrue(password_verify(AuthSeeder::PASSWORD, (string) $after['password']));

        // Login kedua: jalur Argon2id, tidak menyentuh MD5 (tercatat di audit login).
        $this->clearAuthState();
        $this->login(self::NIP, AuthSeeder::PASSWORD)->assertStatus(200);

        $logins = $this->db->table('audit_logs')->where('event', 'login')->where('nip_actor', self::NIP)->orderBy('id_log')->get()->getResultArray();
        $this->assertCount(2, $logins);
        $this->assertSame('legacy', json_decode((string) $logins[0]['after_json'], true)['password_path']);
        $this->assertTrue(json_decode((string) $logins[0]['after_json'], true)['rehashed']);
        $this->assertSame('argon2id', json_decode((string) $logins[1]['after_json'], true)['password_path']);
    }

    public function testWrongLegacyPasswordIsRejectedAndCountsTowardLockout(): void
    {
        $this->login(self::NIP, 'bukan-password')->assertStatus(401);

        $row = $this->db->table('pengguna')->where('nip', self::NIP)->get()->getRowArray();
        $this->assertNull($row['password'], 'MD5 salah tidak boleh memicu rehash');
        $this->assertNotNull($row['password_legacy']);
        $this->seeInDatabase('login_attempts', ['username' => self::NIP, 'success' => 0]);
    }

    public function testSecondLoginDoesNotRehashAgain(): void
    {
        $this->login(self::NIP, AuthSeeder::PASSWORD)->assertStatus(200);
        $firstHash = $this->db->table('pengguna')->where('nip', self::NIP)->get()->getRowArray()['password'];

        $this->clearAuthState();
        $this->login(self::NIP, AuthSeeder::PASSWORD)->assertStatus(200);

        $row = $this->db->table('pengguna')->where('nip', self::NIP)->get()->getRowArray();
        $this->assertSame($firstHash, $row['password'], 'Login kedua tidak boleh rehash ulang');
        $this->assertNull($row['password_legacy']);
    }

    public function testWrongLegacyPasswordLocksAccountAfterNFailures(): void
    {
        $max = config(AuthConfig::class)->lockoutMaxAttempts;

        for ($i = 1; $i <= $max; $i++) {
            $result = $this->login(self::NIP, 'md5-salah-' . $i);
            $result->assertStatus(401);
            $this->assertSame('Username atau password salah.', $this->json($result)['message']);
        }

        $row = $this->db->table('pengguna')->where('nip', self::NIP)->get()->getRowArray();
        $this->assertNull($row['password'], 'Akun masih jalur MD5 (belum pernah rehash)');

        $this->login(self::NIP, AuthSeeder::PASSWORD)->assertStatus(423);
    }

    public function testArgon2idAccountLogsInWithoutTouchingLegacy(): void
    {
        $before = $this->db->table('pengguna')->where('nip', AuthSeeder::NIP_ARGON)->get()->getRowArray();

        $this->login(AuthSeeder::NIP_ARGON, AuthSeeder::PASSWORD)->assertStatus(200);

        $after = $this->db->table('pengguna')->where('nip', AuthSeeder::NIP_ARGON)->get()->getRowArray();
        $this->assertSame($before['password'], $after['password']);
        $this->assertSame($before['password_legacy'], $after['password_legacy']);

        $login = $this->db->table('audit_logs')->where('event', 'login')->where('nip_actor', AuthSeeder::NIP_ARGON)->get()->getRowArray();
        $this->assertSame('argon2id', json_decode((string) $login['after_json'], true)['password_path']);
    }

    // ------------------------------------------------------------------
    // A-10 Audit login
    // ------------------------------------------------------------------

    public function testSuccessfulLoginIsAuditedWithActorAndTimestamp(): void
    {
        $this->login(self::NIP, AuthSeeder::PASSWORD)->assertStatus(200);

        $log = $this->db->table('audit_logs')->where('event', 'login')->where('nip_actor', self::NIP)->get()->getRowArray();
        $this->assertNotNull($log);
        $this->assertSame('pengguna', $log['entity']);
        $this->assertEqualsWithDelta(time(), strtotime((string) $log['created_at']), 5);
        $this->assertStringNotContainsString('$argon2id$', (string) $log['after_json']);

        // Hash password di audit update (rehash/last_login) dimasking.
        $updates = $this->db->table('audit_logs')->where('entity', 'pengguna')->where('event', 'update')->get()->getResultArray();
        $this->assertNotEmpty($updates);

        foreach ($updates as $u) {
            $this->assertStringNotContainsString('$argon2id$', (string) $u['after_json']);
            $this->assertStringNotContainsString(md5(AuthSeeder::PASSWORD), (string) $u['before_json']);
        }
    }

    public function testFailedLoginIsNotAuditedAsLogin(): void
    {
        $this->login(self::NIP, 'salah')->assertStatus(401);

        $this->assertSame(0, $this->db->table('audit_logs')->where('event', 'login')->countAllResults());
    }
}
