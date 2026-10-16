<?php

declare(strict_types=1);

namespace Tests\Auth;

use App\Constants\Role;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;
use Config\Auth as AuthConfig;
use Config\Jwt as JwtConfig;
use Tests\Support\AuthTestTrait;
use Tests\Support\Database\Seeds\AuthSeeder;
use Tests\Support\DatabaseTestCase;

/**
 * MAKE-020 (ISSUE-044) — paksa ganti password saat login (`pengguna.must_change_password`, DBV-020 bagian 4).
 *
 * Penanda dinyalakan saat password diatur sistem/admin (akun baru, reset admin, koreksi NIP — dua yang terakhir
 * juga diuji di AccountProvisionerTest / NipCascadeTest) dan dilepas saat pemilik memilih password sendiri (ganti
 * password, reset lewat token email). Selama penanda = 1, API hanya membuka auth/me, auth/change-password,
 * auth/refresh, dan auth/logout; endpoint lain → 403 `password_change_required`.
 *
 * @internal
 */
final class WajibGantiPasswordTest extends DatabaseTestCase
{
    use FeatureTestTrait;
    use AuthTestTrait;

    protected $seed = AuthSeeder::class;

    private const NIP     = '199002152015022002';
    private const NEW     = 'PasswordBaru456';
    private const NIP_BRU = '200001012024011001';

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

    public function testLoginDanMeMengembalikanPenanda(): void
    {
        $this->tandai(self::NIP);

        $login = $this->login(self::NIP, AuthSeeder::PASSWORD);
        $login->assertStatus(200);
        $data = $this->json($login)['data'];
        $this->assertTrue($data['user']['must_change_password']);

        $this->clearAuthState();
        $me = $this->withBearer($data['access_token'])->get('api/v1/auth/me');
        $me->assertStatus(200);
        $this->assertTrue($this->json($me)['data']['user']['must_change_password']);

        // Akun tanpa penanda: false.
        $this->clearAuthState();
        $lain = $this->login(AuthSeeder::NIP_ARGON, AuthSeeder::PASSWORD);
        $lain->assertStatus(200);
        $this->assertFalse($this->json($lain)['data']['user']['must_change_password']);
    }

    public function testEndpointLainDitolakSelamaPenandaAktif(): void
    {
        $admin = AuthSeeder::nipForRole(Role::SUPER_ADMIN);
        $this->tandai($admin);
        $token = $this->loginToken($admin);

        foreach (['api/v1/_rbac/any', 'api/v1/auth/users', 'api/v1/faq'] as $url) {
            $this->clearAuthState();
            $this->assertDitolak($this->withBearer($token)->get($url), $url);
        }

        $this->clearAuthState();
        $this->assertDitolak($this->withBearer($token)->withBodyFormat('json')->post('api/v1/auth/users', [
            'nip' => self::NIP_BRU, 'password' => 'AkunBaru2026', 'user_level' => Role::PEGAWAI,
        ]), 'POST users');
        $this->dontSeeInDatabase('pengguna', ['nip' => self::NIP_BRU]);

        // Akun lain (tanpa penanda) tidak terdampak.
        $this->clearAuthState();
        $this->asNip(self::NIP)->get('api/v1/_rbac/any')->assertStatus(200);
    }

    public function testMeRefreshDanLogoutTetapTerbuka(): void
    {
        $this->tandai(self::NIP);

        $login = $this->login(self::NIP, AuthSeeder::PASSWORD);
        $login->assertStatus(200);
        $refresh = $this->responseCookie($login, config(JwtConfig::class)->refreshCookie);
        $this->assertNotNull($refresh);

        $this->clearAuthState();
        $this->setRefreshCookie($refresh);
        $baru = $this->post('api/v1/auth/refresh');
        $baru->assertStatus(200);
        $akses = $this->json($baru)['data']['access_token'];

        // Token hasil refresh tetap membawa penanda.
        $this->clearAuthState();
        $this->assertDitolak($this->withBearer($akses)->get('api/v1/_rbac/any'), 'setelah refresh');

        $this->clearAuthState();
        $this->withBearer($akses)->get('api/v1/auth/me')->assertStatus(200);

        $this->clearAuthState();
        $this->withBearer($akses)->post('api/v1/auth/logout')->assertStatus(200);
    }

    public function testGantiPasswordMelepasPenandaLaluSesiNormal(): void
    {
        $this->tandai(self::NIP);
        $token = $this->loginToken(self::NIP);

        $this->clearAuthState();
        $this->withBearer($token)->withBodyFormat('json')->post('api/v1/auth/change-password', [
            'old_password'              => AuthSeeder::PASSWORD,
            'new_password'              => self::NEW,
            'new_password_confirmation' => self::NEW,
        ])->assertStatus(200);

        $this->assertSame(0, $this->penanda(self::NIP));

        // Login ulang (sesi lama dicabut oleh ganti password) → penanda false, endpoint lain terbuka.
        $this->clearAuthState();
        $login = $this->login(self::NIP, self::NEW);
        $login->assertStatus(200);
        $this->assertFalse($this->json($login)['data']['user']['must_change_password']);

        $this->clearAuthState();
        $this->withBearer($this->json($login)['data']['access_token'])->get('api/v1/_rbac/any')->assertStatus(200);
    }

    public function testAdminMembuatAkunDanResetPasswordMenyalakanPenanda(): void
    {
        $admin = AuthSeeder::nipForRole(Role::SUPER_ADMIN);
        $this->daftarkanPegawai(self::NIP_BRU);

        $this->asNip($admin)->withBodyFormat('json')->post('api/v1/auth/users', [
            'nip' => self::NIP_BRU, 'password' => 'AkunBaru2026', 'user_level' => Role::PEGAWAI,
        ])->assertStatus(201);
        $this->assertSame(1, $this->penanda(self::NIP_BRU), 'akun baru dari admin');

        $this->assertSame(0, $this->penanda(self::NIP));
        $this->clearAuthState();
        $this->asNip($admin)->withBodyFormat('json')->put('api/v1/auth/users/' . $this->idOf(self::NIP), ['password' => 'ResetAdmin2026'])
            ->assertStatus(200);
        $this->assertSame(1, $this->penanda(self::NIP), 'reset password oleh admin');

        // Ubah field lain tanpa password: penanda tidak disentuh.
        $this->db->table('pengguna')->where('nip', self::NIP)->update(['must_change_password' => 0]);
        $this->clearAuthState();
        $this->asNip($admin)->withBodyFormat('json')->put('api/v1/auth/users/' . $this->idOf(self::NIP), ['name' => 'Nama Baru'])
            ->assertStatus(200);
        $this->assertSame(0, $this->penanda(self::NIP), 'tanpa password');

        // Admin mengganti password akunnya sendiri lewat Manajemen Akun = pemilik memilih sendiri → tidak dinyalakan.
        $this->clearAuthState();
        $this->asNip($admin)->withBodyFormat('json')->put('api/v1/auth/users/' . $this->idOf($admin), ['password' => 'AdminSendiri2026'])
            ->assertStatus(200);
        $this->assertSame(0, $this->penanda($admin), 'akun sendiri');
    }

    public function testResetLewatTokenEmailMelepasPenanda(): void
    {
        config(AuthConfig::class)->exposeResetTokenInResponse = true;
        $this->tandai(self::NIP);

        $forgot = $this->forgotPassword(self::NIP);
        $forgot->assertStatus(200);

        $this->clearAuthState();
        config(AuthConfig::class)->exposeResetTokenInResponse = true;
        $this->withBodyFormat('json')->post('api/v1/auth/reset-password', [
            'token'                     => $this->json($forgot)['data']['token'],
            'new_password'              => self::NEW,
            'new_password_confirmation' => self::NEW,
        ])->assertStatus(200);

        $this->assertSame(0, $this->penanda(self::NIP));
    }

    private function tandai(string $nip): void
    {
        $this->db->table('pengguna')->where('nip', $nip)->update(['must_change_password' => 1]);
    }

    private function penanda(string $nip): int
    {
        return (int) $this->db->table('pengguna')->where('nip', $nip)->get()->getRowArray()['must_change_password'];
    }

    private function idOf(string $nip): int
    {
        return (int) $this->db->table('pengguna')->where('nip', $nip)->get()->getRowArray()['id_pengguna'];
    }

    /** Access token dari login sungguhan (claims dibangun dari baris DB). */
    private function loginToken(string $nip): string
    {
        $login = $this->login($nip, AuthSeeder::PASSWORD);
        $login->assertStatus(200);

        return (string) $this->json($login)['data']['access_token'];
    }

    private function assertDitolak(TestResponse $result, string $label): void
    {
        $result->assertStatus(403);
        $this->assertSame(
            ['status' => 'error', 'code' => 'password_change_required'],
            array_intersect_key($this->json($result), ['status' => 1, 'code' => 1]),
            $label,
        );
    }
}
