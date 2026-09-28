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
 * DBV-010/CR-013 — aturan akun non-pegawai: NIP wajib role 2/6/7, opsional role 1/3/4/5/8 (nama wajib bila tanpa NIP),
 * NIP angka maks. 18 digit, email opsional, username default NIP / wajib bila NIP kosong / maks. 100, NIP hanya bisa
 * diisi untuk akun yang belum punya NIP.
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
        $this->bersihkanDataDbv010();
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
        $this->assertSame(0, $this->db->table('token')->where('id_pengguna', $id)->where('revoked', 0)->countAllResults());
        $this->seeInDatabase('audit_logs', ['entity' => 'pengguna', 'event' => 'delete', 'entity_id' => (string) $id, 'nip_actor' => self::SUPER_ADMIN]);

        $this->clearAuthState();
        $this->login(self::PEGAWAI_S02, AuthSeeder::PASSWORD)->assertStatus(401);
        $this->asNip(self::SUPER_ADMIN)->get('api/v1/auth/users/' . $id)->assertStatus(404);
    }

    public function testCannotDeleteOwnAccountAndValidationErrors(): void
    {
        $this->asNip(self::SUPER_ADMIN)->delete('api/v1/auth/users/' . $this->idOf(self::SUPER_ADMIN))->assertStatus(422);

        $bad = $this->asNip(self::SUPER_ADMIN)->withBodyFormat('json')->post('api/v1/auth/users', [
            'nip' => 'ABC123', 'password' => 'lemah', 'user_level' => 9,
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
    // DBV-010/CR-013: akun tanpa NIP (K2)
    // ------------------------------------------------------------------

    public function testCreateAppliesNipRulesPerRole(): void
    {
        $post = fn (array $body) => $this->asNip(self::SUPER_ADMIN)->withBodyFormat('json')->post('api/v1/auth/users', $body + ['password' => self::NEW_PASSWORD]);

        // Role 2/6/7 wajib NIP.
        foreach ([Role::PEGAWAI, Role::PTT, Role::PPPK] as $role) {
            $result = $post(['user_level' => $role, 'name' => 'Tanpa NIP', 'username' => 'tanpa.nip.' . $role]);
            $result->assertStatus(422);
            $this->assertSame(['NIP wajib diisi untuk role Pegawai/PTT/PPPK.'], $this->json($result)['errors']['nip'], "role {$role}");
        }

        // Role 1/3/4/5/8 tanpa NIP: nama dan username wajib.
        $missing = $post(['user_level' => Role::MENTERI, 'nip' => null]);
        $missing->assertStatus(422);
        $this->assertSame(['Nama wajib diisi untuk akun tanpa NIP.'], $this->json($missing)['errors']['name']);
        $this->assertArrayHasKey('username', $this->json($missing)['errors']);
        $this->assertArrayNotHasKey('nip', $this->json($missing)['errors']);

        $created = $post(['user_level' => Role::MENTERI, 'nip' => '', 'name' => 'Menteri Pariwisata', 'username' => 'menteri', 'email' => 'menteri@example.go.id']);
        $created->assertStatus(201);
        $data = $this->json($created)['data'];
        $this->assertNull($data['nip']);
        $this->assertSame('Menteri Pariwisata', $data['name']);
        $this->assertSame('menteri@example.go.id', $data['email']);
        $this->seeInDatabase('pengguna', ['id_pengguna' => $data['id_pengguna'], 'nip' => null, 'username' => 'menteri']);

        // NIP ikut legacy: angka maks. 18 digit (NIK 16 digit Non-PNS diterima).
        $post(['user_level' => Role::PTT, 'nip' => '3171012345678901'])->assertStatus(201);
        $this->seeInDatabase('pengguna', ['nip' => '3171012345678901', 'username' => '3171012345678901']);

        foreach (['1234567890123456789', '19900215A0150220', '1990-0215'] as $nip) {
            $bad = $post(['user_level' => Role::PEGAWAI, 'nip' => $nip]);
            $bad->assertStatus(422);
            $this->assertSame(['NIP harus berupa angka, maksimal 18 digit.'], $this->json($bad)['errors']['nip'], $nip);
        }

        // Email opsional tetapi harus valid; nama maks. 150.
        $this->assertArrayHasKey('email', $this->json($post(['user_level' => Role::PIMPINAN, 'name' => 'X', 'username' => 'pimpinan.x', 'email' => 'bukan-email']))['errors']);
        $this->assertArrayHasKey('name', $this->json($post(['user_level' => Role::PIMPINAN, 'name' => str_repeat('n', 151), 'username' => 'pimpinan.y']))['errors']);

        // Username maks. 100 dan unik dalam collation unicode_ci ('straße' = 'strasse').
        $post(['user_level' => Role::ADMIN_VIEW_ESELON1, 'name' => 'Seratus', 'username' => str_repeat('u', 100)])->assertStatus(201);
        $this->assertArrayHasKey('username', $this->json($post(['user_level' => Role::ADMIN_VIEW_ESELON1, 'name' => 'X', 'username' => str_repeat('u', 101)]))['errors']);

        $post(['user_level' => Role::PIMPINAN, 'name' => 'Strasse', 'username' => 'strasse'])->assertStatus(201);
        $dup = $post(['user_level' => Role::PIMPINAN, 'name' => 'Straße', 'username' => 'straße']);
        $dup->assertStatus(422);
        $this->assertSame(['Username sudah dipakai.'], $this->json($dup)['errors']['username']);
    }

    public function testUpdateOnlyLinksNipToAccountWithoutNip(): void
    {
        $akun    = $this->buatAkunTanpaNip('admin.view', Role::ADMIN_VIEW_ESELON1);
        $id      = (int) $akun['id_pengguna'];
        $session = $this->issueTokensForUser($akun);
        $put     = fn (int $target, array $body) => $this->asNip(self::SUPER_ADMIN)->withBodyFormat('json')->put('api/v1/auth/users/' . $target, $body);

        // Role → 2 tanpa NIP ditolak; mengosongkan nama akun tanpa NIP ditolak.
        $this->assertArrayHasKey('nip', $this->json($put($id, ['user_level' => Role::PEGAWAI]))['errors']);
        $this->assertArrayHasKey('name', $this->json($put($id, ['name' => '']))['errors']);

        // NIP yang sudah dipakai akun lain ditolak.
        $this->assertSame(['NIP sudah memiliki akun.'], $this->json($put($id, ['nip' => self::PEGAWAI_S01]))['errors']['nip']);

        // Mengisi NIP (menautkan ke pegawai) + ubah nama/email → sesi akun dicabut (claims memuat nip).
        $linked = $put($id, ['nip' => self::NEW_NIP, 'name' => 'Nama Baru', 'email' => 'baru@example.go.id']);
        $linked->assertStatus(200);
        $this->assertSame(self::NEW_NIP, $this->json($linked)['data']['nip']);
        $this->seeInDatabase('pengguna', ['id_pengguna' => $id, 'nip' => self::NEW_NIP, 'name' => 'Nama Baru', 'email' => 'baru@example.go.id']);
        $this->assertSame(0, $this->db->table('token')->where('id_pengguna', $id)->countAllResults());

        $this->clearAuthState();
        $this->setRefreshCookie($session['refresh_token']);
        $this->post('api/v1/auth/refresh')->assertStatus(401);

        // NIP yang sudah ada tidak bisa diubah/dihapus di sini (B-06); mengirim NIP yang sama = tanpa perubahan.
        $this->clearAuthState();
        $pegawai = $this->idOf(self::PEGAWAI_S01);

        foreach (['200001012024011099', null, ''] as $nip) {
            $this->assertSame(['NIP tidak dapat diubah di sini; gunakan fitur ganti NIP (B-06).'], $this->json($put($pegawai, ['nip' => $nip]))['errors']['nip']);
        }

        $put($pegawai, ['nip' => self::PEGAWAI_S01, 'name' => 'Siti'])->assertStatus(200);
        $this->seeInDatabase('pengguna', ['id_pengguna' => $pegawai, 'nip' => self::PEGAWAI_S01, 'name' => 'Siti']);
    }

    public function testListSearchesAndSortsByName(): void
    {
        $this->buatAkunTanpaNip('budi', Role::MENTERI, ['name' => 'Budi Santoso']);

        $found = $this->json($this->asNip(self::SUPER_ADMIN)->get('api/v1/auth/users?search=Santoso'));
        $this->assertSame(1, $found['data']['total']);
        $this->assertNull($found['data']['items'][0]['nip']);
        $this->assertSame('Budi Santoso', $found['data']['items'][0]['name']);

        $sorted = $this->json($this->asNip(self::SUPER_ADMIN)->get('api/v1/auth/users?sort=name&order=desc&per_page=1'));
        $this->assertSame('Budi Santoso', $sorted['data']['items'][0]['name'], 'akun seed ber-NIP tanpa nama (NULL) berada di akhir urutan desc');
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
