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
 * G-09 — Web Config (key-value). DoD: tipe data per key distandarkan (bukan free-text semua) dan perubahan
 * nilai langsung ter-reflect (cache invalidate). MTC-012.
 *
 * @internal
 */
final class WebConfigTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use AuthTestTrait;
    use MasterDataTestTrait;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = null;
    protected $seed      = MasterDataSeeder::class;

    private const URI = 'api/v1/master/web-config';

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
     * MTC-012: nilai dengan tipe tidak sesuai ditolak.
     *
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function invalidValues(): iterable
    {
        yield 'teks di field integer' => ['tarif_uang_makan_pns', 'empat puluh ribu'];
        yield 'desimal di field integer' => ['tarif_uang_makan_pns', '41000.5'];
        yield 'teks di field decimal' => ['potongan_tl1', 'setengah'];
        yield 'koma di field decimal' => ['potongan_tl1', '0,5'];
        yield 'jam tidak valid' => ['jam_masuk_normal', '25:00'];
        yield 'format jam salah' => ['jam_masuk_normal', '7.30'];
        yield 'boolean bukan 0/1' => ['presensi_wajib_foto', 'kadang'];
        yield 'nilai kosong' => ['header_pdf', ''];
    }

    /**
     * @dataProvider invalidValues
     */
    public function testValueIsValidatedAgainstItsType(string $key, string $value): void
    {
        $before = $this->db->table('web_config')->where('config_name', $key)->get()->getRowArray();

        $result = $this->sendJson('PUT', self::URI . '/' . $key, ['config_value' => $value]);
        $result->assertStatus(422);
        $this->assertArrayHasKey('config_value', $this->json($result)['errors']);

        // Nilai lama tidak berubah.
        $this->seeInDatabase('web_config', ['config_name' => $key, 'config_value' => $before['config_value']]);
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: int|float|string|bool}>
     */
    public static function validValues(): iterable
    {
        yield 'integer' => ['tarif_uang_makan_pns', '45000', 45000];
        yield 'decimal' => ['potongan_tl1', '0.75', 0.75];
        yield 'time' => ['jam_masuk_normal', '08:00', '08:00'];
        yield 'time detik' => ['jam_masuk_normal', '08:00:00', '08:00:00'];
        yield 'boolean' => ['presensi_wajib_foto', '0', false];
        yield 'string' => ['header_pdf', 'KEMENTERIAN PARIWISATA RI', 'KEMENTERIAN PARIWISATA RI'];
    }

    /**
     * @dataProvider validValues
     */
    public function testValidValueIsSavedAndReturnedCasted(string $key, string $value, int|float|string|bool $expected): void
    {
        $result = $this->sendJson('PUT', self::URI . '/' . $key, ['config_value' => $value]);
        $result->assertStatus(200);
        $this->assertSame($expected, $this->json($result)['data']['value_casted']);
        $this->seeInDatabase('web_config', ['config_name' => $key, 'config_value' => $value]);
    }

    /**
     * Perubahan value langsung terpakai oleh konsumen (WebConfigService::value) — cache ter-invalidate.
     */
    public function testChangedValueIsImmediatelyVisibleToConsumers(): void
    {
        $this->assertSame(41000, service('webConfigService')->value('tarif_uang_makan_pns'));

        $this->sendJson('PUT', self::URI . '/tarif_uang_makan_pns', ['config_value' => '50000'])->assertStatus(200);
        $this->assertSame(50000, service('webConfigService')->value('tarif_uang_makan_pns'));

        $this->sendJson('POST', self::URI, [
            'config_name' => 'tarif_uang_makan_pppk', 'config_value' => '38000', 'tipe_data' => 'integer',
        ])->assertStatus(201);
        $this->assertSame(38000, service('webConfigService')->value('tarif_uang_makan_pppk'));

        $this->delete(self::URI . '/tarif_uang_makan_pppk')->assertStatus(200);
        $this->assertNull(service('webConfigService')->value('tarif_uang_makan_pppk'));
        $this->assertSame(12345, service('webConfigService')->value('tidak_ada', 12345));
    }

    public function testCreateValidatesKeyAndType(): void
    {
        $this->sendJson('POST', self::URI, ['config_name' => 'tarif_uang_makan_pns', 'config_value' => '1'])->assertStatus(422);
        $this->sendJson('POST', self::URI, ['config_name' => 'ada spasi', 'config_value' => '1'])->assertStatus(422);
        $this->sendJson('POST', self::URI, ['config_name' => 'key_baru', 'config_value' => 'abc', 'tipe_data' => 'integer'])->assertStatus(422);
        $this->sendJson('POST', self::URI, ['config_name' => 'key_baru', 'config_value' => '1', 'tipe_data' => 'angka'])->assertStatus(422);

        $created = $this->sendJson('POST', self::URI, [
            'config_name' => 'jam_pulang_puasa', 'config_value' => '15:00', 'tipe_data' => 'time', 'keterangan' => 'Jam pulang bulan puasa',
        ]);
        $created->assertStatus(201);
        $this->assertSame('15:00', $this->json($created)['data']['value_casted']);
    }

    public function testChangingTypeRevalidatesExistingValue(): void
    {
        // header_pdf berisi teks; diubah ke integer → ditolak karena nilai lama tidak valid.
        $this->sendJson('PUT', self::URI . '/header_pdf', ['tipe_data' => 'integer'])->assertStatus(422);
        $this->seeInDatabase('web_config', ['config_name' => 'header_pdf', 'tipe_data' => 'string']);

        // Diubah bersama nilai barunya → boleh.
        $this->sendJson('PUT', self::URI . '/header_pdf', ['tipe_data' => 'integer', 'config_value' => '2026'])->assertStatus(200);
        $this->assertSame(2026, service('webConfigService')->value('header_pdf'));
    }

    public function testKeyIsImmutableAndListFiltersWork(): void
    {
        $this->sendJson('PUT', self::URI . '/header_pdf', ['config_name' => 'header_baru', 'config_value' => 'X'])->assertStatus(200);
        $this->seeInDatabase('web_config', ['config_name' => 'header_pdf']);
        $this->dontSeeInDatabase('web_config', ['config_name' => 'header_baru']);

        $all = $this->json($this->get(self::URI))['data'];
        $this->assertCount(5, $all);

        $integers = $this->json($this->get(self::URI, ['tipe_data' => 'integer']))['data'];
        $this->assertSame(['tarif_uang_makan_pns'], array_column($integers, 'config_name'));

        $search = $this->json($this->get(self::URI, ['search' => 'uang makan']))['data'];
        $this->assertCount(1, $search);

        $this->get(self::URI . '/tidak_ada')->assertStatus(404);
    }

    public function testAuditIsRecordedAndEndpointsAreSuperAdminOnly(): void
    {
        $this->sendJson('POST', self::URI, ['config_name' => 'batas_upload_mb', 'config_value' => '5', 'tipe_data' => 'integer'])->assertStatus(201);
        $id = (string) $this->db->table('web_config')->where('config_name', 'batas_upload_mb')->get()->getRowArray()['id_web_config'];

        $this->sendJson('PUT', self::URI . '/batas_upload_mb', ['config_value' => '10'])->assertStatus(200);
        $this->delete(self::URI . '/batas_upload_mb')->assertStatus(200);

        foreach (['create', 'update', 'delete'] as $event) {
            $this->seeInDatabase('audit_logs', [
                'entity'    => 'web_config',
                'entity_id' => $id,
                'event'     => $event,
                'nip_actor' => '198501012010011001',
            ]);
        }

        foreach (array_diff(Role::all(), [Role::SUPER_ADMIN]) as $role) {
            $this->asRole($role);
            $this->get(self::URI)->assertStatus(403);
            $this->get(self::URI . '/header_pdf')->assertStatus(403);
            $this->sendJson('POST', self::URI, ['config_name' => 'x', 'config_value' => '1'])->assertStatus(403);
            $this->sendJson('PUT', self::URI . '/header_pdf', ['config_value' => 'X'])->assertStatus(403);
            $this->delete(self::URI . '/header_pdf')->assertStatus(403);
        }

        $this->withHeaders(['Authorization' => ''])->get(self::URI)->assertStatus(401);
    }
}
