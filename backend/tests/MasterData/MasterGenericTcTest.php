<?php

declare(strict_types=1);

namespace Tests\MasterData;

use App\Constants\Role;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\AuthTestTrait;
use Tests\Support\Database\Seeds\MasterDataSeeder;
use Tests\Support\MasterDataTestTrait;
use Throwable;

/**
 * G-TC — test case generik Modul G (02-MasterData.md), dijalankan ke SELURUH master yang terdaftar di engine
 * (saat ini G-07: agama, jenis_pegawai, jenis_status, wilayah 4 level). MTC-008 (pola CRUD master).
 *
 * Tiap test mengulang skenario untuk semua master sekaligus agar biaya migrate:refresh per test tetap kecil.
 * G-TC #5 (RBAC) ada di RbacMasterEndpointsTest; G-TC #7 (QA visual) di luar cakupan test otomatis.
 *
 * @internal
 */
final class MasterGenericTcTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use AuthTestTrait;
    use MasterDataTestTrait;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = null;
    protected $seed      = MasterDataSeeder::class;

    private const ADMIN_NIP = '198501012010011001';

    protected function setUp(): void
    {
        parent::setUp();
        $this->clearAuthState();
        $this->resetMasterState();
        $this->asRole(Role::SUPER_ADMIN);
    }

    protected function tearDown(): void
    {
        $this->clearAuthState();
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // G-TC #1 — keunikan nama/kode
    // ------------------------------------------------------------------

    public function testCreateSucceedsAndDuplicateCodeOrNameIsRejected(): void
    {
        foreach (self::masterFixtures() as $entity => $fx) {
            $def  = service('masterRegistry')->get($entity);
            $base = "api/v1/master/{$entity}";

            $created = $this->sendJson('POST', $base, $fx['new']);
            $created->assertStatus(201);
            $row = $this->json($created)['data'];
            $this->assertSame($fx['new'][$def->nameField], $row[$def->nameField], $entity);
            $this->assertSame('1', (string) $row['status'], $entity);

            // Kode duplikat
            $dupCode = $fx['new'];
            $dupCode[$def->nameField] .= ' Lain';
            $result = $this->sendJson('POST', $base, $dupCode);
            $result->assertStatus(422);
            $this->assertArrayHasKey($def->primaryKey, $this->json($result)['errors'], $entity);

            // Nama duplikat (case-insensitive) di induk yang sama
            $dupName                   = $fx['new'];
            $dupName[$def->primaryKey] = 'X' . substr($fx['new'][$def->primaryKey], 1);
            $dupName[$def->nameField]  = $fx['duplicate'];
            $result                    = $this->sendJson('POST', $base, $dupName);
            $result->assertStatus(422);
            $this->assertArrayHasKey($def->nameField, $this->json($result)['errors'], $entity);

            // Nama duplikat lewat update
            $result = $this->sendJson('PUT', "{$base}/{$fx['new'][$def->primaryKey]}", [$def->nameField => $fx['duplicate']]);
            $result->assertStatus(422);
        }
    }

    public function testSameNameIsAllowedUnderDifferentParent(): void
    {
        // "Gambir" sudah ada di kecamatan 317101; boleh dipakai di kecamatan lain.
        $this->sendJson('POST', 'api/v1/master/kelurahan', [
            'id_kelurahan'   => '3273011002',
            'id_kecamatan'   => '327301',
            'nama_kelurahan' => 'Gambir',
        ])->assertStatus(201);

        // Tapi tidak di induk yang sama.
        $this->sendJson('POST', 'api/v1/master/kelurahan', [
            'id_kelurahan'   => '3171011009',
            'id_kecamatan'   => '317101',
            'nama_kelurahan' => '  gambir ',
        ])->assertStatus(422);
    }

    public function testParentMustExistAndBeActiveAndCodeIsImmutable(): void
    {
        $this->sendJson('POST', 'api/v1/master/kecamatan', [
            'id_kecamatan' => '999901', 'id_kabupaten_kota' => '9999', 'nama_kecamatan' => 'Tanpa Induk',
        ])->assertStatus(422);

        $this->sendJson('PATCH', 'api/v1/master/kabupaten-kota/3172/status', ['status' => '0'])->assertStatus(200);
        $result = $this->sendJson('POST', 'api/v1/master/kecamatan', [
            'id_kecamatan' => '317201', 'id_kabupaten_kota' => '3172', 'nama_kecamatan' => 'Penjaringan',
        ]);
        $result->assertStatus(422);
        $this->assertArrayHasKey('id_kabupaten_kota', $this->json($result)['errors']);

        // Kode (PK) tidak ikut diubah walaupun dikirim.
        $this->sendJson('PUT', 'api/v1/master/agama/6', ['id_agama' => '9', 'nama_agama' => 'Khonghucu'])->assertStatus(200);
        $this->seeInDatabase('agama', ['id_agama' => '6', 'nama_agama' => 'Khonghucu']);
        $this->dontSeeInDatabase('agama', ['id_agama' => '9']);

        // Nama tidak boleh dikosongkan lewat update.
        $this->sendJson('PUT', 'api/v1/master/agama/6', ['nama_agama' => '  '])->assertStatus(422);
    }

    // ------------------------------------------------------------------
    // G-TC #2 — soft-delete only
    // ------------------------------------------------------------------

    public function testDeleteIsAlwaysSoftAndReferencedMasterCannotBeHardDeleted(): void
    {
        foreach (self::masterFixtures() as $entity => $fx) {
            $def = service('masterRegistry')->get($entity);

            $result = $this->delete("api/v1/master/{$entity}/{$fx['existing']}");
            $result->assertStatus(200);
            $body = $this->json($result)['data'];
            $this->assertTrue($body['soft_delete'], $entity);
            $this->assertSame('0', (string) $body['item']['status'], $entity);

            // Baris tetap ada (bukan hard delete), status '0'.
            $this->seeInDatabase($def->table, [$def->primaryKey => $fx['existing'], 'status' => '0']);
        }

        // Master yang direlasikan (provinsi 31 punya kabupaten/kota): soft delete, anak tetap utuh.
        $this->delete('api/v1/master/provinsi/31')->assertStatus(200);
        $this->seeInDatabase('provinsi', ['id_provinsi' => '31', 'status' => '0']);
        $this->seeInDatabase('kabupaten_kota', ['id_kabupaten_kota' => '3171', 'id_provinsi' => '31', 'status' => '1']);

        // Lapis DB: FK RESTRICT menolak hard delete master yang direlasikan.
        $failed = false;

        try {
            $deleted = $this->db->table('provinsi')->where('id_provinsi', '31')->delete();
            $failed  = $deleted === false;
        } catch (Throwable) {
            $failed = true;
        }

        $this->assertTrue($failed, 'FK RESTRICT harus menolak hard delete provinsi yang masih punya kabupaten/kota');
        $this->seeInDatabase('provinsi', ['id_provinsi' => '31']);
    }

    // ------------------------------------------------------------------
    // G-TC #3 — toggle status langsung ter-reflect di dropdown
    // ------------------------------------------------------------------

    public function testStatusToggleIsReflectedInOptionsImmediately(): void
    {
        foreach (self::masterFixtures() as $entity => $fx) {
            $parent = $fx['parent'][1] ?? null;

            // Panggil dulu agar cache terisi — invalidasi harus membuat perubahan langsung terlihat.
            $this->assertContains($fx['existing'], $this->optionIds($entity, $parent), $entity);

            $this->sendJson('PATCH', "api/v1/master/{$entity}/{$fx['existing']}/status", ['status' => '0'])->assertStatus(200);
            $this->assertNotContains($fx['existing'], $this->optionIds($entity, $parent), "{$entity}: non-aktif harus hilang dari dropdown");

            $this->sendJson('PATCH', "api/v1/master/{$entity}/{$fx['existing']}/status", ['status' => '1'])->assertStatus(200);
            $this->assertContains($fx['existing'], $this->optionIds($entity, $parent), "{$entity}: aktif kembali harus muncul");

            // Entri baru langsung muncul, entri yang dihapus langsung hilang.
            $def = service('masterRegistry')->get($entity);
            $this->sendJson('POST', "api/v1/master/{$entity}", $fx['new'])->assertStatus(201);
            $this->assertContains($fx['new'][$def->primaryKey], $this->optionIds($entity, $parent), $entity);
            $this->delete("api/v1/master/{$entity}/{$fx['new'][$def->primaryKey]}")->assertStatus(200);
            $this->assertNotContains($fx['new'][$def->primaryKey], $this->optionIds($entity, $parent), $entity);
        }
    }

    public function testFourLevelCascadeOptions(): void
    {
        $this->assertSame(['31', '32'], $this->optionIds('provinsi'));
        $this->assertSame(['3171', '3172'], $this->optionIds('kabupaten-kota', '31'));
        $this->assertSame(['3273'], $this->optionIds('kabupaten-kota', '32'));
        $this->assertSame(['317101', '317102'], $this->optionIds('kecamatan', '3171'));
        $this->assertSame(['3171011001', '3171011002'], $this->optionIds('kelurahan', '317101'));
        $this->assertSame([], $this->optionIds('kelurahan', '999999'));

        $first = $this->json($this->get('api/v1/master/kelurahan/options', ['parent' => '317101']))['data'][0];
        $this->assertSame(['id' => '3171011001', 'nama' => 'Gambir', 'parent' => '317101'], $first);
    }

    // ------------------------------------------------------------------
    // G-TC #4 — re-ordering
    // ------------------------------------------------------------------

    public function testReorderShiftsOtherEntriesAndKeepsDropdownLogical(): void
    {
        // Konghucu (6) ke posisi 1 → yang lain bergeser turun.
        $this->sendJson('PATCH', 'api/v1/master/agama/6/order', ['order' => 1])->assertStatus(200);
        $this->assertSame(['6', '1', '2', '3', '4', '5'], $this->optionIds('agama'));
        $this->assertSame([1, 2, 3, 4, 5, 6], $this->orders('agama', 'id_agama', ['6', '1', '2', '3', '4', '5']));

        // Islam (1, sekarang posisi 2) ke posisi 5 → yang di antaranya bergeser naik.
        $this->sendJson('PATCH', 'api/v1/master/agama/1/order', ['order' => 5])->assertStatus(200);
        $this->assertSame(['6', '2', '3', '4', '1', '5'], $this->optionIds('agama'));

        // Posisi di luar jangkauan dijepit ke ujung.
        $this->sendJson('PATCH', 'api/v1/master/agama/6/order', ['order' => 99])->assertStatus(200);
        $this->assertSame(['2', '3', '4', '1', '5', '6'], $this->optionIds('agama'));

        $this->sendJson('PATCH', 'api/v1/master/agama/6/order', ['order' => 0])->assertStatus(422);

        // Urutan wilayah berlaku per induk: kabupaten/kota provinsi 32 tidak ikut bergeser.
        $this->sendJson('PATCH', 'api/v1/master/kabupaten-kota/3172/order', ['order' => 1])->assertStatus(200);
        $this->assertSame(['3172', '3171'], $this->optionIds('kabupaten-kota', '31'));
        $this->seeInDatabase('kabupaten_kota', ['id_kabupaten_kota' => '3273', 'order' => 1]);

        // Entri baru dengan order eksplisit disisipkan, bukan menimpa.
        $this->sendJson('POST', 'api/v1/master/agama', ['id_agama' => '7', 'nama_agama' => 'Kepercayaan', 'order' => 2])->assertStatus(201);
        $this->assertSame(['2', '7', '3', '4', '1', '5', '6'], $this->optionIds('agama'));
    }

    public function testMovingToAnotherParentAppendsAndRenumbersOldParent(): void
    {
        $this->sendJson('PUT', 'api/v1/master/kabupaten-kota/3171', ['id_provinsi' => '32'])->assertStatus(200);

        $this->assertSame(['3172'], $this->optionIds('kabupaten-kota', '31'));
        $this->assertSame(['3273', '3171'], $this->optionIds('kabupaten-kota', '32'));
        $this->seeInDatabase('kabupaten_kota', ['id_kabupaten_kota' => '3172', 'order' => 1]);
        $this->seeInDatabase('kabupaten_kota', ['id_kabupaten_kota' => '3171', 'order' => 2]);
    }

    // ------------------------------------------------------------------
    // G-TC #6 — audit log tambah/ubah/hapus
    // ------------------------------------------------------------------

    public function testAuditLogIsRecordedForCreateUpdateDeleteWithActor(): void
    {
        foreach (self::masterFixtures() as $entity => $fx) {
            $def = service('masterRegistry')->get($entity);
            $id  = $fx['new'][$def->primaryKey];

            $this->sendJson('POST', "api/v1/master/{$entity}", $fx['new'])->assertStatus(201);
            $this->sendJson('PUT', "api/v1/master/{$entity}/{$id}", [$def->nameField => $fx['new'][$def->nameField] . ' (ubah)'])->assertStatus(200);
            $this->delete("api/v1/master/{$entity}/{$id}")->assertStatus(200);

            foreach (['create', 'update', 'delete'] as $event) {
                $this->seeInDatabase('audit_logs', [
                    'entity'    => $def->table,
                    'entity_id' => $id,
                    'event'     => $event,
                    'nip_actor' => self::ADMIN_NIP,
                ]);
            }

            $log   = $this->db->table('audit_logs')->where(['entity' => $def->table, 'entity_id' => $id, 'event' => 'delete'])->get()->getRowArray();
            $after = json_decode((string) $log['after_json'], true);
            $this->assertSame('0', (string) $after['status'], "{$entity}: after_json delete harus status 0");
        }
    }

    public function testLeadingZeroCodeIsPreservedInRouteAndAudit(): void
    {
        $this->sendJson('POST', 'api/v1/master/agama', ['id_agama' => '07', 'nama_agama' => 'Kepercayaan'])->assertStatus(201);

        $result = $this->get('api/v1/master/agama/07');
        $result->assertStatus(200);
        $this->assertSame('07', $this->json($result)['data']['id_agama']);
        $this->get('api/v1/master/agama/7')->assertStatus(404);

        $this->sendJson('PUT', 'api/v1/master/agama/07', ['nama_agama' => 'Penghayat Kepercayaan'])->assertStatus(200);
        $this->seeInDatabase('audit_logs', ['entity' => 'agama', 'entity_id' => '07', 'event' => 'create']);
        $this->seeInDatabase('audit_logs', ['entity' => 'agama', 'entity_id' => '07', 'event' => 'update']);
        $this->dontSeeInDatabase('audit_logs', ['entity' => 'agama', 'entity_id' => '7']);
    }

    public function testListSupportsSearchStatusParentFilterAndParentName(): void
    {
        $this->sendJson('PATCH', 'api/v1/master/agama/5/status', ['status' => '0'])->assertStatus(200);

        $all = $this->json($this->get('api/v1/master/agama'))['data'];
        $this->assertSame(6, $all['total']);
        $this->assertSame('1', $all['items'][0]['id_agama']);

        $inactive = $this->json($this->get('api/v1/master/agama', ['status' => '0']))['data'];
        $this->assertSame(['5'], array_column($inactive['items'], 'id_agama'));

        $search = $this->json($this->get('api/v1/master/agama', ['search' => 'kat']))['data'];
        $this->assertSame(['3'], array_column($search['items'], 'id_agama'));

        $kec = $this->json($this->get('api/v1/master/kecamatan', ['parent' => '3171']))['data'];
        $this->assertSame(['317101', '317102'], array_column($kec['items'], 'id_kecamatan'));
        $this->assertSame('Jakarta Pusat', $kec['items'][0]['parent_nama']);

        $this->get('api/v1/master/agama/99')->assertStatus(404);
    }

    /**
     * @param list<string> $ids
     *
     * @return list<int>
     */
    private function orders(string $table, string $pk, array $ids): array
    {
        return array_map(
            fn (string $id): int => (int) $this->db->table($table)->where($pk, $id)->get()->getRowArray()['order'],
            $ids,
        );
    }
}
