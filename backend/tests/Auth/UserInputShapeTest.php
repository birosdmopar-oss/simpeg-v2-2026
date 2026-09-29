<?php

declare(strict_types=1);

namespace Tests\Auth;

use App\Constants\Role;
use App\Exceptions\ValidationException;
use App\Libraries\Auth\AuthContext;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;
use Tests\Support\AuthTestTrait;
use Tests\Support\Database\Seeds\AuthSeeder;

/**
 * ISSUE-023/CR-019 — isian body berbentuk array/objek (juga boolean) di CRUD akun (A-08) dan login/lupa password →
 * 422 per field "Isian harus berupa teks.", bukan 500. `permit_empty` CI4 menganggap `[]`/`{}`/`false` kosong sehingga
 * lolos ke controller/service yang meng-cast-nya ("Array to string conversion"); array berisi sudah ditolak StrictRules
 * tetapi dengan pesan bawaan per rule. Nilai yang ditolak tidak membuat/mengubah baris apa pun.
 *
 * @internal
 */
final class UserInputShapeTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use AuthTestTrait;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = null;
    protected $seed      = AuthSeeder::class;

    private const SUPER_ADMIN = '198501012010011001'; // S01
    private const ADMIN_S01   = '198703102012031003'; // role 3, S01
    private const PEGAWAI_S01 = '199002152015022002'; // S01
    private const NEW_NIP     = '200001012024011001';
    private const PASSWORD    = 'AkunBaru2026';
    private const MESSAGE     = ['Isian harus berupa teks.'];

    /**
     * Seluruh field body POST/PUT /auth/users.
     */
    private const FIELDS = ['status', 'id_unit', 'id_satker', 'user_level', 'password', 'nip', 'name', 'email', 'username'];

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
     * Setiap field sendirian (field lain sah) pada POST dan PUT: `[]` lolos `permit_empty` lalu di-cast service
     * (status/id_unit/id_satker → 500 sebelum CR-019); `["1"]` kini berpesan sama untuk semua field.
     */
    public function testEachFieldIsRejectedAloneOnCreateAndUpdate(): void
    {
        $id     = $this->idOf(self::PEGAWAI_S01);
        $before = $this->row($id);

        foreach (['array kosong' => [], 'array berisi' => ['1']] as $label => $value) {
            foreach (self::FIELDS as $field) {
                $body         = $this->validCreateBody();
                $body[$field] = $value;

                $created = $this->asNip(self::SUPER_ADMIN)->withBodyFormat('json')->post('api/v1/auth/users', $body);
                $created->assertStatus(422);
                $this->assertSame([$field => self::MESSAGE], $this->json($created)['errors'], "POST {$field} {$label}");

                $updated = $this->asNip(self::SUPER_ADMIN)->withBodyFormat('json')->put('api/v1/auth/users/' . $id, [$field => $value]);
                $updated->assertStatus(422);
                $this->assertSame([$field => self::MESSAGE], $this->json($updated)['errors'], "PUT {$field} {$label}");
            }
        }

        $this->dontSeeInDatabase('pengguna', ['nip' => self::NEW_NIP]);
        $this->assertSame($before, $this->row($id));
    }

    /**
     * Semua bentuk bukan teks — `[]`, `["1"]`, `{"a":1}` (didekode jadi array asosiatif), `true`, `false` (sebelumnya
     * lolos `permit_empty` diam-diam) — untuk Super Admin dan Admin Satker di POST, PUT, dan PATCH status. Semua field
     * salah dikumpulkan dalam satu 422 (form bisa menandai semuanya); tidak ada akun yang dibuat atau berubah.
     */
    public function testEveryNonTextShapeIsRejectedForEveryActorAndEndpoint(): void
    {
        $id     = $this->idOf(self::PEGAWAI_S01);
        $before = $this->row($id);
        $total  = $this->db->table('pengguna')->countAllResults();
        $all    = array_fill_keys(self::FIELDS, self::MESSAGE);

        foreach ([self::SUPER_ADMIN, self::ADMIN_S01] as $actor) {
            foreach (['array kosong' => [], 'array berisi' => ['1'], 'objek' => ['a' => 1], 'boolean true' => true, 'boolean false' => false] as $label => $value) {
                $body = array_fill_keys(self::FIELDS, $value);

                $created = $this->asNip($actor)->withBodyFormat('json')->post('api/v1/auth/users', $body);
                $created->assertStatus(422);
                $this->assertErrors($all, $created, "{$actor} POST {$label}");

                $updated = $this->asNip($actor)->withBodyFormat('json')->put('api/v1/auth/users/' . $id, $body);
                $updated->assertStatus(422);
                $this->assertErrors($all, $updated, "{$actor} PUT {$label}");

                $status = $this->asNip($actor)->withBodyFormat('json')->patch('api/v1/auth/users/' . $id . '/status', ['status' => $value]);
                $status->assertStatus(422);
                $this->assertSame(['status' => self::MESSAGE], $this->json($status)['errors'], "{$actor} PATCH status {$label}");
            }
        }

        $this->assertSame($total, $this->db->table('pengguna')->countAllResults());
        $this->assertSame($before, $this->row($id));
    }

    /**
     * Nilai sah (angka bulat JSON untuk role, teks untuk status/unit/satker) tetap diterima.
     */
    public function testScalarValuesStillAccepted(): void
    {
        $created = $this->asNip(self::ADMIN_S01)->withBodyFormat('json')->post('api/v1/auth/users', $this->validCreateBody());
        $created->assertStatus(201);
        $id = (int) $this->json($created)['data']['id_pengguna'];
        $this->seeInDatabase('pengguna', ['id_pengguna' => $id, 'user_level' => Role::PEGAWAI, 'id_unit' => 'U01', 'id_satker' => 'S01', 'status' => '1']);

        $this->clearAuthState();
        $this->asNip(self::ADMIN_S01)->withBodyFormat('json')->put('api/v1/auth/users/' . $id, ['user_level' => Role::PTT, 'id_unit' => 'U02', 'status' => '0'])->assertStatus(200);
        $this->seeInDatabase('pengguna', ['id_pengguna' => $id, 'user_level' => Role::PTT, 'id_unit' => 'U02', 'status' => '0']);

        $this->clearAuthState();
        $this->asNip(self::ADMIN_S01)->withBodyFormat('json')->patch('api/v1/auth/users/' . $id . '/status', ['status' => '1'])->assertStatus(200);
        $this->seeInDatabase('pengguna', ['id_pengguna' => $id, 'status' => '1']);
    }

    /**
     * Lapis kedua di UserService (pemanggil selain controller): tanpa ini `(int) ['5']` = 1 membuat akun Super Admin
     * diam-diam, dan status/id_unit/id_satker/password berbentuk array memicu "Array to string conversion".
     */
    public function testUserServiceRejectsNonTextFieldsFromOtherCallers(): void
    {
        $actor = new AuthContext();
        $actor->setClaims($this->claimsForNip(self::SUPER_ADMIN));
        $users = service('userService');

        foreach (['user_level' => ['5'], 'status' => ['0'], 'id_unit' => [], 'id_satker' => ['S02'], 'password' => ['x']] as $field => $value) {
            try {
                $users->create($actor, [$field => $value] + $this->validCreateBody());
                $this->fail("create {$field} harus ditolak");
            } catch (ValidationException $e) {
                $this->assertSame([$field => self::MESSAGE], $e->getErrors(), "create {$field}");
            }

            try {
                $users->update($actor, $this->idOf(self::PEGAWAI_S01), [$field => $value]);
                $this->fail("update {$field} harus ditolak");
            } catch (ValidationException $e) {
                $this->assertSame([$field => self::MESSAGE], $e->getErrors(), "update {$field}");
            }
        }

        $this->dontSeeInDatabase('pengguna', ['nip' => self::NEW_NIP]);
        $this->seeInDatabase('pengguna', ['nip' => self::PEGAWAI_S01, 'user_level' => Role::PEGAWAI, 'status' => '1', 'id_satker' => 'S01']);
    }

    /**
     * Endpoint anonim: `captcha_token: []` lolos `permit_empty|string` lalu di-cast (string) di controller → 500.
     * Ditolak sebelum captcha/kredensial dicek, jadi tidak tercatat di login_attempts/forgot_attempts.
     */
    public function testLoginAndForgotRejectNonTextFields(): void
    {
        $login  = fn (array $body) => $this->withBodyFormat('json')->post('api/v1/auth/login', $body + ['username' => self::PEGAWAI_S01, 'password' => AuthSeeder::PASSWORD, 'captcha_token' => 'ok']);
        $forgot = fn (array $body) => $this->withBodyFormat('json')->post('api/v1/auth/forgot-password', $body + ['username' => self::PEGAWAI_S01, 'captcha_token' => 'ok']);

        foreach ([$login, $forgot] as $post) {
            $result = $post(['captcha_token' => []]);
            $result->assertStatus(422);
            $this->assertSame(['captcha_token' => self::MESSAGE], $this->json($result)['errors']);
        }

        foreach (['array kosong' => [], 'array berisi' => ['1'], 'objek' => ['a' => 1], 'boolean true' => true, 'boolean false' => false] as $label => $value) {
            $result = $login(['username' => $value, 'password' => $value, 'captcha_token' => $value]);
            $result->assertStatus(422);
            $this->assertErrors(['username' => self::MESSAGE, 'password' => self::MESSAGE, 'captcha_token' => self::MESSAGE], $result, "login {$label}");

            $result = $forgot(['username' => $value, 'captcha_token' => $value]);
            $result->assertStatus(422);
            $this->assertErrors(['username' => self::MESSAGE, 'captcha_token' => self::MESSAGE], $result, "forgot {$label}");
        }

        $this->assertSame(0, $this->db->table('login_attempts')->countAllResults());
        $this->assertSame(0, $this->db->table('forgot_attempts')->countAllResults());
    }

    // ------------------------------------------------------------------

    /**
     * Body create yang sah untuk Super Admin maupun Admin Satker S01.
     *
     * @return array<string, mixed>
     */
    private function validCreateBody(): array
    {
        return [
            'nip'        => self::NEW_NIP,
            'name'       => 'Pegawai Baru',
            'email'      => 'pegawai.baru@example.go.id',
            'username'   => self::NEW_NIP,
            'password'   => self::PASSWORD,
            'user_level' => Role::PEGAWAI,
            'id_unit'    => 'U01',
            'id_satker'  => 'S01',
            'status'     => '1',
        ];
    }

    /**
     * errors per field tanpa memedulikan urutan key (urutan mengikuti rules controller).
     *
     * @param array<string, list<string>> $expected
     */
    private function assertErrors(array $expected, TestResponse $result, string $message): void
    {
        $actual = $this->json($result)['errors'] ?? null;
        $this->assertIsArray($actual, $message);
        ksort($expected);
        ksort($actual);
        $this->assertSame($expected, $actual, $message);
    }

    private function idOf(string $nip): int
    {
        return (int) $this->db->table('pengguna')->where('nip', $nip)->get()->getRowArray()['id_pengguna'];
    }

    /**
     * @return array<string, mixed>
     */
    private function row(int $id): array
    {
        /** @var array<string, mixed> $row */
        $row = $this->db->table('pengguna')->where('id_pengguna', $id)->get()->getRowArray();

        return $row;
    }
}
