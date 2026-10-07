<?php

declare(strict_types=1);

namespace Tests\MasterData;

use App\Constants\Role;
use App\Libraries\CacheService;
use App\Libraries\MasterData\WebConfigService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;
use Config\Services;
use Config\WebConfig;
use InvalidArgumentException;
use Tests\Support\AuthTestTrait;
use Tests\Support\Database\Seeds\AuthSeeder;

/**
 * G-09 — Web Config (DBV-006/CR-030): RBAC role 1 saja (tanpa endpoint baca publik), daftar katalog + nilai bawaan,
 * simpan bertipe (upsert, 201/200, MTC-012 tipe salah → 422), key ber-`/`, hapus = kembali bawaan, key tak dikenal
 * hasil impor (read-only, bisa dihapus), audit_logs + `updated_by`, dan invalidasi cache value()/values().
 *
 * @internal
 */
final class WebConfigTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use AuthTestTrait;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = null;
    protected $seed      = AuthSeeder::class;

    private const BASE = 'api/v1/web-config';

    private const ADMIN_NIP = '198501012010011001';

    protected function setUp(): void
    {
        parent::setUp();
        $this->clearAuthState();
        $this->resetWebConfigServices();
        $this->asRole(Role::SUPER_ADMIN);
    }

    protected function tearDown(): void
    {
        $this->resetWebConfigServices();
        $this->clearAuthState();
        parent::tearDown();
    }

    public function testOnlySuperAdminCanReadOrWrite(): void
    {
        $this->assertSame([Role::SUPER_ADMIN], WebConfigService::WRITE_ROLES);

        $calls = [
            ['GET', self::BASE, []],
            ['GET', self::BASE . '/nama_kementerian', []],
            ['PUT', self::BASE . '/nama_kementerian', ['config_value' => 'Diubah']],
            ['DELETE', self::BASE . '/nama_kementerian', []],
        ];

        foreach (array_diff(Role::all(), [Role::SUPER_ADMIN]) as $role) {
            foreach ($calls as [$method, $uri, $body]) {
                $result = $this->asRole($role)->sendJson($method, $uri, $body);
                $result->assertStatus(403);
                $this->assertSame(['status' => 'error', 'message' => 'Forbidden'], $this->json($result), "{$method} {$uri} role {$role}");
            }
        }

        foreach ($calls as [$method, $uri, $body]) {
            $this->withHeaders(['Authorization' => ''])->sendJson($method, $uri, $body)->assertStatus(401);
        }

        $this->assertSame(0, $this->db->table('web_config')->countAllResults());
        $this->dontSeeInDatabase('audit_logs', ['entity' => 'web_config']);

        $this->asRole(Role::SUPER_ADMIN)->get(self::BASE)->assertStatus(200);
    }

    public function testListShowsWholeCatalogWithDefaults(): void
    {
        $items   = $this->listItems();
        $catalog = config(WebConfig::class)->keys;

        $this->assertSame(array_keys($catalog), array_column($items, 'config_name'));
        $this->assertCount(35, $items, '24 key baseline + pdf_header_nama_unit (Slip Gaji) + 10 key potongan tukin/uang makan');

        $byName = array_column($items, null, 'config_name');
        $this->assertTrue($byName['email_sent_time']['is_default']);
        $this->assertSame('06:00:00', $byName['email_sent_time']['effective']);
        $this->assertNull($byName['uang_makan_gol_3']['effective']);
        $this->assertSame('integer', $byName['uang_makan_gol_3']['type']);
        $this->assertSame(['min' => 0, 'max' => 10_000_000], $byName['uang_makan_gol_3']['constraints']);
        $this->assertSame(['min' => 0, 'max' => 100, 'scale' => 2], $byName['TL1/PSW1']['constraints']);
        $this->assertSame(['ref' => 'unit'], $byName['id_unit_kepegawaian']['constraints']);
        $this->assertTrue($byName['email_ultah_ad']['personal']);
        $this->assertTrue($byName['nama_kementerian']['known']);
    }

    /**
     * MTC-012: ubah tarif uang makan → tersimpan dan langsung terbaca; isi teks di field integer → ditolak.
     */
    public function testSetCreatesThenUpdatesAndInvalidatesCache(): void
    {
        $service = service('webConfigService');
        $this->assertNull($service->value('uang_makan_gol_3'));

        $created = $this->sendJson('PUT', self::BASE . '/uang_makan_gol_3', ['config_value' => '37000']);
        $created->assertStatus(201);
        $item = $this->json($created)['data'];
        $this->assertSame('37000', $item['config_value']);
        $this->assertFalse($item['is_default']);
        $this->assertSame('Golongan III', $item['remark'], 'remark baris baru = label katalog');

        $row = $this->db->table('web_config')->where('config_name', 'uang_makan_gol_3')->get()->getRowArray();
        $this->assertSame($this->adminId(), (int) $row['updated_by']);
        $this->seeInDatabase('audit_logs', ['entity' => 'web_config', 'entity_id' => (string) $row['id_web_config'], 'event' => 'create', 'nip_actor' => self::ADMIN_NIP]);
        $this->assertSame(37000, $service->value('uang_makan_gol_3'));

        // Angka JSON juga diterima; nilai baru langsung terbaca (cache diinvalidasi).
        $this->sendJson('PUT', self::BASE . '/uang_makan_gol_3', ['config_value' => 38500, 'remark' => '  Tarif   2026 '])->assertStatus(200);
        $this->assertSame(38500, $service->value('uang_makan_gol_3'));
        $this->assertSame(38500, $service->values()['uang_makan_gol_3']);
        $this->seeInDatabase('web_config', ['config_name' => 'uang_makan_gol_3', 'config_value' => '38500', 'remark' => 'Tarif 2026']);
        $this->seeInDatabase('audit_logs', ['entity' => 'web_config', 'entity_id' => (string) $row['id_web_config'], 'event' => 'update']);

        // Tipe salah → 422 di field config_value, nilai lama tetap.
        foreach (['tiga puluh ribu', '37.000', '-1', '10000001', ''] as $bad) {
            $result = $this->sendJson('PUT', self::BASE . '/uang_makan_gol_3', ['config_value' => $bad]);
            $result->assertStatus(422);
            $this->assertArrayHasKey('config_value', $this->json($result)['errors'], $bad);
        }

        $this->sendJson('PUT', self::BASE . '/uang_makan_gol_3', [])->assertStatus(422);
        $this->sendJson('PUT', self::BASE . '/uang_makan_gol_3', ['config_value' => '1', 'remark' => str_repeat('x', 256)])->assertStatus(422);
        $this->assertSame(38500, $service->value('uang_makan_gol_3'));
        $this->assertSame(1, $this->db->table('web_config')->countAllResults());
    }

    public function testKeyWithSlashAndDecimalNormalization(): void
    {
        $result = $this->sendJson('PUT', self::BASE . '/TL1/PSW1', ['config_value' => '0.50']);
        $result->assertStatus(201);
        $this->assertSame('0.5', $this->json($result)['data']['config_value']);
        $this->assertSame(0.5, service('webConfigService')->value('TL1/PSW1'));

        $this->get(self::BASE . '/TL1/PSW1')->assertStatus(200);
        $this->assertSame('0.5', $this->json($this->get(self::BASE . '/TL1/PSW1'))['data']['effective']);

        foreach (['1,5', '1.555', '101', 'abc'] as $bad) {
            $this->sendJson('PUT', self::BASE . '/TL1/PSW1', ['config_value' => $bad])->assertStatus(422);
        }

        $this->get(self::BASE . '/TL1')->assertStatus(404);
        $this->get(self::BASE . '/tidak_ada')->assertStatus(404);
        $this->sendJson('PUT', self::BASE . '/tidak_ada', ['config_value' => 'x'])->assertStatus(404);
        $this->sendJson('PUT', self::BASE . '/TL1/PSW1/lebih', ['config_value' => 'x'])->assertStatus(404);
    }

    public function testHtmlIsSanitizedAndOtherTypes(): void
    {
        $html = $this->sendJson('PUT', self::BASE . '/pdf_header_nama_kementerian', ['config_value' => 'KEMENTERIAN<br/>PARIWISATA<script>alert(1)</script>']);
        $html->assertStatus(201);
        $this->assertSame('KEMENTERIAN<br />PARIWISATA', $this->json($html)['data']['config_value']);

        $this->sendJson('PUT', self::BASE . '/pdf_header_nama_kementerian', ['config_value' => '<script>x</script>'])->assertStatus(422);
        $this->sendJson('PUT', self::BASE . '/email_sent_time', ['config_value' => '7:00'])->assertStatus(422);
        $this->sendJson('PUT', self::BASE . '/email_sent_time', ['config_value' => '07:30'])->assertStatus(201);
        $this->assertSame('07:30:00', service('webConfigService')->value('email_sent_time'));
        $this->sendJson('PUT', self::BASE . '/logo_kementerian_pdf', ['config_value' => '/var/www/html/assets/logo.png'])->assertStatus(422);
        $this->sendJson('PUT', self::BASE . '/logo_kementerian_pdf', ['config_value' => 'kop/logo-kiri.png'])->assertStatus(201);
        $this->sendJson('PUT', self::BASE . '/nama_kementerian', ['config_value' => "Baris\nKedua"])->assertStatus(422);
        $this->sendJson('PUT', self::BASE . '/id_unit_kepegawaian', ['config_value' => '08'])->assertStatus(422);
        $this->sendJson('PUT', self::BASE . '/id_unit_kepegawaian', ['config_value' => '8'])->assertStatus(201);
        $this->assertSame(8, service('webConfigService')->value('id_unit_kepegawaian'));
    }

    public function testDeleteRestoresDefault(): void
    {
        $this->sendJson('PUT', self::BASE . '/email_sent_time', ['config_value' => '08:00'])->assertStatus(201);
        $service = service('webConfigService');
        $this->assertSame('08:00:00', $service->value('email_sent_time'));
        $id = (string) $this->db->table('web_config')->get()->getRowArray()['id_web_config'];

        $result = $this->sendJson('DELETE', self::BASE . '/email_sent_time');
        $result->assertStatus(200);
        $item = $this->json($result)['data']['item'];
        $this->assertTrue($item['is_default']);
        $this->assertSame('06:00:00', $item['effective']);
        $this->assertSame('06:00:00', $service->value('email_sent_time'));
        $this->assertSame(0, $this->db->table('web_config')->countAllResults(), 'hard delete');
        $this->seeInDatabase('audit_logs', ['entity' => 'web_config', 'entity_id' => $id, 'event' => 'delete', 'nip_actor' => self::ADMIN_NIP]);

        $this->sendJson('DELETE', self::BASE . '/email_sent_time')->assertStatus(404);
        $this->sendJson('DELETE', self::BASE . '/tidak_ada')->assertStatus(404);
    }

    /**
     * Baris key tak dikenal (hasil impor yang diputuskan TL) tampil read-only di akhir daftar dan bisa dihapus.
     */
    public function testUnknownImportedKeyIsReadOnlyButDeletable(): void
    {
        $this->db->table('web_config')->insert(['config_name' => 'key_lama_tidak_dipakai', 'config_value' => 'x', 'remark' => 'impor']);

        $items = $this->listItems();
        $last  = $items[array_key_last($items)];
        $this->assertSame('key_lama_tidak_dipakai', $last['config_name']);
        $this->assertFalse($last['known']);
        $this->assertNull($last['type']);

        $this->sendJson('PUT', self::BASE . '/key_lama_tidak_dipakai', ['config_value' => 'y'])->assertStatus(422);
        $this->get(self::BASE . '/key_lama_tidak_dipakai')->assertStatus(200);

        $deleted = $this->sendJson('DELETE', self::BASE . '/key_lama_tidak_dipakai');
        $deleted->assertStatus(200);
        $this->assertTrue($this->json($deleted)['data']['item']['deleted']);
        $this->assertSame(0, $this->db->table('web_config')->countAllResults());

        $this->expectException(InvalidArgumentException::class);
        service('webConfigService')->value('key_lama_tidak_dipakai');
    }

    /**
     * Nilai tersimpan yang tidak sah (mis. impor tanpa normalisasi) ditandai `valid: false` dan konsumen menerima bawaan.
     */
    public function testInvalidStoredValueFallsBackToDefault(): void
    {
        $this->db->table('web_config')->insert(['config_name' => 'TK', 'config_value' => '3,5']);
        $this->db->table('web_config')->insert(['config_name' => 'email_sent_time', 'config_value' => 'jam enam']);

        $byName = array_column($this->listItems(), null, 'config_name');
        $this->assertFalse($byName['TK']['valid']);
        $this->assertNull($byName['TK']['effective']);
        $this->assertSame('06:00:00', $byName['email_sent_time']['effective']);
        $this->assertNull(service('webConfigService')->value('TK'));

        // Diperbaiki lewat PUT (baris yang sama di-update).
        $this->sendJson('PUT', self::BASE . '/TK', ['config_value' => '3.5'])->assertStatus(200);
        $this->assertSame(3.5, service('webConfigService')->value('TK'));
    }

    // ------------------------------------------------------------------

    /**
     * @return list<array<string, mixed>>
     */
    private function listItems(): array
    {
        $result = $this->get(self::BASE);
        $result->assertStatus(200);

        return $this->json($result)['data']['items'];
    }

    /**
     * @param array<string, mixed> $body
     */
    private function sendJson(string $method, string $uri, array $body = []): TestResponse
    {
        return $this->withBodyFormat('json')->call($method, $uri, $body);
    }

    private function resetWebConfigServices(): void
    {
        foreach (['webConfigService', 'cacheService', 'htmlSanitizer'] as $name) {
            Services::resetSingle($name);
        }

        Services::injectMock('cacheService', new CacheService(service('cache'), ''));
    }

    private function adminId(): int
    {
        return (int) $this->db->table('pengguna')->select('id_pengguna')->where('nip', self::ADMIN_NIP)->get()->getRowArray()['id_pengguna'];
    }
}
