<?php

declare(strict_types=1);

namespace Tests\MasterData;

use App\Constants\Role;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\AuthTestTrait;
use Tests\Support\Database\Seeds\MasterDataSeeder;
use Tests\Support\DatabaseTestCase;
use Tests\Support\MasterDataTestTrait;

/**
 * DBV-018/CR-032 — perilaku master G-02 sisa yang dikelola admin di legacy (rumpun & sub rumpun jabatan, jabatan
 * akademik, periode struktur jabatan) lewat engine master generik. G-TC umum (keunikan nama, soft delete, toggle status →
 * dropdown, urutan, audit, RBAC) dijalankan untuk keempat master oleh MasterGenericTcTest, RbacMasterEndpointsTest, dan
 * MasterConfigSchemaTest; di sini hanya aturan khusus (backend/docs/db-review/G-02b-jabatan-sisa-schema.md Bagian 2.9):
 *
 *  - meta keempat master; tabel DBV-018 lain (jenjang_jf, peta_jabatan, struktur_jabatan, jabatan_koordinasi) tidak
 *    terdaftar di engine;
 *  - sub rumpun: nama unik per rumpun, rumpun wajib aktif, dropdown ikut status rumpun (statusChain);
 *  - jabatan akademik: `is_atasan` wajib 1/2 (CHECK DB tidak pernah tercapai);
 *  - periode struktur: nama = tahun 4 digit 2000–3000 (PeriodeStrukturJabatanHooks);
 *  - kolom audit legacy: created_by diisi saat tambah, updated_by saat ubah (rumpun/sub rumpun/jabatan akademik);
 *  - FK jabatan → jenjang_jf: `jabatan.id_jenjang_jf` tetap tidak dikelola API, nilai yatim ditolak DB.
 *
 * @internal
 */
final class JabatanSisaTest extends DatabaseTestCase
{
    use FeatureTestTrait;
    use AuthTestTrait;
    use MasterDataTestTrait;

    protected $seed = MasterDataSeeder::class;

    private const ADMIN_NIP = '198501012010011001';

    private const G02B = ['rumpun-jabatan', 'subrumpun-jabatan', 'jabatan-akademik', 'periode-struktur-jabatan'];

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

    public function testMetaDescribesG02bMasters(): void
    {
        $meta = array_column($this->json($this->get('api/v1/master/meta'))['data'], null, 'key');

        $expected = [
            // key => [label, name_field, name_label, name_max_length, parent, has_order, status_chain, id_max_length]
            'rumpun-jabatan'           => ['Rumpun Jabatan', 'rumpun_jabatan', 'Rumpun Jabatan', 50, null, true, false, 3],
            'subrumpun-jabatan'        => ['Sub Rumpun Jabatan', 'subrumpun_jabatan', 'Sub Rumpun Jabatan', 255, ['field' => 'id_rumpun_jabatan', 'entity' => 'rumpun-jabatan'], true, true, 3],
            'jabatan-akademik'         => ['Jabatan Akademik', 'jabatan_akademik', 'Jabatan Akademik', 255, null, false, false, 11],
            'periode-struktur-jabatan' => ['Periode Struktur Jabatan', 'periode_struktur_jabatan', 'Periode Struktur', 45, null, false, false, 11],
        ];

        foreach ($expected as $key => $values) {
            $m = $meta[$key];
            $this->assertSame($values, [
                $m['label'], $m['name_field'], $m['name_label'], $m['name_max_length'], $m['parent'], $m['has_order'],
                $m['status_chain'], $m['id_max_length'],
            ], $key);
            $this->assertTrue($m['auto_increment'], $key);
            $this->assertTrue($m['has_status'], $key);
        }

        $this->assertSame([], $meta['rumpun-jabatan']['fields']);
        $isAtasan = array_column($meta['jabatan-akademik']['fields'], null, 'name')['is_atasan'];
        $this->assertSame(
            ['Jabatan Atasan', 'select', true, [['value' => '1', 'label' => 'Ya'], ['value' => '2', 'label' => 'Tidak']]],
            [$isAtasan['label'], $isAtasan['type'], $isAtasan['required'], $isAtasan['options']],
        );

        $registered = array_keys(service('masterRegistry')->all());

        foreach (['jenjang-jf', 'peta-jabatan', 'struktur-jabatan', 'jabatan-koordinasi'] as $notEngine) {
            $this->assertArrayNotHasKey($notEngine, $meta, "{$notEngine} bukan master engine generik (G-02b Bagian 2.9)");
            $this->assertNotContains($notEngine, $registered, $notEngine);
        }

        foreach (['jenjang_jf', 'peta_jabatan', 'struktur_jabatan', 'jabatan_koordinasi'] as $table) {
            $this->assertNotContains($table, array_map(static fn ($def): string => $def->table, service('masterRegistry')->all()), $table);
        }
    }

    /**
     * Dropdown keempat master = UL_ALL (dipakai riwayat jabatan B-07 dan struktur jabatan): setiap role login mendapat
     * options berisi entri; tanpa token 401.
     */
    public function testOptionsAreOpenToEveryRole(): void
    {
        foreach (Role::all() as $role) {
            $this->asRole($role);

            foreach (self::G02B as $key) {
                $this->assertNotSame([], $this->optionIds($key), "{$key} role {$role}");
            }

            $this->assertSame(['1', '2'], $this->optionIds('subrumpun-jabatan', '1'), "sub rumpun per rumpun role {$role}");
        }

        foreach (self::G02B as $key) {
            $this->withHeaders(['Authorization' => ''])->get("api/v1/master/{$key}/options")->assertStatus(401);
        }
    }

    /**
     * Sub rumpun: nama unik per rumpun (nama sama di rumpun lain boleh), rumpun wajib ada & aktif, dropdown hanya sub
     * rumpun aktif yang rumpunnya aktif.
     */
    public function testSubrumpunRules(): void
    {
        $this->assertSame(['3', '4'], $this->optionIds('subrumpun-jabatan', '2'));
        $this->sendJson('POST', 'api/v1/master/subrumpun-jabatan', ['id_rumpun_jabatan' => '2', 'subrumpun_jabatan' => 'Manajemen Keuangan'])->assertStatus(201);
        $result = $this->sendJson('POST', 'api/v1/master/subrumpun-jabatan', ['id_rumpun_jabatan' => '1', 'subrumpun_jabatan' => 'MANAJEMEN KEUANGAN']);
        $result->assertStatus(422);
        $this->assertArrayHasKey('subrumpun_jabatan', $this->json($result)['errors']);

        $this->sendJson('POST', 'api/v1/master/subrumpun-jabatan', ['subrumpun_jabatan' => 'Tanpa Rumpun'])->assertStatus(422);
        $this->sendJson('POST', 'api/v1/master/subrumpun-jabatan', ['id_rumpun_jabatan' => '99', 'subrumpun_jabatan' => 'Rumpun Yatim'])->assertStatus(422);

        $this->assertSame(['3', '4', '5'], $this->optionIds('subrumpun-jabatan', '2'));
        $this->sendJson('PATCH', 'api/v1/master/rumpun-jabatan/2/status', ['status' => '2'])->assertStatus(200);
        $this->assertSame([], $this->optionIds('subrumpun-jabatan', '2'));
        $this->seeInDatabase('subrumpun_jabatan', ['id_subrumpun_jabatan' => 3, 'status' => 1]);

        $result = $this->sendJson('POST', 'api/v1/master/subrumpun-jabatan', ['id_rumpun_jabatan' => '2', 'subrumpun_jabatan' => 'Baru']);
        $result->assertStatus(422);
        $this->assertArrayHasKey('id_rumpun_jabatan', $this->json($result)['errors']);
    }

    /**
     * Jabatan akademik: `is_atasan` wajib 1 (Ya) / 2 (Tidak); nilai lain → 422 pada field-nya (CHECK DB
     * chk_jabatan_akademik_is_atasan tidak pernah tercapai). Tanpa urutan.
     */
    public function testJabatanAkademikIsAtasanIsRequired(): void
    {
        foreach (['0', '3', 'ya', '', null] as $value) {
            $result = $this->sendJson('POST', 'api/v1/master/jabatan-akademik', ['jabatan_akademik' => 'Lektor Kepala', 'is_atasan' => $value]);
            $result->assertStatus(422);
            $this->assertArrayHasKey('is_atasan', $this->json($result)['errors'], var_export($value, true));
        }

        $this->sendJson('POST', 'api/v1/master/jabatan-akademik', ['jabatan_akademik' => 'Lektor Kepala'])->assertStatus(422);

        $created = $this->json($this->sendJson('POST', 'api/v1/master/jabatan-akademik', ['jabatan_akademik' => 'Lektor Kepala', 'is_atasan' => '1']))['data'];
        $this->assertSame('1', (string) $created['is_atasan']);
        $this->sendJson('PUT', "api/v1/master/jabatan-akademik/{$created['id_jabatan_akademik']}", ['is_atasan' => '2'])->assertStatus(200);
        $this->seeInDatabase('jabatan_akademik', ['id_jabatan_akademik' => $created['id_jabatan_akademik'], 'is_atasan' => 2]);
        $this->sendJson('PATCH', 'api/v1/master/jabatan-akademik/1/order', ['order' => 1])->assertStatus(422);
    }

    /**
     * Periode struktur: nama = tahun 4 digit 2000–3000 (mask form legacy), unik. Hanya diperiksa saat nama ditulis, jadi
     * periode impor lama berbentuk lain tetap bisa dinonaktifkan.
     */
    public function testPeriodeStrukturMustBeAYear(): void
    {
        foreach (['24', '1999', '3001', '20a4', 'Periode 2025', '02024'] as $value) {
            $result = $this->sendJson('POST', 'api/v1/master/periode-struktur-jabatan', ['periode_struktur_jabatan' => $value]);
            $this->assertSame(422, $result->response()->getStatusCode(), var_export($value, true));
            $this->assertArrayHasKey('periode_struktur_jabatan', $this->json($result)['errors'], var_export($value, true));
        }

        $this->assertSame(
            ['Periode Struktur harus tahun 4 digit antara 2000 dan 3000.'],
            $this->json($this->sendJson('POST', 'api/v1/master/periode-struktur-jabatan', ['periode_struktur_jabatan' => '1999']))['errors']['periode_struktur_jabatan'],
        );

        $this->sendJson('POST', 'api/v1/master/periode-struktur-jabatan', ['periode_struktur_jabatan' => '2027'])->assertStatus(201);
        $this->sendJson('POST', 'api/v1/master/periode-struktur-jabatan', ['periode_struktur_jabatan' => '2021'])->assertStatus(422);
        $this->sendJson('PUT', 'api/v1/master/periode-struktur-jabatan/1', ['periode_struktur_jabatan' => '21'])->assertStatus(422);
        $this->seeInDatabase('periode_struktur_jabatan', ['id_periode_struktur_jabatan' => 1, 'periode_struktur_jabatan' => '2021']);

        $this->db->table('periode_struktur_jabatan')->insert(['id_periode_struktur_jabatan' => 9, 'periode_struktur_jabatan' => 'Periode Lama']);
        $this->sendJson('PATCH', 'api/v1/master/periode-struktur-jabatan/9/status', ['status' => '2'])->assertStatus(200);
        $this->seeInDatabase('periode_struktur_jabatan', ['id_periode_struktur_jabatan' => 9, 'status' => 2]);
    }

    /**
     * Kolom audit legacy (Lm_jabatan.php sp_rumpun/sp_subrumpun/sp_jabaka): tambah mengisi created_by dan membiarkan
     * updated_by NULL, ubah mengisi updated_by. Periode (tanpa created_by) mengisi updated_by di keduanya. Semua tercatat
     * di audit_logs dengan aktor.
     */
    public function testAuditColumnsFollowLegacy(): void
    {
        $adminId = (int) $this->db->table('pengguna')->select('id_pengguna')->where('nip', self::ADMIN_NIP)->get()->getRowArray()['id_pengguna'];

        $id = (string) $this->json($this->sendJson('POST', 'api/v1/master/rumpun-jabatan', ['rumpun_jabatan' => 'Kesehatan']))['data']['id_rumpun_jabatan'];
        $this->seeInDatabase('rumpun_jabatan', ['id_rumpun_jabatan' => $id, 'created_by' => $adminId, 'updated_by' => null]);
        $this->sendJson('PUT', "api/v1/master/rumpun-jabatan/{$id}", ['rumpun_jabatan' => 'Kesehatan Masyarakat'])->assertStatus(200);
        $this->seeInDatabase('rumpun_jabatan', ['id_rumpun_jabatan' => $id, 'created_by' => $adminId, 'updated_by' => $adminId]);

        $periode = (string) $this->json($this->sendJson('POST', 'api/v1/master/periode-struktur-jabatan', ['periode_struktur_jabatan' => '2028']))['data']['id_periode_struktur_jabatan'];
        $this->seeInDatabase('periode_struktur_jabatan', ['id_periode_struktur_jabatan' => $periode, 'updated_by' => $adminId]);

        foreach (['create', 'update'] as $event) {
            $this->seeInDatabase('audit_logs', ['entity' => 'rumpun_jabatan', 'entity_id' => $id, 'event' => $event, 'nip_actor' => self::ADMIN_NIP]);
        }
    }

    /**
     * FK jabatan → jenjang_jf (DBV-018): kolom tetap tidak dikelola API (hiddenColumns), nilai yang ada di jenjang_jf
     * diterima DB, nilai yatim ditolak DB (RESTRICT), dan jenjang yang dirujuk tidak bisa dihapus.
     */
    public function testJenjangJfForeignKeyGuardsJabatan(): void
    {
        $this->db->table('jabatan')->where('id_jabatan', 3)->update(['id_jenjang_jf' => 1]);
        $this->seeInDatabase('jabatan', ['id_jabatan' => 3, 'id_jenjang_jf' => 1]);

        $failed = false;

        try {
            $failed = $this->db->table('jabatan')->where('id_jabatan', 3)->update(['id_jenjang_jf' => 99]) === false;
        } catch (\Throwable) {
            $failed = true;
        }

        $this->assertTrue($failed, 'id_jenjang_jf yatim harus ditolak FK');

        $failed = false;

        try {
            $failed = $this->db->table('jenjang_jf')->where('id_jenjang_jf', 1)->delete() === false;
        } catch (\Throwable) {
            $failed = true;
        }

        $this->assertTrue($failed, 'jenjang_jf yang dirujuk jabatan tidak bisa dihapus');
        $this->assertStringNotContainsString('id_jenjang_jf', (string) $this->get('api/v1/master/jabatan/3')->getJSON());
    }
}
