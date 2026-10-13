<?php

declare(strict_types=1);

namespace Tests\Auth;

use App\Constants\Role;
use App\Libraries\Auth\JwtService;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Auth as AuthConfig;
use Config\Jwt as JwtConfig;
use Firebase\JWT\JWT;
use Tests\Support\AuthTestTrait;
use Tests\Support\Database\Seeds\AuthSeeder;
use Tests\Support\DatabaseTestCase;
use Tests\Support\MasterDataTestTrait;

/**
 * DBV-010/CR-013 — akun tanpa NIP (role 1/3/4/5/8, K2) memakai seluruh jalur auth dengan identitas `id_pengguna`:
 * login lewat username bebas, sesi (sub = id_pengguna), pencabutan & reuse per akun, ganti/reset password, audit
 * berpelaku id_pengguna (nip_actor NULL), stamp `updated_by` master, pencegahan hapus/nonaktifkan akun sendiri, scoping
 * Admin Satker. Token format lama (sub = NIP) ditolak sehingga pengguna login ulang sekali setelah deploy.
 *
 * Data akun tanpa NIP dibersihkan di tearDown(): down() migration DBV-010 menolaknya saat regress test berikutnya.
 *
 * @internal
 */
final class AkunTanpaNipTest extends DatabaseTestCase
{
    use FeatureTestTrait;
    use AuthTestTrait;
    use MasterDataTestTrait;

    protected $seed = AuthSeeder::class;

    private const NEW_PASSWORD = 'PasswordBaru2026';

    protected function setUp(): void
    {
        parent::setUp();
        $this->clearAuthState();
        $this->resetMasterState();
    }

    protected function tearDown(): void
    {
        $this->clearAuthState();
        $this->bersihkanDataDbv010();
        parent::tearDown();
    }

    public function testLoginWithFreeUsernameIssuesSessionKeyedById(): void
    {
        $akun = $this->buatAkunTanpaNip('admin.pusat');
        $id   = (int) $akun['id_pengguna'];

        $login = $this->login('admin.pusat', AuthSeeder::PASSWORD);
        $login->assertStatus(200);
        $json = $this->json($login);

        $this->assertSame($id, $json['data']['user']['id_pengguna']);
        $this->assertNull($json['data']['user']['nip']);
        $this->assertSame('Akun admin.pusat', $json['data']['user']['name']);

        $claims = service('jwt')->verifyAccessToken($json['data']['access_token']);
        $this->assertSame((string) $id, $claims['sub']);
        $this->assertNull($claims['nip']);
        $this->assertSame(JwtService::CLAIMS_VERSION, $claims['ver']);

        $refresh = (string) $this->responseCookie($login, 'refresh_token');
        $this->seeInDatabase('token', ['token_hash' => hash('sha256', $refresh), 'id_pengguna' => $id, 'nip' => null, 'revoked' => 0]);

        $this->clearAuthState();
        $me = $this->withBearer($json['data']['access_token'])->get('api/v1/auth/me');
        $me->assertStatus(200);
        $this->assertSame($id, $this->json($me)['data']['user']['id_pengguna']);
        $this->assertSame('admin.pusat', $this->json($me)['data']['user']['username']);
    }

    /**
     * T-02 untuk akun tanpa NIP: setiap baris audit selama login (lazy rehash MD5, last_login_at, event login)
     * berpelaku pemilik akun lewat id_pengguna_actor; nip_actor NULL.
     */
    public function testEveryLoginAuditRowHasOwnerIdAsActor(): void
    {
        $akun = $this->buatAkunTanpaNip('menteri', Role::MENTERI, ['password' => null, 'password_legacy' => md5(AuthSeeder::PASSWORD)]);

        $this->login('menteri', AuthSeeder::PASSWORD)->assertStatus(200);

        $rows = $this->db->table('audit_logs')->where('entity', 'pengguna')->orderBy('id_log')->get()->getResultArray();
        $this->assertSame(['update', 'update', 'login'], array_column($rows, 'event'));

        foreach ($rows as $row) {
            $this->assertSame((string) $akun['id_pengguna'], (string) $row['id_pengguna_actor'], "audit {$row['event']}");
            $this->assertNull($row['nip_actor']);
        }
    }

    /**
     * Rotasi dan reuse detection per id_pengguna: reuse di akun A hanya mencabut sesi A, sesi akun tanpa NIP lain (B)
     * tetap hidup. Logout menghapus baris token dan mencatat audit logout berpelaku id_pengguna.
     */
    public function testRefreshReuseAndLogoutAreScopedToTheAccount(): void
    {
        $a = $this->buatAkunTanpaNip('akun.a', Role::ADMIN_VIEW_ESELON1);
        $b = $this->buatAkunTanpaNip('akun.b', Role::PIMPINAN);

        $sessionA = $this->issueTokensForUser($a);
        $sessionB = $this->issueTokensForUser($b);

        $this->setRefreshCookie($sessionA['refresh_token']);
        $rotated = $this->post('api/v1/auth/refresh');
        $rotated->assertStatus(200);

        // Reuse token A lama → seluruh sesi A dicabut, sesi B tidak.
        $this->clearAuthState();
        $this->setRefreshCookie($sessionA['refresh_token']);
        $this->post('api/v1/auth/refresh')->assertStatus(401);
        $this->assertSame(0, $this->db->table('token')->where('id_pengguna', $a['id_pengguna'])->where('revoked', 0)->countAllResults());

        $this->clearAuthState();
        $this->setRefreshCookie($sessionB['refresh_token']);
        $refreshedB = $this->post('api/v1/auth/refresh');
        $refreshedB->assertStatus(200);

        // Logout B: baris token dihapus, audit logout berpelaku B.
        $accessB  = $this->json($refreshedB)['data']['access_token'];
        $refreshB = (string) $this->responseCookie($refreshedB, 'refresh_token');
        $this->clearAuthState();
        $this->setRefreshCookie($refreshB);
        $this->withBearer($accessB)->post('api/v1/auth/logout')->assertStatus(200);

        $this->dontSeeInDatabase('token', ['token_hash' => hash('sha256', $refreshB)]);
        $this->seeInDatabase('audit_logs', [
            'event'             => 'logout', 'entity' => 'pengguna', 'entity_id' => (string) $b['id_pengguna'],
            'id_pengguna_actor' => $b['id_pengguna'], 'nip_actor' => null,
        ]);
    }

    public function testChangeAndResetPasswordWorkForAccountWithoutNip(): void
    {
        $akun = $this->buatAkunTanpaNip('admin.satker', Role::ADMIN_SATKER);
        $id   = (int) $akun['id_pengguna'];

        $other = $this->issueTokensForUser($akun);
        $this->asUser($akun)->withBodyFormat('json')->post('api/v1/auth/change-password', [
            'old_password'              => AuthSeeder::PASSWORD,
            'new_password'              => self::NEW_PASSWORD,
            'new_password_confirmation' => self::NEW_PASSWORD,
        ])->assertStatus(200);

        $this->assertSame(0, $this->db->table('token')->where('id_pengguna', $id)->countAllResults(), 'seluruh sesi dicabut');
        $this->seeInDatabase('audit_logs', ['entity' => 'pengguna', 'event' => 'update', 'entity_id' => (string) $id, 'id_pengguna_actor' => $id, 'nip_actor' => null]);

        $this->clearAuthState();
        $this->setRefreshCookie($other['refresh_token']);
        $this->post('api/v1/auth/refresh')->assertStatus(401);

        // Reset password lewat token (tanpa sesi): pelaku = pemilik akun.
        $this->clearAuthState();
        config(AuthConfig::class)->exposeResetTokenInResponse = true;
        $session                                              = $this->issueTokensForUser($akun);
        $token                                                = $this->json($this->forgotPassword('admin.satker'))['data']['token'];
        $lastLog                                              = (int) ($this->db->table('audit_logs')->selectMax('id_log')->get()->getRowArray()['id_log'] ?? 0);

        $this->withBodyFormat('json')->post('api/v1/auth/reset-password', [
            'token'                     => $token,
            'new_password'              => 'PasswordReset2026',
            'new_password_confirmation' => 'PasswordReset2026',
        ])->assertStatus(200);

        $this->dontSeeInDatabase('token', ['token_hash' => hash('sha256', $session['refresh_token'])]);
        $rows = $this->db->table('audit_logs')->where('id_log >', $lastLog)->get()->getResultArray();
        $this->assertCount(1, $rows);
        $this->assertSame((string) $id, (string) $rows[0]['id_pengguna_actor']);
        $this->assertNull($rows[0]['nip_actor']);

        $this->clearAuthState();
        $this->login('admin.satker', 'PasswordReset2026')->assertStatus(200);
    }

    /**
     * Super Admin tanpa NIP (akun legacy tipikal): stamp created_by/updated_by master dan audit CRUD akun memakai
     * id_pengguna, bukan NULL.
     */
    public function testSuperAdminWithoutNipStampsMasterAndAuditsAccountChanges(): void
    {
        $admin = $this->buatAkunTanpaNip('superadmin');
        $id    = (int) $admin['id_pengguna'];

        $created = $this->asUser($admin)->withBodyFormat('json')->post('api/v1/master/agama', ['agama' => 'Kepercayaan']);
        $created->assertStatus(201);
        $agamaId = (int) $this->db->table('agama')->where('agama', 'Kepercayaan')->get()->getRowArray()['id_agama'];
        $this->seeInDatabase('agama', ['id_agama' => $agamaId, 'updated_by' => $id]);

        $this->clearAuthState();
        $this->resetMasterState();
        $this->asUser($admin)->withBodyFormat('json')->put('api/v1/master/agama/' . $agamaId, ['agama' => 'Kepercayaan YME'])->assertStatus(200);
        $this->seeInDatabase('agama', ['id_agama' => $agamaId, 'agama' => 'Kepercayaan YME', 'updated_by' => $id]);
        $this->seeInDatabase('audit_logs', ['entity' => 'agama', 'event' => 'update', 'id_pengguna_actor' => $id, 'nip_actor' => null]);

        // CRUD akun oleh Super Admin tanpa NIP.
        $this->clearAuthState();
        $new = $this->asUser($admin)->withBodyFormat('json')->post('api/v1/auth/users', [
            'nip' => '200001012024011001', 'password' => self::NEW_PASSWORD, 'user_level' => Role::PEGAWAI,
        ]);
        $new->assertStatus(201);
        $this->seeInDatabase('audit_logs', [
            'entity'            => 'pengguna', 'event' => 'create', 'entity_id' => (string) $this->json($new)['data']['id_pengguna'],
            'id_pengguna_actor' => $id, 'nip_actor' => null,
        ]);
    }

    /**
     * Pencegahan hapus/nonaktifkan akun sendiri memakai id_pengguna: dengan NIP NULL, akun tanpa NIP lain tetap bisa
     * dikelola (sebelumnya perbandingan NIP menganggap semua akun tanpa NIP "diri sendiri").
     */
    public function testSelfProtectionUsesIdNotNip(): void
    {
        $admin = $this->buatAkunTanpaNip('superadmin');
        $other = $this->buatAkunTanpaNip('pimpinan', Role::PIMPINAN);
        $self  = (int) $admin['id_pengguna'];

        $this->asUser($admin)->delete('api/v1/auth/users/' . $self)->assertStatus(422);
        $this->clearAuthState();
        $deactivate = $this->asUser($admin)->withBodyFormat('json')->patch('api/v1/auth/users/' . $self . '/status', ['status' => '0']);
        $deactivate->assertStatus(422);
        $this->assertArrayHasKey('status', $this->json($deactivate)['errors']);
        $this->seeInDatabase('pengguna', ['id_pengguna' => $self, 'status' => '1', 'deleted_at' => null]);

        $this->clearAuthState();
        $this->asUser($admin)->withBodyFormat('json')->patch('api/v1/auth/users/' . $other['id_pengguna'] . '/status', ['status' => '0'])->assertStatus(200);
        $this->clearAuthState();
        $this->asUser($admin)->delete('api/v1/auth/users/' . $other['id_pengguna'])->assertStatus(200);
        $this->assertNotNull($this->db->table('pengguna')->where('id_pengguna', $other['id_pengguna'])->get()->getRowArray()['deleted_at']);
    }

    public function testAdminSatkerWithoutNipIsScopedToOwnSatker(): void
    {
        $admin = $this->buatAkunTanpaNip('admin.s01', Role::ADMIN_SATKER, ['id_satker' => 'S01']);

        $list = $this->json($this->asUser($admin)->get('api/v1/auth/users?per_page=100'));
        $this->assertGreaterThan(0, $list['data']['total']);

        foreach ($list['data']['items'] as $item) {
            $this->assertSame('S01', $item['id_satker']);
        }

        $outside = (int) $this->db->table('pengguna')->where('nip', '199505052018052006')->get()->getRowArray()['id_pengguna']; // S02
        $this->clearAuthState();
        $this->asUser($admin)->get('api/v1/auth/users/' . $outside)->assertStatus(403);
    }

    /**
     * Kompatibilitas deploy: access token format lama (sub = NIP, tanpa 'ver', secret sama) → 401; baris refresh token
     * format lama → 401 "tidak dikenal" dan barisnya dihapus. Pengguna cukup login ulang.
     */
    public function testLegacyTokensAreRejectedSoUsersLogInAgain(): void
    {
        $nip    = AuthSeeder::nipForRole(Role::SUPER_ADMIN);
        $config = config(JwtConfig::class);
        $now    = time();
        $legacy = JWT::encode(
            ['iss' => $config->issuer, 'iat' => $now, 'nbf' => $now, 'exp' => $now + 3600, 'sub' => $nip, 'role' => Role::SUPER_ADMIN],
            $config->secret,
            $config->algorithm,
        );

        $this->withBearer($legacy)->get('api/v1/auth/me')->assertStatus(401);

        $plain = bin2hex(random_bytes(32));
        $this->db->table('token')->insert([
            'id_pengguna' => 0, 'nip' => $nip, 'token_hash' => hash('sha256', $plain),
            'claims_json' => json_encode(['sub' => $nip, 'role' => Role::SUPER_ADMIN, 'id_unit' => 'U01', 'id_satker' => 'S01']),
            'expires_at'  => date('Y-m-d H:i:s', $now + 3600), 'revoked' => 0, 'created_at' => date('Y-m-d H:i:s', $now),
        ]);

        $this->clearAuthState();
        $this->setRefreshCookie($plain);
        $result = $this->post('api/v1/auth/refresh');
        $result->assertStatus(401);
        $this->assertSame('', $this->responseCookie($result, 'refresh_token'), 'cookie refresh dihapus');
        $this->dontSeeInDatabase('token', ['token_hash' => hash('sha256', $plain)]);

        $this->clearAuthState();
        $this->login($nip, AuthSeeder::PASSWORD)->assertStatus(200);
    }
}
