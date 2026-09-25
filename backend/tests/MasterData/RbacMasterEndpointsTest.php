<?php

declare(strict_types=1);

namespace Tests\MasterData;

use App\Constants\Role;
use App\Libraries\MasterData\MasterDefinition;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\MasterData as MasterDataConfig;
use Tests\Support\AuthTestTrait;
use Tests\Support\Database\Seeds\MasterDataSeeder;
use Tests\Support\MasterDataTestTrait;

/**
 * G-TC #5 — RBAC Modul G (Matriks Role x Endpoint Bagian 2 Modul G, pola "Super Admin only").
 *
 * - Seluruh endpoint CRUD master + master/meta: role 1 saja; role 2-8 → 403, tanpa token → 401.
 * - master/{entity}/options (dropdown read-only, entri aktif): UL_ALL (8 role), tanpa token → 401 — kecuali master
 *   ber-`publicOptions = false` (FAQ, CR-003): role 1 saja, role 2-8 → 403.
 *
 * @internal
 */
final class RbacMasterEndpointsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use AuthTestTrait;
    use MasterDataTestTrait;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = null;
    protected $seed      = MasterDataSeeder::class;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clearAuthState();
        $this->resetMasterState();
    }

    protected function tearDown(): void
    {
        $this->clearAuthState();
        parent::tearDown();
    }

    /**
     * @return list<array{0: string, 1: string, 2: array<string, mixed>}> [method, uri, body]
     */
    private function crudCalls(string $entity, string $existing): array
    {
        return [
            ['GET', "api/v1/master/{$entity}", []],
            ['POST', "api/v1/master/{$entity}", []],
            ['GET', "api/v1/master/{$entity}/{$existing}", []],
            ['PUT', "api/v1/master/{$entity}/{$existing}", []],
            ['PATCH', "api/v1/master/{$entity}/{$existing}/status", ['status' => '2']],
            ['PATCH', "api/v1/master/{$entity}/{$existing}/order", ['order' => 1]],
            ['DELETE', "api/v1/master/{$entity}/{$existing}", []],
        ];
    }

    public function testCrudEndpointsAreSuperAdminOnly(): void
    {
        $nonAdmin = array_values(array_diff(Role::all(), [Role::SUPER_ADMIN]));

        foreach (self::masterFixtures() as $entity => $fx) {
            foreach ($this->crudCalls($entity, $fx['existing']) as [$method, $uri, $body]) {
                foreach ($nonAdmin as $role) {
                    $result = $this->asRole($role)->sendJson($method, $uri, $body);
                    $result->assertStatus(403);
                    $this->assertSame(['status' => 'error', 'message' => 'Forbidden'], $this->json($result), "{$method} {$uri} role {$role}");
                }

                $this->withHeaders(['Authorization' => ''])->sendJson($method, $uri, $body)->assertStatus(401);
            }
        }

        // Tidak ada perubahan data oleh role yang ditolak.
        $this->seeInDatabase('agama', ['id_agama' => '6', 'status' => '1', 'order' => 6]);
        $this->dontSeeInDatabase('audit_logs', ['entity' => 'agama']);

        foreach ($nonAdmin as $role) {
            $this->asRole($role)->get('api/v1/master/meta')->assertStatus(403);
        }
    }

    public function testSuperAdminPassesFilterOnEveryCrudEndpoint(): void
    {
        foreach (self::masterFixtures() as $entity => $fx) {
            $this->asRole(Role::SUPER_ADMIN);
            $def = service('masterRegistry')->get($entity);

            $this->get("api/v1/master/{$entity}")->assertStatus(200);
            $this->sendJson('POST', "api/v1/master/{$entity}", $fx['new'])->assertStatus(201);
            $this->get("api/v1/master/{$entity}/{$fx['existing']}")->assertStatus(200);
            $this->sendJson('PUT', "api/v1/master/{$entity}/{$fx['existing']}", [$def->nameField => 'Nama Uji ' . $entity])->assertStatus(200);
            $this->sendJson('PATCH', "api/v1/master/{$entity}/{$fx['existing']}/order", ['order' => 1])->assertStatus(200);
            $this->sendJson('PATCH', "api/v1/master/{$entity}/{$fx['existing']}/status", ['status' => '2'])->assertStatus(200);
            $this->delete("api/v1/master/{$entity}/{$fx['existing']}")->assertStatus(200);
        }

        $meta = $this->json($this->get('api/v1/master/meta'))['data'];
        $this->assertSame(array_keys(self::masterFixtures()), array_column($meta, 'key'));
    }

    public function testOptionsAreOpenToEveryLoggedInRoleExceptAdminOnlyMasters(): void
    {
        // Master tanpa konsumen dropdown di luar form admin (publicOptions = false). Daftarnya dibaca dari
        // Config\MasterData (CR-009), bukan daftar tetap: grup DBV berikutnya cukup mengatur publicOptions di entrinya.
        $adminOnly = array_keys(array_filter(
            config(MasterDataConfig::class)->entities,
            static fn (array $entity): bool => ($entity['publicOptions'] ?? true) === false,
        ));

        // Registry (dipakai routing & service) harus sepakat dengan config, dan master FAQ tetap role 1 (CR-003).
        $this->assertSame($adminOnly, array_keys(array_filter(
            service('masterRegistry')->all(),
            static fn (MasterDefinition $def): bool => ! $def->publicOptions,
        )));

        foreach (['faq-topic', 'faq-sub-topic', 'faq-article'] as $faq) {
            $this->assertContains($faq, $adminOnly, "{$faq} wajib publicOptions false (CR-003)");
        }

        foreach (Role::all() as $role) {
            $this->asRole($role);

            foreach (self::masterFixtures() as $entity => $fx) {
                $parent = $fx['parent'][1] ?? null;

                if ($role === Role::SUPER_ADMIN || ! in_array($entity, $adminOnly, true)) {
                    $this->assertNotSame([], $this->optionIds($entity, $parent), "{$entity} role {$role}");

                    continue;
                }

                $result = $this->get("api/v1/master/{$entity}/options", $parent !== null ? ['parent' => $parent] : []);
                $result->assertStatus(403);
                $this->assertSame(['status' => 'error', 'message' => 'Forbidden'], $this->json($result), "{$entity} role {$role}");
            }
        }

        foreach (array_keys(self::masterFixtures()) as $entity) {
            $this->withHeaders(['Authorization' => ''])->get("api/v1/master/{$entity}/options")->assertStatus(401);
        }
    }
}
