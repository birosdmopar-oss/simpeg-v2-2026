<?php

declare(strict_types=1);

namespace Tests\Auth;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\AuthTestTrait;
use Tests\Support\Database\Seeds\AuthSeeder;

/**
 * A-06 — ganti password (MTC-006): lama salah ditolak; baru Argon2id; seluruh refresh token lama dicabut; audit.
 *
 * @internal
 */
final class ChangePasswordTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use AuthTestTrait;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = null;
    protected $seed      = AuthSeeder::class;

    private const NIP = '199002152015022002';
    private const NEW = 'PasswordBaru456';

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

    public function testWrongOldPasswordIsRejected(): void
    {
        $result = $this->asNip(self::NIP)->withBodyFormat('json')->post('api/v1/auth/change-password', [
            'old_password'              => 'salah',
            'new_password'              => self::NEW,
            'new_password_confirmation' => self::NEW,
        ]);

        $result->assertStatus(422);
        $this->assertArrayHasKey('old_password', $this->json($result)['errors']);
        $this->assertNull($this->db->table('pengguna')->where('nip', self::NIP)->get()->getRowArray()['password']);
    }

    public function testWeakOrMismatchedNewPasswordIsRejected(): void
    {
        $result = $this->asNip(self::NIP)->withBodyFormat('json')->post('api/v1/auth/change-password', [
            'old_password'              => AuthSeeder::PASSWORD,
            'new_password'              => 'pendek',
            'new_password_confirmation' => 'beda',
        ]);

        $result->assertStatus(422);
        $errors = $this->json($result)['errors'];
        $this->assertArrayHasKey('new_password', $errors);
        $this->assertArrayHasKey('new_password_confirmation', $errors);
    }

    public function testSuccessStoresArgon2idAndRevokesAllRefreshTokens(): void
    {
        $sessionA = $this->issueTokensFor(self::NIP);
        $sessionB = $this->issueTokensFor(self::NIP);

        $result = $this->withBearer($sessionA['access_token'])->withBodyFormat('json')->post('api/v1/auth/change-password', [
            'old_password'              => AuthSeeder::PASSWORD, // masih MD5 legacy → verifikasi berlapis
            'new_password'              => self::NEW,
            'new_password_confirmation' => self::NEW,
        ]);

        $result->assertStatus(200);

        $row = $this->db->table('pengguna')->where('nip', self::NIP)->get()->getRowArray();
        $this->assertStringStartsWith('$argon2id$', (string) $row['password']);
        $this->assertTrue(password_verify(self::NEW, (string) $row['password']));
        $this->assertNull($row['password_legacy']);
        $this->assertNotNull($row['password_changed_at']);

        // Seluruh sesi lama tidak berlaku lagi.
        $this->assertSame(0, $this->db->table('token')->where('nip', self::NIP)->where('revoked', 0)->countAllResults());

        foreach ([$sessionA, $sessionB] as $session) {
            $this->clearAuthState();
            $this->setRefreshCookie($session['refresh_token']);
            $this->post('api/v1/auth/refresh')->assertStatus(401);
        }

        // Login dengan password baru berhasil, password lama ditolak.
        $this->clearAuthState();
        $this->login(self::NIP, self::NEW)->assertStatus(200);
        $this->clearAuthState();
        $this->login(self::NIP, AuthSeeder::PASSWORD)->assertStatus(401);
    }

    public function testChangeIsAuditedWithMaskedHashAndActor(): void
    {
        $this->asNip(self::NIP)->withBodyFormat('json')->post('api/v1/auth/change-password', [
            'old_password'              => AuthSeeder::PASSWORD,
            'new_password'              => self::NEW,
            'new_password_confirmation' => self::NEW,
        ])->assertStatus(200);

        $logs = $this->db->table('audit_logs')->where('entity', 'pengguna')->where('event', 'update')->where('nip_actor', self::NIP)->get()->getResultArray();
        $this->assertNotEmpty($logs);

        $found = false;

        foreach ($logs as $log) {
            $after = json_decode((string) $log['after_json'], true);
            $this->assertStringNotContainsString('$argon2id$', (string) $log['after_json']);

            if (($after['password'] ?? null) === '***' && ! empty($after['password_changed_at'])) {
                $found = true;
            }
        }

        $this->assertTrue($found, 'Audit ganti password harus ada dengan hash dimasking');
    }

    public function testRequiresAuthentication(): void
    {
        $this->withBodyFormat('json')->post('api/v1/auth/change-password', [
            'old_password' => 'x', 'new_password' => self::NEW, 'new_password_confirmation' => self::NEW,
        ])->assertStatus(401);
    }
}
