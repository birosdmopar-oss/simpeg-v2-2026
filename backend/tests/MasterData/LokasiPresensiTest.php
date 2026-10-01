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

/**
 * DBV-007/CR-031 — G-03 Master Lokasi Presensi lewat engine master + LokasiPresensiHooks/AturanLokasiPresensiHooks.
 * G-TC generik (soft delete, toggle, audit, RBAC per endpoint) ikut lewat fixture di MasterGenericTcTest dan
 * RbacMasterEndpointsTest; test ini menguji aturan khusus G-03 (PRD G3-FR-02..15, K-4, K-5, K-8).
 *
 * Seed: lokasi 1 "Kantor Pusat" (-6.175392, 106.824964; tidak dirujuk aturan), lokasi 2 "Gedung Sapta Pesona"
 * (-6.175400, 106.827200; dirujuk aturan aktif 1). Jenis pegawai 1..3 aktif.
 *
 * @internal
 */
final class LokasiPresensiTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use AuthTestTrait;
    use MasterDataTestTrait;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = null;
    protected $seed      = MasterDataSeeder::class;

    private const LOKASI = 'api/v1/master/lokasi-presensi';
    private const ATURAN = 'api/v1/master/aturan-lokasi-presensi';

    /**
     * Tabel bantuan (unit/satker/web_config belum ada di main) yang dibuat test ini dan wajib dibuang.
     *
     * @var list<string>
     */
    private array $tempTables = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->clearAuthState();
        $this->resetMasterState();
        $this->asRole(Role::SUPER_ADMIN);
    }

    protected function tearDown(): void
    {
        foreach ($this->tempTables as $table) {
            $this->db->query('DROP TABLE IF EXISTS ' . $this->db->escapeIdentifiers($this->db->prefixTable($table)));
        }

        $this->tempTables = [];
        $this->clearAuthState();
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Lokasi presensi — radius & koordinat (G3-FR-02/03)
    // ------------------------------------------------------------------

    public function testRadiusMustBeAtLeastTenMeters(): void
    {
        $result = $this->sendJson('POST', self::LOKASI, $this->lokasi('Lokasi Radius Kecil', '-6.2', '106.8', '9.99'));
        $result->assertStatus(422);
        $this->assertArrayHasKey('radius', $this->json($result)['errors']);

        $result = $this->sendJson('POST', self::LOKASI, $this->lokasi('Lokasi Radius Pas', '-6.2', '106.8', '10'));
        $result->assertStatus(201);
        $this->assertSame(10.0, (float) $this->json($result)['data']['radius']);

        // Ubah ke bawah 10 juga ditolak.
        $id = (string) $this->json($result)['data']['id_lokasi_presensi'];
        $this->sendJson('PUT', self::LOKASI . "/{$id}", ['radius' => '9.99'])->assertStatus(422);
        $this->sendJson('PUT', self::LOKASI . "/{$id}", ['radius' => 'sepuluh'])->assertStatus(422);
        $this->seeInDatabase('lokasi_presensi', ['id_lokasi_presensi' => $id, 'radius' => 10]);
    }

    public function testCoordinateRangeIsInclusive(): void
    {
        $valid = [['90', '0'], ['-90', '1'], ['10', '180'], ['11', '-180']];

        foreach ($valid as $i => [$lat, $long]) {
            $this->sendJson('POST', self::LOKASI, $this->lokasi("Lokasi Batas {$i}", $lat, $long))->assertStatus(201);
        }

        $invalid = [
            'latitude'  => [['90.0001', '2'], ['-90.0001', '3'], ['abc', '4']],
            'longitude' => [['12', '180.0001'], ['13', '-180.0001'], ['14', '1e2']],
        ];

        foreach ($invalid as $field => $cases) {
            foreach ($cases as $i => [$lat, $long]) {
                $result = $this->sendJson('POST', self::LOKASI, $this->lokasi("Lokasi Salah {$field} {$i}", $lat, $long));
                $result->assertStatus(422);
                $this->assertArrayHasKey($field, $this->json($result)['errors'], "{$field} {$lat},{$long}");
            }
        }

        $this->assertSame(6, $this->db->table('lokasi_presensi')->countAllResults());
    }

    // ------------------------------------------------------------------
    // K-4 — koordinat unik (tambah, ubah, pemulihan), perbandingan numerik
    // ------------------------------------------------------------------

    public function testDuplicateCoordinateRejectedOnCreateAndUpdateComparedNumerically(): void
    {
        // Lokasi 2 tersimpan "-6.175400" / "106.827200": input "-6.1754" / "106.8272" bernilai sama.
        $result = $this->sendJson('POST', self::LOKASI, $this->lokasi('Gedung Kembar', '-6.1754', '106.8272'));
        $result->assertStatus(422);
        $this->assertStringContainsString('Gedung Sapta Pesona', $this->json($result)['errors']['latitude'][0]);

        // Ubah lokasi 1 ke koordinat lokasi 2 (satu kolom saja yang dikirim pun tetap dibanding pasangan finalnya).
        $this->sendJson('PUT', self::LOKASI . '/1', ['latitude' => '-6.17540', 'longitude' => '106.82720'])->assertStatus(422);
        $this->sendJson('PUT', self::LOKASI . '/1', ['latitude' => '-6.1754'])->assertStatus(200);
        $this->sendJson('PUT', self::LOKASI . '/1', ['longitude' => '106.8272000'])->assertStatus(422);
        $this->seeInDatabase('lokasi_presensi', ['id_lokasi_presensi' => 1, 'longitude' => '106.824964']);

        // Ubah tanpa menyentuh koordinat tetap boleh.
        $this->sendJson('PUT', self::LOKASI . '/1', ['nama_lokasi' => 'Kantor Pusat Baru', 'radius' => '75'])->assertStatus(200);

        // Latitude sama, longitude beda = bukan duplikat.
        $this->sendJson('POST', self::LOKASI, $this->lokasi('Gedung Sebelah', '-6.1754', '106.8273'))->assertStatus(201);
    }

    public function testCoordinateOfDeletedLocationCanBeReusedAndRestoreIsChecked(): void
    {
        $first = $this->json($this->sendJson('POST', self::LOKASI, $this->lokasi('Lokasi Lama', '-7.1', '110.1')))['data'];
        $oldId = (string) $first['id_lokasi_presensi'];
        $this->delete(self::LOKASI . "/{$oldId}")->assertStatus(200);
        $this->seeInDatabase('lokasi_presensi', ['id_lokasi_presensi' => $oldId, 'status' => 10]);

        // Koordinat milik baris status 10 boleh dipakai lagi (tambah dan ubah).
        $second = $this->sendJson('POST', self::LOKASI, $this->lokasi('Lokasi Baru', '-7.100', '110.10'));
        $second->assertStatus(201);
        $this->sendJson('PUT', self::LOKASI . '/1', ['latitude' => '-7.1', 'longitude' => '110.1'])->assertStatus(422);

        // Pulihkan baris lama → bentrok dengan lokasi baru: ditolak lewat PATCH status (1 maupun 2) dan PUT status.
        foreach (['1', '2'] as $status) {
            $result = $this->sendJson('PATCH', self::LOKASI . "/{$oldId}/status", ['status' => $status]);
            $result->assertStatus(422);
            $this->assertArrayHasKey('latitude', $this->json($result)['errors']);
        }

        $this->sendJson('PUT', self::LOKASI . "/{$oldId}", ['status' => '1'])->assertStatus(422);
        $this->seeInDatabase('lokasi_presensi', ['id_lokasi_presensi' => $oldId, 'status' => 10]);

        // Pulihkan sambil memindah koordinat (PUT status + koordinat baru) boleh: dicek terhadap koordinat finalnya.
        $this->sendJson('PUT', self::LOKASI . "/{$oldId}", ['status' => '1', 'latitude' => '-7.2', 'longitude' => '110.2'])->assertStatus(200);
        $this->seeInDatabase('lokasi_presensi', ['id_lokasi_presensi' => $oldId, 'status' => 1, 'latitude' => '-7.2']);

        // Tanpa bentrok, pemulihan biasa lewat PATCH status boleh.
        $newId = (string) $this->json($second)['data']['id_lokasi_presensi'];
        $this->delete(self::LOKASI . "/{$newId}")->assertStatus(200);
        $this->sendJson('PATCH', self::LOKASI . "/{$newId}/status", ['status' => '1'])->assertStatus(200);
    }

    // ------------------------------------------------------------------
    // K-5 — lokasi yang dirujuk aturan aktif
    // ------------------------------------------------------------------

    public function testLocationReferencedByActiveRuleCannotBeDeactivatedOrDeleted(): void
    {
        $result = $this->sendJson('PATCH', self::LOKASI . '/2/status', ['status' => '2']);
        $result->assertStatus(422);
        $message = $this->json($result)['errors']['status'][0];
        $this->assertStringContainsString('dinonaktifkan', $message);
        $this->assertStringContainsString('#1 (Aturan kantor pusat)', $message);

        $result = $this->delete(self::LOKASI . '/2');
        $result->assertStatus(422);
        $this->assertStringContainsString('dihapus', $this->json($result)['errors']['status'][0]);

        $this->sendJson('PUT', self::LOKASI . '/2', ['status' => '2'])->assertStatus(422);
        $this->seeInDatabase('lokasi_presensi', ['id_lokasi_presensi' => 2, 'status' => 1]);

        // Data legacy: target_lp angka (bukan teks) dan ber-addslashes tetap terbaca sebagai rujukan.
        $this->db->table('dm_user_lokasi_presensi')->insert([
            'target_lp' => '[1]', 'target_lp_desc' => '[\"Kantor Pusat\"]', 'keterangan' => 'Impor legacy', 'status' => 1,
        ]);
        $this->sendJson('PATCH', self::LOKASI . '/1/status', ['status' => '2'])->assertStatus(422);
        $this->db->table('dm_user_lokasi_presensi')->where('keterangan', 'Impor legacy')->update(['target_lp' => '[\"1\"]']);
        $this->delete(self::LOKASI . '/1')->assertStatus(422);
        $this->db->table('dm_user_lokasi_presensi')->where('keterangan', 'Impor legacy')->delete();

        // Aturan perujuk dinonaktifkan → lokasi boleh dinonaktifkan; aturan tidak bisa diaktifkan lagi selama lokasinya
        // tidak aktif (PATCH maupun PUT status).
        $this->sendJson('PATCH', self::ATURAN . '/1/status', ['status' => '2'])->assertStatus(200);
        $this->sendJson('PATCH', self::LOKASI . '/2/status', ['status' => '2'])->assertStatus(200);

        $result = $this->sendJson('PATCH', self::ATURAN . '/1/status', ['status' => '1']);
        $result->assertStatus(422);
        $this->assertStringContainsString('ID 2', $this->json($result)['errors']['status'][0]);
        $this->sendJson('PUT', self::ATURAN . '/1', ['status' => '1'])->assertStatus(422);

        // Aturan yang dihapus juga tidak merujuk: lokasi boleh dihapus, aturan tidak bisa dipulihkan ke aktif.
        $this->delete(self::ATURAN . '/1')->assertStatus(200);
        $this->delete(self::LOKASI . '/2')->assertStatus(200);
        $this->sendJson('PATCH', self::ATURAN . '/1/status', ['status' => '1'])->assertStatus(422);
        $this->sendJson('PATCH', self::ATURAN . '/1/status', ['status' => '2'])->assertStatus(200);
    }

    // ------------------------------------------------------------------
    // Aturan penargetan — format, normalisasi, *_desc (G3-FR-11..13)
    // ------------------------------------------------------------------

    public function testRuleCreateNormalizesAndFillsDescriptionsWithNames(): void
    {
        $result = $this->sendJson('POST', self::ATURAN, $this->aturan([
            'target_lp'      => '[1, "2", "1"]',
            'target_jp'      => '["2", 1]',
            'hari_berlaku'   => '5,1,3,3',
            'target_lp_desc' => 'nilai kiriman klien diabaikan',
        ]));
        $result->assertStatus(201);
        $row = $this->json($result)['data'];

        $this->assertSame('["1","2"]', $row['target_lp']);
        $this->assertSame('["Kantor Pusat","Gedung Sapta Pesona"]', $row['target_lp_desc']);
        $this->assertSame('["0"]', $row['target_uns']);
        $this->assertSame('["Seluruh Kementerian"]', $row['target_uns_desc']);
        $this->assertSame('["2","1"]', $row['target_jp']);
        $this->assertSame('["Pegawai Pemerintah dengan Perjanjian Kerja","Pegawai Negeri Sipil"]', $row['target_jp_desc']);
        $this->assertSame('1,3,5', $row['hari_berlaku']);
        $this->assertSame('1', (string) $row['status']);
        $this->assertNull($row['target_peg']);
        $this->assertNull($row['excl_peg']);

        // Dua aturan boleh menargetkan lokasi yang sama (tidak ada keunikan nama turunan); hari kosong = NULL.
        $result = $this->sendJson('POST', self::ATURAN, $this->aturan(['hari_berlaku' => '']));
        $result->assertStatus(201);
        $this->assertNull($this->json($result)['data']['hari_berlaku']);
        $this->assertSame('["Gedung Sapta Pesona"]', $this->json($result)['data']['target_lp_desc']);
    }

    public function testHariBerlakuOnlyAcceptsIsoDays(): void
    {
        foreach (['0', '8', 'a', '1,8', '1,,2', '1;2', ' ,1'] as $hari) {
            $result = $this->sendJson('POST', self::ATURAN, $this->aturan(['hari_berlaku' => $hari]));
            $result->assertStatus(422);
            $this->assertArrayHasKey('hari_berlaku', $this->json($result)['errors'], "hari '{$hari}'");
        }

        $this->sendJson('PUT', self::ATURAN . '/1', ['hari_berlaku' => '7,6,7'])->assertStatus(200);
        $this->seeInDatabase('dm_user_lokasi_presensi', ['id_dm_user_lokasi_presensi' => 1, 'hari_berlaku' => '6,7']);
        $this->sendJson('PUT', self::ATURAN . '/1', ['hari_berlaku' => '8'])->assertStatus(422);
        $this->sendJson('PUT', self::ATURAN . '/1', ['hari_berlaku' => ''])->assertStatus(200);
        $this->seeInDatabase('dm_user_lokasi_presensi', ['id_dm_user_lokasi_presensi' => 1, 'hari_berlaku' => null]);
    }

    /**
     * JSON target kosong/rusak/bukan list/non-skalar dan nilai non-teks di payload → 422 per field, tidak pernah 500.
     */
    public function testMalformedTargetJsonIsRejectedWith422(): void
    {
        $bad = ['', '   ', '[]', '{}', 'null', '"1"', '1', '{bad', '[1,', '[[1]]', '[{"id":1}]', '{"a":"1"}', '[1.5]', '[true]', '[null]', '["", "1"]', '["01"]', '["-1"]', '["99999999999"]'];

        foreach (['target_lp', 'target_uns', 'target_jp'] as $field) {
            foreach ($bad as $value) {
                $result = $this->sendJson('POST', self::ATURAN, $this->aturan([$field => $value]));
                $result->assertStatus(422);
                $this->assertArrayHasKey($field, $this->json($result)['errors'], "{$field} = {$value}");

                $this->sendJson('PUT', self::ATURAN . '/1', [$field => $value])->assertStatus(422);
            }

            // Nilai JSON non-teks di body (array/objek) → 422 dari controller.
            foreach ([[1], ['a' => '1'], [[1]]] as $value) {
                $result = $this->sendJson('POST', self::ATURAN, $this->aturan([$field => $value]));
                $result->assertStatus(422);
                $this->assertArrayHasKey($field, $this->json($result)['errors']);
            }
        }

        // Field wajib tidak dikirim saat tambah.
        foreach (['target_lp', 'target_uns', 'target_jp'] as $field) {
            $payload = $this->aturan();
            unset($payload[$field]);
            $this->sendJson('POST', self::ATURAN, $payload)->assertStatus(422);
        }

        $this->assertSame(1, $this->db->table('dm_user_lokasi_presensi')->countAllResults());
        $this->seeInDatabase('dm_user_lokasi_presensi', ['id_dm_user_lokasi_presensi' => 1, 'target_lp' => '["2"]', 'target_uns' => '["0"]', 'target_jp' => '["1"]']);
    }

    public function testTargetLocationAndJenisPegawaiMustExistAndBeActive(): void
    {
        $this->assertTargetRejected('target_lp', '["99"]', '99');
        $this->assertTargetRejected('target_jp', '["99"]', '99');

        // Lokasi tidak aktif / dihapus.
        $this->sendJson('PATCH', self::LOKASI . '/1/status', ['status' => '2'])->assertStatus(200);
        $this->assertTargetRejected('target_lp', '["2","1"]', '1');
        $this->delete(self::LOKASI . '/1')->assertStatus(200);
        $this->assertTargetRejected('target_lp', '["1"]', '1');

        // Jenis pegawai tidak aktif / dihapus.
        $this->sendJson('PATCH', 'api/v1/master/jenis-pegawai/3/status', ['status' => '2'])->assertStatus(200);
        $this->assertTargetRejected('target_jp', '["1","3"]', '3');

        // K-8: jenis pegawai id 7 tidak dapat dipilih walaupun ada dan aktif.
        $this->db->table('jenis_pegawai')->insert(['id_jenis_pegawai' => 7, 'jenis_pegawai' => 'Jenis Tujuh', 'order' => 7, 'status' => 1]);
        $result = $this->sendJson('POST', self::ATURAN, $this->aturan(['target_jp' => '["1","7"]']));
        $result->assertStatus(422);
        $this->assertStringContainsString('ID 7', $this->json($result)['errors']['target_jp'][0]);

        // Ubah aturan ke lokasi/jenis pegawai tak dikenal juga ditolak, data tidak berubah.
        $this->sendJson('PUT', self::ATURAN . '/1', ['target_lp' => '["1"]'])->assertStatus(422);
        $this->sendJson('PUT', self::ATURAN . '/1', ['target_jp' => '["7"]'])->assertStatus(422);
        $this->seeInDatabase('dm_user_lokasi_presensi', ['id_dm_user_lokasi_presensi' => 1, 'target_lp' => '["2"]', 'target_jp' => '["1"]']);
    }

    /**
     * Tabel unit/satker/web_config belum ada di main (G-02/G-09): hanya `0` yang diterima, desc "Seluruh Kementerian".
     */
    public function testTargetUnitSatkerOnlyAcceptsWholeMinistryWhileUnitTablesAreMissing(): void
    {
        $this->assertFalse($this->db->tableExists($this->db->prefixTable('unit'), false));
        $this->assertFalse($this->db->tableExists($this->db->prefixTable('satker'), false));

        foreach (['["5"]', '["sat_1"]', '["5","sat_1"]'] as $value) {
            $result = $this->sendJson('POST', self::ATURAN, $this->aturan(['target_uns' => $value]));
            $result->assertStatus(422);
            $this->assertStringContainsString('tidak dikenal', $this->json($result)['errors']['target_uns'][0]);
        }

        foreach (['["sat_"]', '["sat_x"]', '["satker_1"]', '["abc"]', '["0x1"]'] as $value) {
            $result = $this->sendJson('POST', self::ATURAN, $this->aturan(['target_uns' => $value]));
            $result->assertStatus(422);
            $this->assertArrayHasKey('target_uns', $this->json($result)['errors']);
        }

        // Legacy: "Seluruh Kementerian" (0) menghapus pilihan unit/satker lain.
        $result = $this->sendJson('POST', self::ATURAN, $this->aturan(['target_uns' => '["sat_1", 0]']));
        $result->assertStatus(201);
        $this->assertSame(['["0"]', '["Seluruh Kementerian"]'], [$this->json($result)['data']['target_uns'], $this->json($result)['data']['target_uns_desc']]);
    }

    /**
     * Setelah tabel unit/satker/web_config tersedia (G-02/G-09), id unit, `sat_<id>`, dan nama kementerian dibaca dari
     * tabelnya — desc berisi nama, tidak pernah "Satker <id>".
     */
    public function testTargetUnitSatkerUsesUnitSatkerAndWebConfigNamesWhenAvailable(): void
    {
        $this->createTempTable('unit', '`id_unit` INT NOT NULL PRIMARY KEY, `unit` VARCHAR(150) NOT NULL, `status` TINYINT NOT NULL DEFAULT 1');
        $this->createTempTable('satker', '`id_satker` INT NOT NULL PRIMARY KEY, `id_unit` INT NULL, `satker` VARCHAR(150) NOT NULL, `status` TINYINT NOT NULL DEFAULT 1');
        $this->createTempTable('web_config', '`id_web_config` INT NOT NULL AUTO_INCREMENT PRIMARY KEY, `config_name` VARCHAR(255) NOT NULL, `config_value` TEXT NULL');

        $this->db->table('unit')->insertBatch([['id_unit' => 3, 'unit' => 'Sekretariat Kementerian', 'status' => 1], ['id_unit' => 4, 'unit' => 'Unit Nonaktif', 'status' => 2]]);
        $this->db->table('satker')->insertBatch([['id_satker' => 3, 'id_unit' => 3, 'satker' => 'Biro SDM', 'status' => 1], ['id_satker' => 9, 'id_unit' => 3, 'satker' => 'Satker Dihapus', 'status' => 10]]);

        // id unit 3 dan satker 3 sama angkanya: desc tetap memakai nama yang benar per jenis.
        $result = $this->sendJson('POST', self::ATURAN, $this->aturan(['target_uns' => '["sat_3","3"]']));
        $result->assertStatus(201);
        $this->assertSame('["sat_3","3"]', $this->json($result)['data']['target_uns']);
        $this->assertSame('["Biro SDM","Sekretariat Kementerian"]', $this->json($result)['data']['target_uns_desc']);

        $this->assertTargetRejected('target_uns', '["4"]', '4');
        $this->assertTargetRejected('target_uns', '["sat_9"]', 'sat_9');
        $this->assertTargetRejected('target_uns', '["sat_4"]', 'sat_4');

        // `0` → nama kementerian dari web_config (bila terisi), selain itu teks bawaan.
        $this->db->table('web_config')->insert(['config_name' => 'nama_kementerian', 'config_value' => 'Kementerian Uji Coba']);
        $this->sendJson('PUT', self::ATURAN . '/1', ['target_uns' => '[0]'])->assertStatus(200);
        $this->seeInDatabase('dm_user_lokasi_presensi', ['id_dm_user_lokasi_presensi' => 1, 'target_uns' => '["0"]', 'target_uns_desc' => '["Kementerian Uji Coba"]']);
    }

    public function testRuleUpdateOnlyRecomputesChangedTargetsAndIgnoresClientDescriptions(): void
    {
        // Data impor legacy (desc lama) tetap utuh saat hanya keterangan yang diubah.
        $this->db->table('dm_user_lokasi_presensi')->where('id_dm_user_lokasi_presensi', 1)->update(['target_uns_desc' => '["Kemenparekraf"]']);

        $this->sendJson('PUT', self::ATURAN . '/1', ['keterangan' => 'Diubah', 'target_lp_desc' => 'palsu', 'target_jp_desc' => 'palsu'])->assertStatus(200);
        $this->seeInDatabase('dm_user_lokasi_presensi', [
            'id_dm_user_lokasi_presensi' => 1, 'keterangan' => 'Diubah', 'target_lp_desc' => '["Gedung Sapta Pesona"]',
            'target_uns_desc'            => '["Kemenparekraf"]', 'target_jp_desc' => '["Pegawai Negeri Sipil"]',
        ]);

        $this->sendJson('PUT', self::ATURAN . '/1', ['target_lp' => '["1","2"]', 'target_jp' => '["3"]'])->assertStatus(200);
        $this->seeInDatabase('dm_user_lokasi_presensi', [
            'id_dm_user_lokasi_presensi' => 1, 'target_lp' => '["1","2"]', 'target_lp_desc' => '["Kantor Pusat","Gedung Sapta Pesona"]',
            'target_jp'                  => '["3"]', 'target_jp_desc' => '["Pegawai Tidak Tetap"]', 'target_uns_desc' => '["Kemenparekraf"]',
        ]);

        // Target wajib tidak boleh dikosongkan lewat ubah.
        foreach (['target_lp', 'target_uns', 'target_jp'] as $field) {
            $this->sendJson('PUT', self::ATURAN . '/1', [$field => ''])->assertStatus(422);
        }
    }

    // ------------------------------------------------------------------
    // Hak akses — hanya Super Admin (role 1); dropdown lokasi untuk semua role login
    // ------------------------------------------------------------------

    public function testOnlySuperAdminCanManageG03(): void
    {
        foreach (array_diff(Role::all(), [Role::SUPER_ADMIN]) as $role) {
            $this->asRole($role);

            foreach ([self::LOKASI, self::ATURAN] as $base) {
                $this->get($base)->assertStatus(403);
                $this->get("{$base}/1")->assertStatus(403);
                $this->sendJson('PUT', "{$base}/1", ['keterangan' => 'x', 'nama_lokasi' => 'x'])->assertStatus(403);
                $this->sendJson('PATCH', "{$base}/1/status", ['status' => '2'])->assertStatus(403);
                $this->delete("{$base}/1")->assertStatus(403);
            }

            $this->sendJson('POST', self::LOKASI, $this->lokasi('Lokasi Role ' . $role, '-1.' . $role, '101'))->assertStatus(403);
            $this->sendJson('POST', self::ATURAN, $this->aturan())->assertStatus(403);

            // Dropdown lokasi aktif terbuka untuk semua role login (G3-FR-07).
            $this->assertSame(['2', '1'], $this->optionIds('lokasi-presensi'), "role {$role}"); // urut nama
        }

        $this->seeInDatabase('lokasi_presensi', ['id_lokasi_presensi' => 1, 'status' => 1, 'nama_lokasi' => 'Kantor Pusat']);
        $this->assertSame(2, $this->db->table('lokasi_presensi')->countAllResults());
        $this->assertSame(1, $this->db->table('dm_user_lokasi_presensi')->countAllResults());
        $this->dontSeeInDatabase('audit_logs', ['entity' => 'lokasi_presensi']);
        $this->dontSeeInDatabase('audit_logs', ['entity' => 'dm_user_lokasi_presensi']);
    }

    /**
     * @return array<string, string>
     */
    private function lokasi(string $nama, string $latitude, string $longitude, string $radius = '50'): array
    {
        return ['nama_lokasi' => $nama, 'latitude' => $latitude, 'longitude' => $longitude, 'radius' => $radius];
    }

    /**
     * @param array<string, mixed> $override
     *
     * @return array<string, mixed>
     */
    private function aturan(array $override = []): array
    {
        return $override + ['target_lp' => '["2"]', 'target_uns' => '["0"]', 'target_jp' => '["1"]', 'hari_berlaku' => '1,2,3,4,5', 'keterangan' => 'Aturan uji'];
    }

    private function assertTargetRejected(string $field, string $value, string $unknown): void
    {
        $result = $this->sendJson('POST', self::ATURAN, $this->aturan([$field => $value]));
        $result->assertStatus(422);
        $message = $this->json($result)['errors'][$field][0] ?? '';
        $this->assertStringContainsString('tidak dikenal atau tidak aktif', $message, "{$field} = {$value}");
        $this->assertStringContainsString($unknown, $message);
    }

    private function createTempTable(string $table, string $columns): void
    {
        $this->tempTables[] = $table;
        $this->db->query('CREATE TABLE ' . $this->db->escapeIdentifiers($this->db->prefixTable($table)) . " ({$columns}) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
}
