<?php

declare(strict_types=1);

namespace Tests\MasterData;

use App\Constants\Role;
use App\Exceptions\ValidationException;
use App\Libraries\MasterData\MasterDefinition;
use App\Libraries\MasterData\MasterService;
use CodeIgniter\I18n\Time;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use ReflectionMethod;
use Tests\Support\AuthTestTrait;
use Tests\Support\Database\Seeds\AuthSeeder;
use Tests\Support\Database\Seeds\MasterDataSeeder;
use Tests\Support\MasterDataTestTrait;
use Throwable;

/**
 * G-TC — test case generik Modul G (02-MasterData.md), dijalankan ke SELURUH master yang terdaftar di engine
 * (saat ini G-07: agama, jenis_pegawai, jenis_status, wilayah 4 level). MTC-008 (pola CRUD master).
 * Skema & status mengikuti legacy (DBV-001): status 1 Aktif / 2 Tidak Aktif / 10 Dihapus.
 *
 * Tiap test mengulang skenario untuk semua master sekaligus agar biaya migrate:refresh per test tetap kecil.
 * G-TC #5 (RBAC) ada di RbacMasterEndpointsTest; skema DB & migration DBV-001 di Batch1LegacySchemaTest;
 * G-TC #7 (QA visual) di luar cakupan test otomatis.
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
        Time::setTestNow();
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
            $this->assertSame('1', (string) $row['status'], $entity);
            $newId = (string) $row[$def->primaryKey];

            // Kode duplikat — hanya master ber-kode manual (PK auto increment tidak mungkin bentrok).
            if (! $def->autoIncrement) {
                $dupCode = $fx['new'];
                $dupCode[$def->nameField] .= ' Lain';
                $result = $this->sendJson('POST', $base, $dupCode);
                $result->assertStatus(422);
                $this->assertArrayHasKey($def->primaryKey, $this->json($result)['errors'], $entity);
            }

            // Nama duplikat (case-insensitive) di lingkup yang sama (induk / uniqueScope).
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

    /**
     * Keunikan nama juga berlaku terhadap entri Tidak Aktif (status 2) — pesan mengarahkan untuk mengaktifkan kembali.
     */
    public function testNameUniquenessIncludesInactiveEntries(): void
    {
        $this->sendJson('PATCH', 'api/v1/master/agama/4/status', ['status' => '2'])->assertStatus(200);

        $result = $this->sendJson('POST', 'api/v1/master/agama', ['agama' => 'hindu']);
        $result->assertStatus(422);
        $this->assertStringContainsString('tidak aktif', implode(' ', (array) $this->json($result)['errors']['agama']));
        $this->sendJson('PUT', 'api/v1/master/agama/6', ['agama' => 'HINDU'])->assertStatus(422);
        $this->assertSame(1, $this->db->table('agama')->where('agama', 'Hindu')->countAllResults());
    }

    /**
     * Balapan: dua permintaan lolos cek aplikasi, UNIQUE index menolak yang kedua (1062). Transaksi harus di-rollback
     * utuh dan hasilnya 422 (bukan 201/404 palsu), serta koneksi tidak tertinggal dalam transStatus gagal.
     */
    public function testDuplicateRaceIsRolledBackAndTranslatedTo422(): void
    {
        $service = service('masterService');
        $def     = service('masterRegistry')->get('agama');

        // Simulasikan pemenang balapan: cek aplikasi dilewati, INSERT langsung menabrak uq_agama_nama.
        $invoke = static fn (string $method, mixed ...$args): mixed => (new ReflectionMethod(MasterService::class, $method))->invoke($service, ...$args);

        try {
            $invoke(
                'translateDuplicate',
                static fn () => $invoke('transactional', static function () use ($invoke, $def): void {
                    $invoke('model', $def)->update('6', [MasterDefinition::ORDER_FIELD => 99]);
                    $invoke('model', $def)->insert([$def->nameField => 'Islam', MasterDefinition::ORDER_FIELD => 7, MasterDefinition::STATUS_FIELD => '1']);
                }),
                static fn () => $invoke('assertNameUnique', $def, 'Islam', null),
            );
            $this->fail('Pelanggaran UNIQUE harus diterjemahkan menjadi ValidationException (422).');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('agama', (array) $e->getErrors());
        }

        // Rollback utuh: UPDATE sebelum INSERT yang gagal ikut dibatalkan, tidak ada baris ganda.
        $this->seeInDatabase('agama', ['id_agama' => 6, 'order' => 6]);
        $this->assertSame(1, $this->db->table('agama')->where('agama', 'Islam')->countAllResults());
        $this->assertTrue($this->db->transStatus());
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

    /**
     * Legacy: jenis_status unik per status_pegawai (UNIQUE status_pegawai + jenis_status).
     */
    public function testJenisStatusNameIsUniquePerStatusPegawai(): void
    {
        // "Pensiun" sudah ada untuk status pegawai 2 (Tidak Aktif); boleh untuk status pegawai 1.
        $created = $this->sendJson('POST', 'api/v1/master/jenis-status', ['jenis_status' => 'Pensiun', 'status_pegawai' => '1']);
        $created->assertStatus(201);
        $newId = (string) $this->json($created)['data']['id_jenis_status'];

        // Pindah ke status pegawai 2 → bentrok dengan "Pensiun" yang sudah ada di sana.
        $result = $this->sendJson('PUT', "api/v1/master/jenis-status/{$newId}", ['status_pegawai' => '2']);
        $result->assertStatus(422);
        $this->assertArrayHasKey('jenis_status', $this->json($result)['errors']);
        $this->seeInDatabase('jenis_status', ['id_jenis_status' => $newId, 'status_pegawai' => 1]);
    }

    public function testParentMustExistAndBeActiveAndCodeIsImmutable(): void
    {
        $this->sendJson('POST', 'api/v1/master/kecamatan', [
            'id_kecamatan' => '9999010', 'id_kabupaten_kota' => '9999', 'kecamatan' => 'Tanpa Induk',
        ])->assertStatus(422);

        $this->sendJson('PATCH', 'api/v1/master/kabupaten-kota/3172/status', ['status' => '2'])->assertStatus(200);
        $result = $this->sendJson('POST', 'api/v1/master/kecamatan', [
            'id_kecamatan' => '3172010', 'id_kabupaten_kota' => '3172', 'kecamatan' => 'Penjaringan',
        ]);
        $result->assertStatus(422);
        $this->assertArrayHasKey('id_kabupaten_kota', $this->json($result)['errors']);

        // Kode (PK) tidak ikut diubah walaupun dikirim — kode manual maupun AUTO_INCREMENT.
        $this->sendJson('PUT', 'api/v1/master/provinsi/32', ['id_provinsi' => '39', 'provinsi' => 'Jabar'])->assertStatus(200);
        $this->seeInDatabase('provinsi', ['id_provinsi' => '32', 'provinsi' => 'Jabar']);
        $this->dontSeeInDatabase('provinsi', ['id_provinsi' => '39']);

        $this->sendJson('PUT', 'api/v1/master/agama/6', ['id_agama' => '9', 'agama' => 'Khonghucu'])->assertStatus(200);
        $this->seeInDatabase('agama', ['id_agama' => 6, 'agama' => 'Khonghucu']);
        $this->dontSeeInDatabase('agama', ['id_agama' => 9]);

        // Nama tidak boleh dikosongkan lewat update.
        $this->sendJson('PUT', 'api/v1/master/agama/6', ['agama' => '  '])->assertStatus(422);
    }

    /**
     * ISSUE-008: kode wilayah legacy = tepat 2/4/7/10 digit angka tanpa titik (CHAR(N) di DB, tidak terpotong diam-diam).
     */
    public function testKodeWilayahMustBeExactDigits(): void
    {
        $invalid = [
            ['provinsi', ['id_provinsi' => '3', 'provinsi' => 'Satu Digit']],
            ['provinsi', ['id_provinsi' => '3A', 'provinsi' => 'Ada Huruf']],
            ['kabupaten-kota', ['id_kabupaten_kota' => '31.71', 'id_provinsi' => '31', 'kabupaten_kota' => 'Bertitik']],
            ['kecamatan', ['id_kecamatan' => '317101', 'id_kabupaten_kota' => '3171', 'kecamatan' => 'Format Kemendagri 6 Digit']],
            ['kelurahan', ['id_kelurahan' => '31.71.01.1001', 'id_kecamatan' => '3171010', 'kelurahan' => 'Format Bertitik']],
        ];

        foreach ($invalid as [$entity, $payload]) {
            $def    = service('masterRegistry')->get($entity);
            $result = $this->sendJson('POST', "api/v1/master/{$entity}", $payload);
            $result->assertStatus(422);
            $this->assertArrayHasKey($def->primaryKey, $this->json($result)['errors'], "{$entity} {$payload[$def->primaryKey]}");
        }

        $this->dontSeeInDatabase('kelurahan', ['kelurahan' => 'Format Bertitik']);
    }

    public function testKodeWithTrailingNewlineIsRejected(): void
    {
        $result = $this->sendJson('POST', 'api/v1/master/provinsi', ['id_provinsi' => "33\n", 'provinsi' => 'Newline']);
        $result->assertStatus(422);
        $this->assertArrayHasKey('id_provinsi', $this->json($result)['errors']);
        $this->dontSeeInDatabase('provinsi', ['provinsi' => 'Newline']);
    }

    /**
     * Id route harus bentuk kanonik — '06', '6abc', '1e0' tidak boleh jadi alias id 6 lewat cast numerik MySQL.
     */
    public function testNonCanonicalRouteIdsAreNotFound(): void
    {
        foreach (['agama/06', 'agama/6abc', 'agama/1e0', 'agama/0', 'provinsi/3', 'provinsi/031', 'kecamatan/317101'] as $path) {
            $this->get("api/v1/master/{$path}")->assertStatus(404);
        }

        $this->sendJson('PUT', 'api/v1/master/agama/06', ['agama' => 'Alias'])->assertStatus(404);
        $this->dontSeeInDatabase('agama', ['agama' => 'Alias']);
        $this->get('api/v1/master/agama/6')->assertStatus(200);
        $this->get('api/v1/master/kecamatan/3171010')->assertStatus(200);
    }

    public function testValidationMessagesAreIndonesian(): void
    {
        $order = $this->sendJson('PATCH', 'api/v1/master/agama/6/order', []);
        $order->assertStatus(422);
        $this->assertSame('Urutan wajib diisi.', ((array) $this->json($order)['errors']['order'])[0]);

        $name = $this->sendJson('POST', 'api/v1/master/agama', ['agama' => 123]);
        $name->assertStatus(422);
        $this->assertSame('Agama harus teks.', ((array) $this->json($name)['errors']['agama'])[0]);
    }

    /**
     * agama/jenis_pegawai/jenis_status: PK AUTO_INCREMENT seperti DDL legacy — kode dari input diabaikan.
     */
    public function testAutoIncrementMastersIgnoreCodeInput(): void
    {
        $created = $this->sendJson('POST', 'api/v1/master/agama', ['id_agama' => '99', 'agama' => 'Kepercayaan']);
        $created->assertStatus(201);
        $this->assertSame('7', (string) $this->json($created)['data']['id_agama']);
        $this->dontSeeInDatabase('agama', ['id_agama' => 99]);
    }

    /**
     * Kolom tambahan legacy: kd_area (kabupaten/kota), kd_pos (kelurahan), status_pegawai (jenis status).
     */
    public function testExtraLegacyFieldsAreStoredAndValidated(): void
    {
        $this->sendJson('POST', 'api/v1/master/kabupaten-kota', [
            'id_kabupaten_kota' => '3174', 'id_provinsi' => '31', 'kabupaten_kota' => 'Jakarta Selatan', 'kd_area' => '021',
        ])->assertStatus(201);
        $this->seeInDatabase('kabupaten_kota', ['id_kabupaten_kota' => '3174', 'kd_area' => '021']);

        $this->sendJson('POST', 'api/v1/master/kabupaten-kota', [
            'id_kabupaten_kota' => '3175', 'id_provinsi' => '31', 'kabupaten_kota' => 'Jakarta Timur', 'kd_area' => '02100',
        ])->assertStatus(422);

        // kd_pos: satu atau beberapa kode pos 5 digit dipisah koma; opsional.
        $this->sendJson('POST', 'api/v1/master/kelurahan', [
            'id_kelurahan' => '3171010004', 'id_kecamatan' => '3171010', 'kelurahan' => 'Kebon Kelapa', 'kd_pos' => '10120,10121',
        ])->assertStatus(201);
        $this->seeInDatabase('kelurahan', ['id_kelurahan' => '3171010004', 'kd_pos' => '10120,10121']);

        $result = $this->sendJson('POST', 'api/v1/master/kelurahan', [
            'id_kelurahan' => '3171010005', 'id_kecamatan' => '3171010', 'kelurahan' => 'Kode Pos Salah', 'kd_pos' => '1012',
        ]);
        $result->assertStatus(422);
        $this->assertArrayHasKey('kd_pos', $this->json($result)['errors']);

        $this->sendJson('POST', 'api/v1/master/kelurahan', [
            'id_kelurahan' => '3171010006', 'id_kecamatan' => '3171010', 'kelurahan' => 'Tanpa Kode Pos', 'kd_pos' => '',
        ])->assertStatus(201);
        $this->seeInDatabase('kelurahan', ['id_kelurahan' => '3171010006', 'kd_pos' => null]);

        // status_pegawai wajib dan hanya 1/2.
        $this->sendJson('POST', 'api/v1/master/jenis-status', ['jenis_status' => 'CLTN'])->assertStatus(422);
        $this->sendJson('POST', 'api/v1/master/jenis-status', ['jenis_status' => 'CLTN', 'status_pegawai' => '3'])->assertStatus(422);
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
            $this->assertSame('10', (string) $body['item']['status'], $entity);

            // Baris tetap ada (bukan hard delete), status 10 'Dihapus'.
            $this->seeInDatabase($def->table, [$def->primaryKey => $fx['existing'], 'status' => 10]);
        }

        // Master yang direlasikan (provinsi 31 punya kabupaten/kota): soft delete, anak tetap utuh.
        $this->delete('api/v1/master/provinsi/31')->assertStatus(200);
        $this->seeInDatabase('provinsi', ['id_provinsi' => '31', 'status' => 10]);
        $this->seeInDatabase('kabupaten_kota', ['id_kabupaten_kota' => '3171', 'id_provinsi' => '31', 'status' => 1]);

        // Lapis DB: FK RESTRICT menolak hard delete master yang direlasikan.
        $failed = false;

        try {
            $failed = $this->db->table('provinsi')->where('id_provinsi', '31')->delete() === false;
        } catch (Throwable) {
            $failed = true;
        }

        $this->assertTrue($failed, 'FK RESTRICT harus menolak hard delete provinsi yang masih punya kabupaten/kota');
        $this->seeInDatabase('provinsi', ['id_provinsi' => '31']);
    }

    /**
     * Entri yang dihapus (status 10) tersembunyi dari daftar default seperti legacy (status != 10), bisa dilihat lewat
     * filter status=10, dan bisa dipulihkan. agama mengisi/mengosongkan deleted_at (kolom legacy).
     */
    public function testDeletedEntriesAreHiddenByDefaultAndCanBeRestored(): void
    {
        $this->delete('api/v1/master/agama/5')->assertStatus(200);

        $row = $this->db->table('agama')->where('id_agama', 5)->get()->getRowArray();
        $this->assertNotNull($row['deleted_at']);

        $default = $this->json($this->get('api/v1/master/agama'))['data'];
        $this->assertSame(5, $default['total']);
        $this->assertNotContains('5', array_map('strval', array_column($default['items'], 'id_agama')));

        $deleted = $this->json($this->get('api/v1/master/agama', ['status' => '10']))['data'];
        $this->assertSame(['5'], array_map('strval', array_column($deleted['items'], 'id_agama')));

        // Nama yang sudah dihapus tetap tidak boleh dibuat ganda — arahkan ke pemulihan.
        $result = $this->sendJson('POST', 'api/v1/master/agama', ['agama' => 'buddha']);
        $result->assertStatus(422);
        $this->assertStringContainsString('dihapus', implode(' ', (array) $this->json($result)['errors']['agama']));

        $restored = $this->json($this->sendJson('PATCH', 'api/v1/master/agama/5/status', ['status' => '1']))['data'];
        $this->assertSame('1', (string) $restored['status']);
        $this->seeInDatabase('agama', ['id_agama' => 5, 'status' => 1, 'deleted_at' => null]);
        $this->assertContains('5', $this->optionIds('agama'));

        // Status 10 tidak bisa diset lewat PATCH status (hanya lewat DELETE).
        $this->sendJson('PATCH', 'api/v1/master/agama/5/status', ['status' => '10'])->assertStatus(422);
        $this->sendJson('PATCH', 'api/v1/master/agama/5/status', ['status' => '0'])->assertStatus(422);
    }

    /**
     * PUT dengan field status juga memulihkan entri terhapus secara konsisten dengan PATCH status.
     */
    public function testUpdateWithStatusRestoresDeletedEntryConsistently(): void
    {
        $this->delete('api/v1/master/agama/4')->assertStatus(200);
        $this->sendJson('PUT', 'api/v1/master/agama/4', ['status' => '1'])->assertStatus(200);

        $this->seeInDatabase('agama', ['id_agama' => 4, 'status' => 1, 'deleted_at' => null]);
        $this->assertSame(['1', '2', '3', '5', '6', '4'], $this->optionIds('agama'), 'dipulihkan = ditaruh di akhir urutan');
    }

    /**
     * Entri yang dihapus tidak punya urutan tampil: PATCH order / PUT order ditolak; memulihkan sekaligus menaruh
     * urutan (PUT status + order) tetap boleh. Pemulihan ke status 2 (Tidak Aktif) juga mengosongkan deleted_at.
     */
    public function testDeletedEntryOrderIsLockedUntilRestored(): void
    {
        $this->delete('api/v1/master/agama/5')->assertStatus(200);

        $this->sendJson('PATCH', 'api/v1/master/agama/5/order', ['order' => 1])->assertStatus(422);
        $this->sendJson('PUT', 'api/v1/master/agama/5', ['order' => 1])->assertStatus(422);
        $this->assertSame([1, 2, 3, 4, 5], $this->orders('agama', 'id_agama', ['1', '2', '3', '4', '6']));

        $this->sendJson('PUT', 'api/v1/master/agama/5', ['status' => '2', 'order' => 1])->assertStatus(200);
        $this->seeInDatabase('agama', ['id_agama' => 5, 'status' => 2, 'order' => 1, 'deleted_at' => null]);
        $this->assertSame([1, 2, 3, 4, 5, 6], $this->orders('agama', 'id_agama', ['5', '1', '2', '3', '4', '6']));
    }

    /**
     * Status yang dikirim eksplisit di PUT wajib 1/2: null/'' tidak boleh diam-diam mengaktifkan/memulihkan entri.
     */
    public function testUpdateRejectsEmptyOrNullStatus(): void
    {
        $this->sendJson('PATCH', 'api/v1/master/agama/4/status', ['status' => '2'])->assertStatus(200);

        $this->sendJson('PUT', 'api/v1/master/agama/4', ['status' => null])->assertStatus(422);
        $this->sendJson('PUT', 'api/v1/master/agama/4', ['status' => ''])->assertStatus(422);
        $this->seeInDatabase('agama', ['id_agama' => 4, 'status' => 2]);

        // Field status tidak dikirim = tidak berubah.
        $this->sendJson('PUT', 'api/v1/master/agama/4', ['agama' => 'Hindu Dharma'])->assertStatus(200);
        $this->seeInDatabase('agama', ['id_agama' => 4, 'status' => 2, 'agama' => 'Hindu Dharma']);
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

            $this->sendJson('PATCH', "api/v1/master/{$entity}/{$fx['existing']}/status", ['status' => '2'])->assertStatus(200);
            $this->assertNotContains($fx['existing'], $this->optionIds($entity, $parent), "{$entity}: tidak aktif harus hilang dari dropdown");

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

    public function testFourLevelCascadeOptions(): void
    {
        $this->assertSame(['31', '32'], $this->optionIds('provinsi'));
        $this->assertSame(['3171', '3172'], $this->optionIds('kabupaten-kota', '31'));
        $this->assertSame(['3273'], $this->optionIds('kabupaten-kota', '32'));
        $this->assertSame(['3171010', '3171020'], $this->optionIds('kecamatan', '3171'));
        $this->assertSame(['3171010001', '3171010002'], $this->optionIds('kelurahan', '3171010'));
        $this->assertSame([], $this->optionIds('kelurahan', '9999999'));

        $first = $this->json($this->get('api/v1/master/kelurahan/options', ['parent' => '3171010']))['data'][0];
        $this->assertSame(['id' => '3171010001', 'nama' => 'Gambir', 'parent' => '3171010'], $first);
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

        // Entri baru dengan order eksplisit disisipkan, bukan menimpa (id AUTO_INCREMENT berikutnya = 7).
        $this->sendJson('POST', 'api/v1/master/agama', ['agama' => 'Kepercayaan', 'order' => 2])->assertStatus(201);
        $this->assertSame(['2', '7', '3', '4', '1', '5', '6'], $this->optionIds('agama'));
    }

    /**
     * Urutan tampil hanya menghitung entri yang terlihat (status 10 disembunyikan di daftar default), sehingga posisi
     * 1..n yang dikirim FE sama dengan posisi di backend.
     */
    public function testReorderIgnoresDeletedEntries(): void
    {
        // Hapus Katolik (3) → urutan sisanya dirapatkan 1..5.
        $this->delete('api/v1/master/agama/3')->assertStatus(200);
        $this->assertSame([1, 2, 3, 4, 5], $this->orders('agama', 'id_agama', ['1', '2', '4', '5', '6']));

        // Posisi 3 di daftar yang terlihat = setelah Kristen Protestan (2).
        $this->sendJson('PATCH', 'api/v1/master/agama/6/order', ['order' => 3])->assertStatus(200);
        $this->assertSame(['1', '2', '6', '4', '5'], $this->optionIds('agama'));

        // Pulihkan → masuk kembali di akhir urutan.
        $this->sendJson('PATCH', 'api/v1/master/agama/3/status', ['status' => '1'])->assertStatus(200);
        $this->assertSame(['1', '2', '6', '4', '5', '3'], $this->optionIds('agama'));
        $this->assertSame([6], $this->orders('agama', 'id_agama', ['3']));
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
    // G-TC #6 — audit log tambah/ubah/hapus + kolom audit legacy
    // ------------------------------------------------------------------

    public function testAuditLogIsRecordedForCreateUpdateDeleteWithActor(): void
    {
        foreach (self::masterFixtures() as $entity => $fx) {
            $def     = service('masterRegistry')->get($entity);
            $created = $this->json($this->sendJson('POST', "api/v1/master/{$entity}", $fx['new']))['data'];
            $id      = (string) $created[$def->primaryKey];

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
            $this->assertSame('10', (string) $after['status'], "{$entity}: after_json delete harus status 10");
        }
    }

    /**
     * Kolom audit legacy (created_at, updated_at, updated_by) diisi aplikasi (UTC), bukan default DB:
     * waktu dibekukan ke nilai yang jauh dari jam server, updated_by = id_pengguna aktor.
     */
    public function testLegacyAuditColumnsAreFilledWithActor(): void
    {
        // Super Admin kedua dengan id_pengguna 77: tidak mungkin tertukar dengan kode role (1..8).
        $this->db->table('pengguna')->insert([
            'id_pengguna'     => 77, 'nip' => '198001012005011077', 'username' => '198001012005011077', 'password' => null,
            'password_legacy' => md5(AuthSeeder::PASSWORD), 'user_level' => Role::SUPER_ADMIN, 'id_unit' => 'U01',
            'id_satker'       => 'S01', 'status' => '1',
        ]);
        $this->asNip('198001012005011077');
        $adminId = 77;

        Time::setTestNow('2020-01-02 03:04:05', 'UTC');

        foreach (self::masterFixtures() as $entity => $fx) {
            $created = $this->json($this->sendJson('POST', "api/v1/master/{$entity}", $fx['new']))['data'];

            $this->assertSame('2020-01-02 03:04:05', $created['created_at'], $entity);
            $this->assertSame($adminId, (int) $created['updated_by'], $entity);
        }

        // Update tercatat di updated_at & updated_by (baris seed awalnya NULL).
        $this->seeInDatabase('provinsi', ['id_provinsi' => '32', 'updated_by' => null]);
        Time::setTestNow('2020-02-03 04:05:06', 'UTC');
        $this->sendJson('PUT', 'api/v1/master/provinsi/32', ['provinsi' => 'Jawa Barat Raya'])->assertStatus(200);
        $this->seeInDatabase('provinsi', ['id_provinsi' => '32', 'updated_by' => $adminId, 'updated_at' => '2020-02-03 04:05:06']);

        // Hapus agama mengisi deleted_at dengan jam aplikasi.
        $this->delete('api/v1/master/agama/1')->assertStatus(200);
        $this->seeInDatabase('agama', ['id_agama' => 1, 'deleted_at' => '2020-02-03 04:05:06']);
    }

    public function testLeadingZeroCodeIsPreservedInRouteAndAudit(): void
    {
        $this->sendJson('POST', 'api/v1/master/provinsi', ['id_provinsi' => '09', 'provinsi' => 'Uji Nol Depan'])->assertStatus(201);

        $result = $this->get('api/v1/master/provinsi/09');
        $result->assertStatus(200);
        $this->assertSame('09', $this->json($result)['data']['id_provinsi']);
        $this->get('api/v1/master/provinsi/9')->assertStatus(404);

        $this->sendJson('PUT', 'api/v1/master/provinsi/09', ['provinsi' => 'Uji Nol Depan (ubah)'])->assertStatus(200);
        $this->seeInDatabase('audit_logs', ['entity' => 'provinsi', 'entity_id' => '09', 'event' => 'create']);
        $this->seeInDatabase('audit_logs', ['entity' => 'provinsi', 'entity_id' => '09', 'event' => 'update']);
        $this->dontSeeInDatabase('audit_logs', ['entity' => 'provinsi', 'entity_id' => '9']);
    }

    public function testListSupportsSearchStatusParentFilterAndParentName(): void
    {
        $this->sendJson('PATCH', 'api/v1/master/agama/5/status', ['status' => '2'])->assertStatus(200);

        $all = $this->json($this->get('api/v1/master/agama'))['data'];
        $this->assertSame(6, $all['total']);
        $this->assertSame('1', (string) $all['items'][0]['id_agama']);

        $inactive = $this->json($this->get('api/v1/master/agama', ['status' => '2']))['data'];
        $this->assertSame(['5'], array_map('strval', array_column($inactive['items'], 'id_agama')));

        $search = $this->json($this->get('api/v1/master/agama', ['search' => 'kat']))['data'];
        $this->assertSame(['3'], array_map('strval', array_column($search['items'], 'id_agama')));

        $kec = $this->json($this->get('api/v1/master/kecamatan', ['parent' => '3171']))['data'];
        $this->assertSame(['3171010', '3171020'], array_column($kec['items'], 'id_kecamatan'));
        $this->assertSame('Jakarta Pusat', $kec['items'][0]['parent_nama']);

        $this->get('api/v1/master/agama/99')->assertStatus(404);
    }

    /**
     * Kode alternatif yang valid (panjang/format sama) untuk uji nama duplikat tanpa bentrok kode.
     */
    private static function altCode(MasterDefinition $def, string $code): string
    {
        if ($def->idDigits !== null) {
            return str_pad((string) ((int) $code + 1), $def->idDigits, '0', STR_PAD_LEFT);
        }

        return 'X' . substr($code, 1);
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
