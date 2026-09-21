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
 * A-08 — CRUD akun scoped (MTC-004): role 1 semua akun; role 3 hanya satkernya (di luar → 403); role lain 403.
 * A-10 — perubahan akun tercatat di audit_logs dengan actor.
 *
 * @internal
 */
final class UserCrudScopedTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use AuthTestTrait;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = null;
    protected $seed      = AuthSeeder::class;

    private const SUPER_ADMIN  = '198501012010011001'; // S01
    private const ADMIN_S01    = '198703102012031003'; // role 3, S01
    private const PEGAWAI_S01  = '199002152015022002'; // S01
    private const PEGAWAI_S02  = '199505052018052006'; // S02 (PTT)
    private const NEW_NIP      = '200001012024011001';
    private const NEW_PASSWORD = 'AkunBaru2026';

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

    private function idOf(string $nip): int
    {
        return (int) $this->db->table('pengguna')->where('nip', $nip)->get()->getRowArray()['id_pengguna'];
    }

    // ------------------------------------------------------------------
    // Role 1: CRUD semua akun (MTC-004)
    // ------------------------------------------------------------------

    public function testSuperAdminCanCreateAccountThatCanLoginImmediately(): void
    {
        $result = $this->asNip(self::SUPER_ADMIN)->withBodyFormat('json')->post('api/v1/auth/users', [
            'nip'        => self::NEW_NIP,
            'password'   => self::NEW_PASSWORD,
            'user_level' => Role::PEGAWAI,
            'id_unit'    => 'U01',
            'id_satker'  => 'S02',
        ]);

        $result->assertStatus(201);
        $json = $this->json($result);
        $this->assertSame(self::NEW_NIP, $json['data']['username'], 'username default = NIP');
        $this->assertSame('S02', $json['data']['id_satker']);
        $this->assertArrayNotHasKey('password', $json['data']);

        // Akun baru langsung bisa login; password disimpan Argon2id.
        $this->clearAuthState();
        $this->login(self::NEW_NIP, self::NEW_PASSWORD)->assertStatus(200);
        $this->assertStringStartsWith('$argon2id$', (string) $this->db->table('pengguna')->where('nip', self::NEW_NIP)->get()->getRowArray()['password']);

        // Audit create dengan actor Super Admin dan hash dimasking.
        $log = $this->db->table('audit_logs')->where('entity', 'pengguna')->where('event', 'create')->where('entity_id', (string) $json['data']['id_pengguna'])->get()->getRowArray();
        $this->assertNotNull($log);
        $this->assertSame(self::SUPER_ADMIN, $log['nip_actor']);
        $this->assertSame('***', json_decode((string) $log['after_json'], true)['password']);
    }

    public function testSuperAdminListSearchesAndSortsAllAccounts(): void
    {
        $all = $this->json($this->asNip(self::SUPER_ADMIN)->get('api/v1/auth/users?per_page=100'));
        $this->assertSame(14, $all['data']['total']);

        $search = $this->json($this->asNip(self::SUPER_ADMIN)->get('api/v1/auth/users?search=1995050520'));
        $this->assertSame(1, $search['data']['total']);
        $this->assertSame(self::PEGAWAI_S02, $search['data']['items'][0]['nip']);

        $sorted = $this->json($this->asNip(self::SUPER_ADMIN)->get('api/v1/auth/users?sort=nip&order=desc&per_page=2'));
        $this->assertGreaterThanOrEqual($sorted['data']['items'][1]['nip'], $sorted['data']['items'][0]['nip']);
    }

    public function testSuperAdminUpdateRoleAndStatusRevokesSessionsAndBlocksLogin(): void
    {
        $id      = $this->idOf(self::PEGAWAI_S01);
        $session = $this->issueTokensFor(self::PEGAWAI_S01);

        $update = $this->asNip(self::SUPER_ADMIN)->withBodyFormat('json')->put('api/v1/auth/users/' . $id, ['user_level' => Role::ADMIN_SATKER]);
        $update->assertStatus(200);
        $this->assertSame(Role::ADMIN_SATKER, $this->json($update)['data']['user_level']);

        // Permission langsung ter-refresh: sesi lama dicabut (refresh token tidak berlaku).
        $this->clearAuthState();
        $this->setRefreshCookie($session['refresh_token']);
        $this->post('api/v1/auth/refresh')->assertStatus(401);

        // Nonaktifkan → tidak bisa login lagi.
        $this->clearAuthState();
        $this->asNip(self::SUPER_ADMIN)->withBodyFormat('json')->patch('api/v1/auth/users/' . $id . '/status', ['status' => '0'])->assertStatus(200);
        $this->clearAuthState();
        $this->login(self::PEGAWAI_S01, AuthSeeder::PASSWORD)->assertStatus(401);

        // Audit update dengan actor.
        $this->seeInDatabase('audit_logs', ['entity' => 'pengguna', 'event' => 'update', 'entity_id' => (string) $id, 'nip_actor' => self::SUPER_ADMIN]);
    }

    public function testEachAccountChangeIsAuditedWithBeforeAfterAndActor(): void
    {
        $id     = $this->idOf(self::PEGAWAI_S01);
        $audits = fn (): array => $this->db->table('audit_logs')
            ->where(['entity' => 'pengguna', 'event' => 'update', 'entity_id' => (string) $id])
            ->orderBy('id_log')->get()->getResultArray();
        $json = static fn (?string $raw): array => json_decode((string) $raw, true) ?? [];

        // PUT oleh role 1: satu record, before/after mencerminkan perubahan role.
        $this->asNip(self::SUPER_ADMIN)->withBodyFormat('json')->put('api/v1/auth/users/' . $id, ['user_level' => Role::PPPK])->assertStatus(200);
        $rows = $audits();
        $this->assertCount(1, $rows);
        $this->assertSame(self::SUPER_ADMIN, $rows[0]['nip_actor']);
        $this->assertSame(Role::PEGAWAI, (int) $json($rows[0]['before_json'])['user_level']);
        $this->assertSame(Role::PPPK, (int) $json($rows[0]['after_json'])['user_level']);
        $this->assertNotEmpty($rows[0]['created_at']);

        // PATCH status: record terpisah.
        $this->clearAuthState();
        $this->asNip(self::SUPER_ADMIN)->withBodyFormat('json')->patch('api/v1/auth/users/' . $id . '/status', ['status' => '0'])->assertStatus(200);
        $rows = $audits();
        $this->assertCount(2, $rows);
        $this->assertSame('1', (string) $json($rows[1]['before_json'])['status']);
        $this->assertSame('0', (string) $json($rows[1]['after_json'])['status']);

        // Perubahan oleh role 3 (satker sendiri) tercatat dengan actor admin satker.
        $this->clearAuthState();
        $this->asNip(self::ADMIN_S01)->withBodyFormat('json')->put('api/v1/auth/users/' . $id, ['user_level' => Role::PEGAWAI])->assertStatus(200);
        $rows = $audits();
        $this->assertCount(3, $rows);
        $this->assertSame(self::ADMIN_S01, $rows[2]['nip_actor']);
    }

    public function testSuperAdminDeleteSoftDeletesAndRevokesSessions(): void
    {
        $id = $this->idOf(self::PEGAWAI_S02);
        $this->issueTokensFor(self::PEGAWAI_S02);

        $this->asNip(self::SUPER_ADMIN)->delete('api/v1/auth/users/' . $id)->assertStatus(200);

        $row = $this->db->table('pengguna')->where('id_pengguna', $id)->get()->getRowArray();
        $this->assertNotNull($row['deleted_at'], 'Soft delete');
        $this->assertSame(0, $this->db->table('token')->where('nip', self::PEGAWAI_S02)->where('revoked', 0)->countAllResults());
        $this->seeInDatabase('audit_logs', ['entity' => 'pengguna', 'event' => 'delete', 'entity_id' => (string) $id, 'nip_actor' => self::SUPER_ADMIN]);

        $this->clearAuthState();
        $this->login(self::PEGAWAI_S02, AuthSeeder::PASSWORD)->assertStatus(401);
        $this->asNip(self::SUPER_ADMIN)->get('api/v1/auth/users/' . $id)->assertStatus(404);
    }

    public function testCannotDeleteOwnAccountAndValidationErrors(): void
    {
        $this->asNip(self::SUPER_ADMIN)->delete('api/v1/auth/users/' . $this->idOf(self::SUPER_ADMIN))->assertStatus(422);

        $bad = $this->asNip(self::SUPER_ADMIN)->withBodyFormat('json')->post('api/v1/auth/users', [
            'nip' => '123', 'password' => 'lemah', 'user_level' => 9,
        ]);
        $bad->assertStatus(422);
        $errors = $this->json($bad)['errors'];
        $this->assertArrayHasKey('nip', $errors);
        $this->assertArrayHasKey('password', $errors);
        $this->assertArrayHasKey('user_level', $errors);

        $dup = $this->asNip(self::SUPER_ADMIN)->withBodyFormat('json')->post('api/v1/auth/users', [
            'nip' => self::PEGAWAI_S01, 'password' => self::NEW_PASSWORD, 'user_level' => 2,
        ]);
        $dup->assertStatus(422);
        $this->assertArrayHasKey('nip', $this->json($dup)['errors']);
    }

    // ------------------------------------------------------------------
    // Role 3: scoped ke satker sendiri (MTC-004 langkah 4)
    // ------------------------------------------------------------------

    public function testAdminSatkerOnlySeesOwnSatkerInList(): void
    {
        $json = $this->json($this->asNip(self::ADMIN_S01)->get('api/v1/auth/users?per_page=100'));

        $this->assertGreaterThan(0, $json['data']['total']);

        foreach ($json['data']['items'] as $item) {
            $this->assertSame('S01', $item['id_satker'], 'Admin Satker S01 tidak boleh melihat akun satker lain');
        }

        $this->assertNotContains(self::PEGAWAI_S02, array_column($json['data']['items'], 'nip'));

        // Filter id_satker milik role 1 diabaikan untuk role 3.
        $json2 = $this->json($this->asNip(self::ADMIN_S01)->get('api/v1/auth/users?id_satker=S02&per_page=100'));
        $this->assertSame($json['data']['total'], $json2['data']['total']);
    }

    public function testAdminSatkerCannotAccessAccountOutsideOwnSatker(): void
    {
        $otherId = $this->idOf(self::PEGAWAI_S02);
        $admin   = fn () => $this->asNip(self::ADMIN_S01);

        $admin()->get('api/v1/auth/users/' . $otherId)->assertStatus(403);
        $admin()->withBodyFormat('json')->put('api/v1/auth/users/' . $otherId, ['status' => '0'])->assertStatus(403);
        $admin()->withBodyFormat('json')->patch('api/v1/auth/users/' . $otherId . '/status', ['status' => '0'])->assertStatus(403);
        $admin()->delete('api/v1/auth/users/' . $otherId)->assertStatus(403);

        // Data tidak berubah.
        $this->seeInDatabase('pengguna', ['id_pengguna' => $otherId, 'status' => '1', 'deleted_at' => null]);
    }

    public function testAdminSatkerCanManageAccountsInOwnSatker(): void
    {
        $ownId = $this->idOf(self::PEGAWAI_S01);
        $admin = fn () => $this->asNip(self::ADMIN_S01);

        $admin()->get('api/v1/auth/users/' . $ownId)->assertStatus(200);
        $admin()->withBodyFormat('json')->put('api/v1/auth/users/' . $ownId, ['user_level' => Role::PPPK])->assertStatus(200);
        $this->seeInDatabase('pengguna', ['id_pengguna' => $ownId, 'user_level' => Role::PPPK]);

        // Create: satker dipaksa ke satker admin; satker lain → 403.
        $created = $admin()->withBodyFormat('json')->post('api/v1/auth/users', [
            'nip' => self::NEW_NIP, 'password' => self::NEW_PASSWORD, 'user_level' => Role::PEGAWAI,
        ]);
        $created->assertStatus(201);
        $this->assertSame('S01', $this->json($created)['data']['id_satker']);

        $admin()->withBodyFormat('json')->post('api/v1/auth/users', [
            'nip' => '200001012024011002', 'password' => self::NEW_PASSWORD, 'user_level' => Role::PEGAWAI, 'id_satker' => 'S02',
        ])->assertStatus(403);

        // Tidak boleh membuat/memberi role Super Admin (asumsi keamanan).
        $admin()->withBodyFormat('json')->post('api/v1/auth/users', [
            'nip' => '200001012024011003', 'password' => self::NEW_PASSWORD, 'user_level' => Role::SUPER_ADMIN,
        ])->assertStatus(403);
        $admin()->withBodyFormat('json')->put('api/v1/auth/users/' . $ownId, ['user_level' => Role::SUPER_ADMIN])->assertStatus(403);
        $admin()->withBodyFormat('json')->put('api/v1/auth/users/' . $ownId, ['id_satker' => 'S02'])->assertStatus(403);
    }

    // ------------------------------------------------------------------
    // Role lain: 403 di semua operasi
    // ------------------------------------------------------------------

    /**
     * @return iterable<string, array{int}>
     */
    public static function nonAdminRoles(): iterable
    {
        foreach ([Role::PEGAWAI, Role::ADMIN_VIEW_ESELON1, Role::MENTERI, Role::PTT, Role::PPPK, Role::PIMPINAN] as $role) {
            yield Role::label($role) => [$role];
        }
    }

    /**
     * @dataProvider nonAdminRoles
     */
    public function testOtherRolesGet403OnEveryOperation(int $role): void
    {
        $id = $this->idOf(self::PEGAWAI_S01);

        $this->asRole($role)->get('api/v1/auth/users')->assertStatus(403);
        $this->asRole($role)->get('api/v1/auth/users/' . $id)->assertStatus(403);
        $this->asRole($role)->withBodyFormat('json')->post('api/v1/auth/users', ['nip' => self::NEW_NIP, 'password' => self::NEW_PASSWORD, 'user_level' => 2])->assertStatus(403);
        $this->asRole($role)->withBodyFormat('json')->put('api/v1/auth/users/' . $id, ['status' => '0'])->assertStatus(403);
        $this->asRole($role)->withBodyFormat('json')->patch('api/v1/auth/users/' . $id . '/status', ['status' => '0'])->assertStatus(403);
        $this->asRole($role)->delete('api/v1/auth/users/' . $id)->assertStatus(403);

        $this->dontSeeInDatabase('pengguna', ['nip' => self::NEW_NIP]);
        $this->seeInDatabase('pengguna', ['id_pengguna' => $id, 'status' => '1', 'deleted_at' => null]);
    }

    public function testUnauthenticatedGets401(): void
    {
        $this->get('api/v1/auth/users')->assertStatus(401);
    }
}
