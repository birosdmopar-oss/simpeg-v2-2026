<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\Role;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Jwt as JwtConfig;

/**
 * F0-06 (JwtAuthFilter) + F0-08 (RoleFilter) — endpoint dummy: role berhak 200, tidak berhak 403.
 * Pola test dari Matriks Role x Endpoint Bagian 3.
 *
 * @internal
 */
final class RbacFilterTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = null;

    protected function setUp(): void
    {
        parent::setUp();
        service('authContext')->clear();
    }

    protected function tearDown(): void
    {
        service('authContext')->clear();
        service('superglobals')->unsetCookie(config(JwtConfig::class)->accessCookie);
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // JwtAuthFilter (401)
    // ------------------------------------------------------------------

    public function testMissingTokenReturns401(): void
    {
        $result = $this->get('api/v1/_rbac/any');

        $result->assertStatus(401);
        $result->assertJSONFragment(['status' => 'error']);
    }

    public function testInvalidTokenReturns401(): void
    {
        $result = $this->withHeaders(['Authorization' => 'Bearer not.a.jwt'])->get('api/v1/_rbac/any');

        $result->assertStatus(401);
    }

    public function testExpiredTokenReturns401(): void
    {
        $jwt = service('jwt');
        $jwt->setNow(time() - 3601);
        $token = $jwt->issueAccessToken(['sub' => '1', 'role' => Role::SUPER_ADMIN]);
        $jwt->setNow(null);

        $this->withHeaders(['Authorization' => 'Bearer ' . $token])->get('api/v1/_rbac/any')->assertStatus(401);
    }

    public function testValidTokenViaHttpOnlyCookieReturns200(): void
    {
        $token = service('jwt')->issueAccessToken(['sub' => '198501012010011001', 'role' => Role::PEGAWAI]);

        // Simulasi browser mengirim cookie httpOnly (CI4 membaca cookie lewat service superglobals).
        service('superglobals')->setCookie(config(JwtConfig::class)->accessCookie, $token);

        $result = $this->get('api/v1/_rbac/any');

        $result->assertStatus(200);
        $json = json_decode((string) $result->getJSON(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('198501012010011001', $json['data']['nip']);
        $this->assertSame(Role::PEGAWAI, $json['data']['role']);
    }

    public function testValidTokenViaBearerHeaderReturns200(): void
    {
        $this->asRole(Role::PTT)->get('api/v1/_rbac/any')->assertStatus(200);
    }

    // ------------------------------------------------------------------
    // RoleFilter (403 / 200) — pola Matriks Role x Endpoint Bagian 3
    // ------------------------------------------------------------------

    /**
     * @return iterable<string, array{string, string, list<int>}>
     */
    public static function patternProvider(): iterable
    {
        yield 'Admin CRUD (1,3)' => ['GET', 'api/v1/_rbac/admin-crud', [Role::SUPER_ADMIN, Role::ADMIN_SATKER]];

        yield 'Pegawai View (1,2,3,4,5)' => ['GET', 'api/v1/_rbac/admin-view', [Role::SUPER_ADMIN, Role::PEGAWAI, Role::ADMIN_SATKER, Role::ADMIN_VIEW_ESELON1, Role::MENTERI]];

        yield 'Self-service submit (1,2,3,6,7)' => ['POST', 'api/v1/_rbac/self-service', [Role::SUPER_ADMIN, Role::PEGAWAI, Role::ADMIN_SATKER, Role::PTT, Role::PPPK]];

        yield 'View-only leadership (1,3,4,5,8)' => ['GET', 'api/v1/_rbac/leadership', [Role::SUPER_ADMIN, Role::ADMIN_SATKER, Role::ADMIN_VIEW_ESELON1, Role::MENTERI, Role::PIMPINAN]];

        yield 'Super Admin only (1)' => ['GET', 'api/v1/_rbac/super-admin', [Role::SUPER_ADMIN]];
    }

    /**
     * @dataProvider patternProvider
     *
     * @param list<int> $allowed
     */
    public function testEveryRoleGetsExpectedStatus(string $method, string $path, array $allowed): void
    {
        foreach (Role::all() as $role) {
            $expected = in_array($role, $allowed, true) ? 200 : 403;

            $result = $this->asRole($role)->call(strtolower($method), $path);

            $this->assertSame(
                $expected,
                $result->response()->getStatusCode(),
                sprintf('%s %s role %d (%s) harus %d', $method, $path, $role, Role::label($role), $expected),
            );

            $json = json_decode((string) $result->getJSON(), true, 512, JSON_THROW_ON_ERROR);

            if ($expected === 403) {
                $this->assertSame(['status' => 'error', 'message' => 'Forbidden'], $json);
            } else {
                $this->assertSame('success', $json['status']);
                $this->assertSame($role, $json['data']['role']);
            }
        }
    }

    public function testUnknownRoleValueIsForbidden(): void
    {
        $this->asRole(99)->get('api/v1/_rbac/admin-view')->assertStatus(403);
    }

    /**
     * @return $this
     */
    private function asRole(int $role): self
    {
        $token = service('jwt')->issueAccessToken(['sub' => '19850101201001' . str_pad((string) $role, 4, '0', STR_PAD_LEFT), 'role' => $role]);

        return $this->withHeaders(['Authorization' => 'Bearer ' . $token]);
    }
}
