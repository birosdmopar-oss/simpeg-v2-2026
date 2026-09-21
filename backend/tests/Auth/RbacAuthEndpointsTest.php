<?php

declare(strict_types=1);

namespace Tests\Auth;

use App\Constants\Role;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\AuthTestTrait;
use Tests\Support\Database\Seeds\AuthSeeder;

/**
 * A-13 — RBAC middleware 8 role terhadap SELURUH endpoint Modul A (Matriks Role x Endpoint Bagian 2 Modul A).
 *
 * Publik  : login, refresh, forgot-password, reset-password → bisa diakses tanpa token (validasi 4xx, bukan 401 auth).
 * UL_ALL  : logout, me, change-password → 8 role 200/422 (bukan 401/403); tanpa token → 401.
 * Role 1,3: users/* → 1 & 3 lolos filter, role lain 403.
 *
 * @internal
 */
final class RbacAuthEndpointsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use AuthTestTrait;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = null;
    protected $seed      = AuthSeeder::class;

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
     * @return iterable<string, array{int}>
     */
    public static function allRoles(): iterable
    {
        foreach (Role::all() as $role) {
            yield Role::label($role) => [$role];
        }
    }

    public function testPublicEndpointsDoNotRequireToken(): void
    {
        // Tanpa token: bukan 401 dari JwtAuthFilter, melainkan validasi/bisnis.
        $this->withBodyFormat('json')->post('api/v1/auth/login', [])->assertStatus(422);
        $this->post('api/v1/auth/refresh')->assertStatus(401); // 401 karena refresh token tidak ada (bisnis), bukan filter
        $this->withBodyFormat('json')->post('api/v1/auth/forgot-password', [])->assertStatus(422);
        $this->withBodyFormat('json')->post('api/v1/auth/reset-password', [])->assertStatus(422);
    }

    public function testProtectedEndpointsRejectMissingToken(): void
    {
        $this->post('api/v1/auth/logout')->assertStatus(401);
        $this->get('api/v1/auth/me')->assertStatus(401);
        $this->withBodyFormat('json')->post('api/v1/auth/change-password', [])->assertStatus(401);
        $this->get('api/v1/auth/users')->assertStatus(401);
        $this->withBodyFormat('json')->post('api/v1/auth/users', [])->assertStatus(401);
        $this->get('api/v1/auth/users/1')->assertStatus(401);
        $this->withBodyFormat('json')->put('api/v1/auth/users/1', [])->assertStatus(401);
        $this->withBodyFormat('json')->patch('api/v1/auth/users/1/status', ['status' => '0'])->assertStatus(401);
        $this->delete('api/v1/auth/users/1')->assertStatus(401);
    }

    /**
     * Payload valid: role berhak mendapat sukses sungguhan (200/201), bukan sekadar lolos filter (422);
     * role tidak berhak mendapat 403 dengan body generik.
     *
     * @dataProvider allRoles
     */
    public function testUserManagementWithValidPayloadFollowsMatrix(int $role): void
    {
        $allowed = in_array($role, [Role::SUPER_ADMIN, Role::ADMIN_SATKER], true);
        $id      = (int) $this->db->table('pengguna')->where('nip', AuthSeeder::nipForRole(Role::PEGAWAI))->get()->getRowArray()['id_pengguna'];

        $create = $this->asRole($role)->withBodyFormat('json')->post('api/v1/auth/users', [
            'nip' => '200001012024011001', 'password' => 'AkunBaru2026', 'user_level' => Role::PEGAWAI,
        ]);
        $this->assertSame($allowed ? 201 : 403, $create->response()->getStatusCode(), sprintf('POST users role %d', $role));

        if (! $allowed) {
            $this->assertSame(['status' => 'error', 'message' => 'Forbidden'], array_intersect_key($this->json($create), ['status' => 1, 'message' => 1]));
            $this->dontSeeInDatabase('pengguna', ['nip' => '200001012024011001']);
        }

        $this->clearAuthState();
        $this->asRole($role)->withBodyFormat('json')->patch('api/v1/auth/users/' . $id . '/status', ['status' => '0'])->assertStatus($allowed ? 200 : 403);
        $this->seeInDatabase('pengguna', ['id_pengguna' => $id, 'status' => $allowed ? '0' : '1']);
    }

    /**
     * @dataProvider allRoles
     */
    public function testUlAllEndpointsAreAccessibleToEveryRole(int $role): void
    {
        $this->asRole($role)->get('api/v1/auth/me')->assertStatus(200);

        // change-password: lolos filter (bukan 401/403); payload kosong → 422 validasi.
        $this->asRole($role)->withBodyFormat('json')->post('api/v1/auth/change-password', [])->assertStatus(422);

        // Payload valid → 200 untuk setiap role.
        $this->clearAuthState();
        $this->asRole($role)->withBodyFormat('json')->post('api/v1/auth/change-password', [
            'old_password' => AuthSeeder::PASSWORD, 'new_password' => 'PasswordBaru2026', 'new_password_confirmation' => 'PasswordBaru2026',
        ])->assertStatus(200);
        $this->clearAuthState();

        $this->asRole($role)->post('api/v1/auth/logout')->assertStatus(200);
    }

    /**
     * @dataProvider allRoles
     */
    public function testUserManagementEndpointsFollowMatrix(int $role): void
    {
        $allowed  = in_array($role, [Role::SUPER_ADMIN, Role::ADMIN_SATKER], true);
        $expected = $allowed ? 200 : 403;

        $list = $this->asRole($role)->get('api/v1/auth/users');
        $this->assertSame($expected, $list->response()->getStatusCode(), sprintf('GET users role %d (%s)', $role, Role::label($role)));

        // POST dengan payload kosong: role berhak → 422 (validasi), tidak berhak → 403 (filter lebih dulu).
        $create = $this->asRole($role)->withBodyFormat('json')->post('api/v1/auth/users', []);
        $this->assertSame($allowed ? 422 : 403, $create->response()->getStatusCode(), sprintf('POST users role %d', $role));

        // Objek response bersifat shared antar request dalam satu test: assert langsung setelah tiap request.
        $id = (int) $this->db->table('pengguna')->where('nip', AuthSeeder::nipForRole(Role::PEGAWAI))->get()->getRowArray()['id_pengguna'];

        $this->asRole($role)->get('api/v1/auth/users/' . $id)->assertStatus($allowed ? 200 : 403);
        $this->asRole($role)->withBodyFormat('json')->put('api/v1/auth/users/' . $id, [])->assertStatus($allowed ? 200 : 403);
        $this->asRole($role)->withBodyFormat('json')->patch('api/v1/auth/users/' . $id . '/status', [])->assertStatus($allowed ? 422 : 403);
        $this->asRole($role)->delete('api/v1/auth/users/' . $id)->assertStatus($allowed ? 200 : 403);

        if (! $allowed) {
            $this->seeInDatabase('pengguna', ['id_pengguna' => $id, 'deleted_at' => null]);
        }
    }

    public function testFoundationRbacProbeStillWorks(): void
    {
        $this->asRole(Role::PEGAWAI)->get('api/v1/_rbac/super-admin')->assertStatus(403);
        $this->asRole(Role::SUPER_ADMIN)->get('api/v1/_rbac/super-admin')->assertStatus(200);
    }
}
