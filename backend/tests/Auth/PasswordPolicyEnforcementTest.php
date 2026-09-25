<?php

declare(strict_types=1);

namespace Tests\Auth;

use App\Constants\Role;
use App\Exceptions\ValidationException;
use App\Libraries\Auth\AccountProvisioner;
use App\Libraries\Auth\MockResetTokenNotifier;
use App\Libraries\Auth\PasswordVerifier;
use App\Models\Auth\PenggunaModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use Tests\Support\AuthTestTrait;
use Tests\Support\Database\Seeds\AuthSeeder;

/**
 * ISSUE-006 / K4 — kebijakan password legacy (min 8, huruf besar, huruf kecil, angka) ditegakkan di SEMUA jalur yang
 * menetapkan password: ganti password (A-06), reset password (A-07), admin buat/ubah akun (A-08), dan password awal
 * akun otomatis (A-09). Password lama yang tidak memenuhi aturan TIDAK dipaksa diganti: tetap bisa login dan tetap
 * bisa dipakai sebagai "password lama" saat ganti password.
 *
 * @internal
 */
final class PasswordPolicyEnforcementTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use AuthTestTrait;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = null;
    protected $seed      = AuthSeeder::class;

    private const SUPER_ADMIN = '198501012010011001';
    private const NIP         = '199002152015022002';
    private const NEW_NIP     = '200001012024011001';

    /** Password lama gaya legacy tanpa huruf besar: sah sebelum K4, tidak boleh dipaksa diganti. */
    private const WEAK_LEGACY = 'rahasia123';

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

    /**
     * @return array<string, mixed>
     */
    private function row(string $nip): array
    {
        return (array) $this->db->table('pengguna')->where('nip', $nip)->get()->getRowArray();
    }

    public function testChangePasswordRequiresUppercase(): void
    {
        $result = $this->asNip(self::NIP)->withBodyFormat('json')->post('api/v1/auth/change-password', [
            'old_password'              => AuthSeeder::PASSWORD,
            'new_password'              => 'passwordbaru456',
            'new_password_confirmation' => 'passwordbaru456',
        ]);

        $result->assertStatus(422);
        $this->assertSame(['Password harus mengandung minimal 1 huruf besar.'], $this->json($result)['errors']['new_password']);

        // Password lama tetap berlaku (verifikasi password lama boleh me-rehash MD5 → Argon2id, tapi bukan ganti).
        $row = $this->row(self::NIP);
        $this->assertFalse(password_verify('passwordbaru456', (string) $row['password']), 'Password tidak boleh berubah');
        $this->assertNull($row['password_changed_at']);
    }

    public function testResetPasswordRequiresLowercaseAndKeepsTokenUnused(): void
    {
        $notifier = new MockResetTokenNotifier();
        Services::injectMock('resetTokenNotifier', $notifier);
        $this->forgotPassword(self::NIP)->assertStatus(200);
        $token = $notifier->sent()[0]['token'];

        $result = $this->withBodyFormat('json')->post('api/v1/auth/reset-password', [
            'token'                     => $token,
            'new_password'              => 'PASSWORDRESET789',
            'new_password_confirmation' => 'PASSWORDRESET789',
        ]);

        $result->assertStatus(422);
        $this->assertSame(['Password harus mengandung minimal 1 huruf kecil.'], $this->json($result)['errors']['new_password']);
        $this->seeInDatabase('forgot_attempts', ['token_hash' => hash('sha256', $token), 'used_at' => null]);
    }

    public function testAdminCreateRequiresDigit(): void
    {
        $result = $this->asNip(self::SUPER_ADMIN)->withBodyFormat('json')->post('api/v1/auth/users', [
            'nip' => self::NEW_NIP, 'password' => 'AkunBaruDuaRibu', 'user_level' => Role::PEGAWAI,
        ]);

        $result->assertStatus(422);
        $this->assertSame(['Password harus mengandung minimal 1 angka.'], $this->json($result)['errors']['password']);
        $this->dontSeeInDatabase('pengguna', ['nip' => self::NEW_NIP]);
    }

    public function testAdminUpdateRequiresUppercase(): void
    {
        $id     = (int) $this->row(self::NIP)['id_pengguna'];
        $result = $this->asNip(self::SUPER_ADMIN)->withBodyFormat('json')->put("api/v1/auth/users/{$id}", ['password' => 'akunbaru2026']);

        $result->assertStatus(422);
        $this->assertSame(['Password harus mengandung minimal 1 huruf besar.'], $this->json($result)['errors']['password']);
        $this->assertNull($this->row(self::NIP)['password']);
    }

    public function testProvisionerRejectsInitialPasswordOutsidePolicy(): void
    {
        $pengguna    = new PenggunaModel($this->db);
        $provisioner = new AccountProvisioner($pengguna, new PasswordVerifier($pengguna));

        try {
            $provisioner->provisionForPegawai(['nip' => self::NEW_NIP], Role::PEGAWAI, 'awalsekali1');
            $this->fail('Password awal tanpa huruf besar harus ditolak');
        } catch (ValidationException $e) {
            $this->assertSame(['Password harus mengandung minimal 1 huruf besar.'], $e->getErrors()['password']);
        }

        $this->dontSeeInDatabase('pengguna', ['nip' => self::NEW_NIP]);

        $result = $provisioner->provisionForPegawai(['nip' => self::NEW_NIP], Role::PEGAWAI, 'AwalValid2026');
        $this->assertTrue($result['created']);
        $this->assertSame('AwalValid2026', $result['initial_password']);
    }

    public function testExistingWeakPasswordIsNotForcedToChange(): void
    {
        // Akun legacy dengan password lama (MD5) yang tidak memenuhi K4.
        $this->db->table('pengguna')->where('nip', self::NIP)->update(['password' => null, 'password_legacy' => md5(self::WEAK_LEGACY)]);

        $login = $this->login(self::NIP, self::WEAK_LEGACY);
        $login->assertStatus(200);
        $this->assertStringStartsWith('$argon2id$', (string) $this->row(self::NIP)['password'], 'Lazy rehash tetap jalan');

        // Password lama yang lemah tetap sah sebagai old_password; hanya password BARU yang wajib K4.
        $this->clearAuthState();
        $this->asNip(self::NIP)->withBodyFormat('json')->post('api/v1/auth/change-password', [
            'old_password'              => self::WEAK_LEGACY,
            'new_password'              => 'PasswordBaru456',
            'new_password_confirmation' => 'PasswordBaru456',
        ])->assertStatus(200);
    }
}
