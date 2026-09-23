<?php

declare(strict_types=1);

namespace Tests\MasterData;

use App\Constants\Role;
use App\Libraries\MasterData\MasterDefinition;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\AuthTestTrait;
use Tests\Support\Database\Seeds\MasterDataSeeder;
use Tests\Support\MasterDataTestTrait;
use Throwable;

/**
 * G-TC — test case generik Modul G (02-MasterData.md), dijalankan ke SELURUH master yang terdaftar di engine
 * (G-02 s.d. G-07, jenis libur G-08, FAQ G-10). MTC-008 (pola CRUD master).
 *
 * Tiap test mengulang skenario untuk semua master sekaligus agar biaya migrate:refresh per test tetap kecil.
 * G-TC #5 (RBAC) ada di RbacMasterEndpointsTest; G-TC #7 (QA visual) di luar cakupan test otomatis.
 * Master dengan aturan khusus diuji terpisah: HariLiburTest (G-08), WebConfigTest (G-09), FaqTest (G-10).
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

    /**
     * Master baru wajib punya fixture G-TC — kalau tidak, seluruh test generik di bawah akan melewatkannya.
     */
    public function testEveryRegisteredMasterHasFixture(): void
    {
        $registered = array_keys(service('masterRegistry')->all());
        $fixtures   = array_keys(self::masterFixtures());

        sort($registered);
        sort($fixtures);

        $this->assertSame($registered, $fixtures, 'Setiap master di Config\MasterData wajib punya fixture G-TC.');
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
            $newId = (string) $row[$def->primaryKey];

            if ($def->hasStatus) {
                $this->assertSame('1', (string) $row['status'], $entity);
            }

            // Kode duplikat — hanya master ber-kode manual (PK auto increment tidak mungkin bentrok).
            if (! $def->autoIncrement) {
                $dupCode = $fx['new'];
                $dupCode[$def->nameField] .= ' Lain';
                $result = $this->sendJson('POST', $base, $dupCode);
                $result->assertStatus(422);
                $this->assertArrayHasKey($def->primaryKey, $this->json($result)['errors'], $entity);
            }

            // Nama duplikat (case-insensitive) di induk yang sama
            $dupName = $fx['new'];

            if (! $def->autoIncrement) {
                $dupName[$def->primaryKey] = self::altCode($def, (string) $fx['new'][$def->primaryKey]);
            }

            $dupName[$def->nameField] = $fx['duplicate'];
            $result                   = $this->sendJson('POST', $base, $dupName);
            $result->assertStatus(422);
            $this->assertArrayHasKey($def->nameField, $this->json($result)['errors'], $entity);

            // Nama duplikat lewat update
            $this->sendJson('PUT', "{$base}/{$newId}", [$def->nameField => $fx['duplicate']])->assertStatus(422);
        }
    }

    public function testSameNameIsAllowedUnderDifferentParent(): void
    {
        // "Gambir" sudah ada di kecamatan 3171010; boleh dipakai di kecamatan lain.
        $this->sendJson('POST', 'api/v1/master/kelurahan', [
            'id_kelurahan' => '3273010002',
            'id_kecamatan' => '3273010',
            'kelurahan'    => 'Gambir',
        ])->assertStatus(201);

        // Tapi tidak di induk yang sama.
        $this->sendJson('POST', 'api/v1/master/kelurahan', [
            'id_kelurahan' => '3171010009',
            'id_kecamatan' => '3171010',
            'kelurahan'    => '  gambir ',
        ])->assertStatus(422);
    }

    public function testParentMustExistAndBeActiveAndCodeIsImmutable(): void
    {
        $this->sendJson('POST', 'api/v1/master/kecamatan', [
            'id_kecamatan' => '9999010', 'id_kabupaten_kota' => '9999', 'kecamatan' => 'Tanpa Induk',
        ])->assertStatus(422);

        $this->sendJson('PATCH', 'api/v1/master/kabupaten-kota/3172/status', ['status' => '0'])->assertStatus(200);
        $result = $this->sendJson('POST', 'api/v1/master/kecamatan', [
            'id_kecamatan' => '3172010', 'id_kabupaten_kota' => '3172', 'kecamatan' => 'Penjaringan',
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

    /**
     * Field tambahan per master (zonasi satker G-02, affect_tukin G-06, dst.) tersimpan & tervalidasi.
     */
    public function testExtraFieldsAreStoredAndValidated(): void
    {
        $satker = $this->json($this->sendJson('POST', 'api/v1/master/satker', [
            'id_unit' => '2', 'satker' => 'Balai Wilayah Tengah', 'zonasi' => '60',
        ]))['data'];
        $this->assertSame(60, (int) $satker['zonasi']);
        $this->seeInDatabase('satker', ['satker' => 'Balai Wilayah Tengah', 'zonasi' => 60]);

        $konket = $this->json($this->sendJson('POST', 'api/v1/master/jenis-konket', [
            'id_jenis_konket' => 'CTI', 'nama_jenis_konket' => 'Cuti', 'affect_tukin' => '1',
        ]))['data'];
        $this->assertSame(1, (int) $konket['affect_tukin']);

        // Tipe salah ditolak: select di luar daftar & angka bukan bilangan.
        $this->sendJson('POST', 'api/v1/master/jenis-konket', [
            'id_jenis_konket' => 'XX', 'nama_jenis_konket' => 'Salah', 'affect_tukin' => '9',
        ])->assertStatus(422);
        $this->sendJson('POST', 'api/v1/master/satker', [
            'id_unit' => '1', 'satker' => 'Zonasi Bukan Angka', 'zonasi' => 'pagi',
        ])->assertStatus(422);

        // Field opsional boleh dikosongkan.
        $this->sendJson('POST', 'api/v1/master/unit', ['unit' => 'Staf Ahli', 'alamat_pdf_header' => ''])->assertStatus(201);
    }

    /**
     * G-03 DoD / MTC-010: radius lokasi presensi minimal 10 meter.
     */
    public function testLokasiPresensiRadiusMinimumIsEnforced(): void
    {
        $result = $this->sendJson('POST', 'api/v1/master/lokasi-presensi', [
            'id_lokasi_presensi' => 'L09', 'nama_lokasi' => 'Radius Kecil', 'latitude' => '-6.2', 'longitude' => '106.8', 'radius_meter' => '5',
        ]);
        $result->assertStatus(422);
        $this->assertArrayHasKey('radius_meter', $this->json($result)['errors']);
        $this->dontSeeInDatabase('lokasi_presensi', ['id_lokasi_presensi' => 'L09']);

        $this->sendJson('POST', 'api/v1/master/lokasi-presensi', [
            'id_lokasi_presensi' => 'L10', 'nama_lokasi' => 'Radius Pas Batas', 'latitude' => '-6.2', 'longitude' => '106.8', 'radius_meter' => '10',
        ])->assertStatus(201);

        $this->sendJson('POST', 'api/v1/master/lokasi-presensi', [
            'id_lokasi_presensi' => 'L11', 'nama_lokasi' => 'Radius Lima Puluh', 'latitude' => '-6.2', 'longitude' => '106.8', 'radius_meter' => '50',
        ])->assertStatus(201);

        // Ubah ke radius di bawah batas juga ditolak.
        $this->sendJson('PUT', 'api/v1/master/lokasi-presensi/L10', ['radius_meter' => '9'])->assertStatus(422);
        $this->seeInDatabase('lokasi_presensi', ['id_lokasi_presensi' => 'L10', 'radius_meter' => 10]);

        // Koordinat di luar rentang bumi ditolak.
        $this->sendJson('POST', 'api/v1/master/lokasi-presensi', [
            'id_lokasi_presensi' => 'L12', 'nama_lokasi' => 'Koordinat Salah', 'latitude' => '-100', 'longitude' => '106.8', 'radius_meter' => '20',
        ])->assertStatus(422);
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
        foreach ([['provinsi', 'id_provinsi', '31'], ['unit', 'id_unit', '1'], ['tingkat_hukdis', 'id_tingkat_hukdis', '1']] as [$table, $pk, $id]) {
            $failed = false;

            try {
                $failed = $this->db->table($table)->where($pk, $id)->delete() === false;
            } catch (Throwable) {
                $failed = true;
            }

            $this->assertTrue($failed, "FK RESTRICT harus menolak hard delete {$table} yang masih direlasikan");
            $this->seeInDatabase($table, [$pk => $id]);
        }
    }

    // ------------------------------------------------------------------
    // G-TC #3 — toggle status langsung ter-reflect di dropdown
    // ------------------------------------------------------------------

    public function testStatusToggleIsReflectedInOptionsImmediately(): void
    {
        foreach (self::masterFixtures() as $entity => $fx) {
            $def    = service('masterRegistry')->get($entity);
            $parent = $fx['parent'][1] ?? null;

            // Panggil dulu agar cache terisi — invalidasi harus membuat perubahan langsung terlihat.
            $this->assertContains($fx['existing'], $this->optionIds($entity, $parent), $entity);

            $this->sendJson('PATCH', "api/v1/master/{$entity}/{$fx['existing']}/status", ['status' => '0'])->assertStatus(200);
            $this->assertNotContains($fx['existing'], $this->optionIds($entity, $parent), "{$entity}: non-aktif harus hilang dari dropdown");

            $this->sendJson('PATCH', "api/v1/master/{$entity}/{$fx['existing']}/status", ['status' => '1'])->assertStatus(200);
            $this->assertContains($fx['existing'], $this->optionIds($entity, $parent), "{$entity}: aktif kembali harus muncul");

            // Entri baru langsung muncul, entri yang dihapus langsung hilang.
            $created = $this->json($this->sendJson('POST', "api/v1/master/{$entity}", $fx['new']))['data'];
            $newId   = (string) $created[$def->primaryKey];
            $this->assertContains($newId, $this->optionIds($entity, $parent), $entity);
            $this->delete("api/v1/master/{$entity}/{$newId}")->assertStatus(200);
            $this->assertNotContains($newId, $this->optionIds($entity, $parent), $entity);
        }
    }

    public function testCascadeOptionsAcrossModules(): void
    {
        // Wilayah 4 level (G-07 DoD)
        $this->assertSame(['31', '32'], $this->optionIds('provinsi'));
        $this->assertSame(['3171', '3172'], $this->optionIds('kabupaten-kota', '31'));
        $this->assertSame(['3273'], $this->optionIds('kabupaten-kota', '32'));
        $this->assertSame(['3171010', '3171020'], $this->optionIds('kecamatan', '3171'));
        $this->assertSame(['3171010001', '3171010002'], $this->optionIds('kelurahan', '3171010'));
        $this->assertSame([], $this->optionIds('kelurahan', '9999999'));

        $first = $this->json($this->get('api/v1/master/kelurahan/options', ['parent' => '3171010']))['data'][0];
        $this->assertSame(['id' => '3171010001', 'nama' => 'Gambir', 'parent' => '3171010'], $first);

        // Dropdown berjenjang lain: group→sub group jabatan (G-02), unit→satker (G-02),
        // bidang→jurusan (G-05 DoD), tingkat→jenis hukdis (G-06), topik→sub topik (G-10).
        $this->assertSame(['1', '2'], $this->optionIds('sub-group-jabatan', '1'));
        $this->assertSame(['3'], $this->optionIds('sub-group-jabatan', '2'));
        $this->assertSame(['1', '2'], $this->optionIds('satker', '1'));
        $this->assertSame(['1', '2'], $this->optionIds('jurusan-pendidikan', '1'));
        $this->assertSame(['3'], $this->optionIds('jurusan-pendidikan', '2'));
        $this->assertSame(['1', '2'], $this->optionIds('jenis-hukdis', '1'));
        $this->assertSame(['S01', 'S02'], $this->optionIds('faq-sub-topic', 'T01'));
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

        // Master ber-PK auto increment juga bisa di-reorder (G-02).
        $this->sendJson('PATCH', 'api/v1/master/unit/2/order', ['order' => 1])->assertStatus(200);
        $this->assertSame(['2', '1'], $this->optionIds('unit'));

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

    public function testMasterWithoutOrderRejectsReorder(): void
    {
        // Pemetaan pegawai↔lokasi presensi tidak punya kolom order.
        $this->sendJson('PATCH', 'api/v1/master/pegawai-lokasi-presensi/1/order', ['order' => 1])->assertStatus(422);
    }

    // ------------------------------------------------------------------
    // G-TC #6 — audit log tambah/ubah/hapus
    // ------------------------------------------------------------------

    public function testAuditLogIsRecordedForCreateUpdateDeleteWithActor(): void
    {
        foreach (self::masterFixtures() as $entity => $fx) {
            $def     = service('masterRegistry')->get($entity);
            $created = $this->json($this->sendJson('POST', "api/v1/master/{$entity}", $fx['new']))['data'];
            $id      = (string) $created[$def->primaryKey];

            $rename = self::renamedValue($def, (string) $fx['new'][$def->nameField]);
            $this->sendJson('PUT', "api/v1/master/{$entity}/{$id}", [$def->nameField => $rename])->assertStatus(200);
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
            $this->assertSame('0', (string) $after[MasterDefinition::STATUS_FIELD], "{$entity}: after_json delete harus status 0");
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
        $this->assertSame(['3171010', '3171020'], array_column($kec['items'], 'id_kecamatan'));
        $this->assertSame('Jakarta Pusat', $kec['items'][0]['parent_nama']);

        // Pencarian mencakup kolom tambahan yang didaftarkan (extraSearch), mis. kode group jabatan.
        $group = $this->json($this->get('api/v1/master/group-jabatan', ['search' => 'JFT']))['data'];
        $this->assertSame(['2'], array_column($group['items'], 'id_group_jabatan'));

        $this->get('api/v1/master/agama/99')->assertStatus(404);
    }

    public function testMetaExposesFieldsForEveryMaster(): void
    {
        $meta = $this->json($this->get('api/v1/master/meta'))['data'];
        $this->assertSame(array_keys(service('masterRegistry')->all()), array_column($meta, 'key'));

        $byKey = array_column($meta, null, 'key');
        $this->assertTrue($byKey['satker']['auto_increment']);
        $this->assertFalse($byKey['agama']['auto_increment']);
        $this->assertSame('id_unit', $byKey['satker']['parent']['field']);
        $this->assertContains('zonasi', array_column($byKey['satker']['fields'], 'name'));
        $this->assertFalse($byKey['pegawai-lokasi-presensi']['has_order']);

        $radius = array_column($byKey['lokasi-presensi']['fields'], null, 'name')['radius_meter'];
        $this->assertTrue($radius['required']);
        $this->assertSame('int', $radius['type']);
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
