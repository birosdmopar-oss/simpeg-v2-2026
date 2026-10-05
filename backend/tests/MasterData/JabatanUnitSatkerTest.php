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
 * DBV-008/CR-026 — perilaku master G-02 (unit, satker, group & sub group jabatan, kelas jabatan, jabatan) lewat engine
 * master generik. G-TC umum (keunikan nama, soft delete, toggle status → dropdown, urutan, audit, RBAC) sudah dijalankan
 * untuk keenam master oleh MasterGenericTcTest, RbacMasterEndpointsTest, dan MasterConfigSchemaTest; di sini hanya aturan
 * khusus G-02 (backend/docs/db-review/G-02-jabatan-unit-satker-schema.md Bagian 2.9):
 *
 *  - satker: nama unik per unit, induk unit wajib aktif, `zonasi` wajib 0–120 menit, dropdown ikut status unit
 *    (statusChain), `logo_uns` tidak pernah dikirim/ditulis;
 *  - sub group: nama unik per group, `need_satker` wajib 1/2, pindah group ditolak bila sudah dirujuk jabatan (hook);
 *  - kelas jabatan: kode = nama (codeAsName CR-026), nomor 1–20 tanpa nol di depan, nomor tidak bisa diubah;
 *  - jabatan: group → sub group berjenjang, rujukan wajib aktif, nama unik per (sub group, satker) termasuk satker kosong,
 *    `umur_pensiun` 50–80, filter dropdown, `id_jenjang_jf` tidak dikelola;
 *  - soft delete satker yang masih dirujuk jabatan (pengganti "satker yang masih punya pegawai" sampai B-01).
 *
 * Setiap nilai isian yang ditolak NOT NULL/CHECK di DB harus sudah ditolak validasi (422 pada field-nya): CR-007 tidak
 * menerjemahkan 1048 dan pelanggaran CHECK (3819/4025), jadi nilai itu akan menjadi 500 bila lolos ke DB.
 *
 * @internal
 */
final class JabatanUnitSatkerTest extends CIUnitTestCase
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

    private const G02 = ['unit', 'satker', 'group-jabatan', 'sub-group-jabatan', 'kelas-jabatan', 'jabatan'];

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
     * Metadata form (GET master/meta) keenam master: label legacy, induk & statusChain, urutan, kode = nama kelas
     * jabatan, field tambahan. `logo_uns` dan `id_jenjang_jf` tidak muncul di mana pun.
     */
    public function testMetaDescribesG02Masters(): void
    {
        $meta = array_column($this->json($this->get('api/v1/master/meta'))['data'], null, 'key');

        $expected = [
            // key => [label, auto_increment, name_field, name_label, name_max_length, parent, has_order, status_chain, code_as_name, id_range, id_max_length, filters]
            'unit'              => ['Unit Kerja', true, 'unit', 'Unit Kerja', 150, null, true, false, false, null, 11, []],
            'satker'            => ['Satuan Kerja', true, 'satker', 'Satuan Kerja', 150, ['field' => 'id_unit', 'entity' => 'unit'], true, true, false, null, 11, []],
            'group-jabatan'     => ['Group Jabatan', true, 'group_jabatan', 'Group Jabatan', 45, null, true, false, false, null, 11, []],
            'sub-group-jabatan' => ['Sub Group Jabatan', true, 'sub_group_jabatan', 'Sub Group Jabatan', 100, ['field' => 'id_group_jabatan', 'entity' => 'group-jabatan'], true, true, false, null, 11, []],
            'kelas-jabatan'     => ['Kelas Jabatan', false, 'kelas_jabatan', 'Kelas Jabatan', 2, null, false, false, true, [1, 20], 2, []],
            'jabatan'           => ['Jabatan', true, 'jabatan', 'Jabatan', 250, null, false, false, false, null, 11, ['id_group_jabatan', 'id_sub_group_jabatan', 'id_satker', 'kelas_jabatan']],
        ];

        foreach ($expected as $key => $values) {
            $m = $meta[$key];
            $this->assertSame($values, [
                $m['label'], $m['auto_increment'], $m['name_field'], $m['name_label'], $m['name_max_length'], $m['parent'],
                $m['has_order'], $m['status_chain'], $m['code_as_name'], $m['id_range'], $m['id_max_length'], $m['filters'],
            ], $key);
            $this->assertTrue($m['has_status'], $key);
            $this->assertSame('shift', $m['order_mode'], $key);
        }

        $this->assertSame('kelas_jabatan', $meta['kelas-jabatan']['primary_key']);

        $fields = static fn (string $key): array => array_column($meta[$key]['fields'], null, 'name');

        $this->assertSame(['is_upt', 'alamat_pdf_header', 'tembusan_kppn', 'lokasi_kppn'], array_keys($fields('unit')));
        $this->assertSame(255, $fields('unit')['alamat_pdf_header']['max_bytes']);
        $this->assertSame('boolean', $fields('unit')['is_upt']['type']);

        $satker = $fields('satker');
        $this->assertSame(['zonasi', 'is_upt', 'alamat_pdf_header', 'tembusan_kppn', 'lokasi_kppn'], array_keys($satker));
        $this->assertSame(
            ['Zonasi Presensi (menit)', 'int', true, 0, 120, 'Selisih jam presensi dari WIB dalam menit: 0 = WIB, 60 = WITA, 120 = WIT.'],
            [$satker['zonasi']['label'], $satker['zonasi']['type'], $satker['zonasi']['required'], $satker['zonasi']['min'], $satker['zonasi']['max'], $satker['zonasi']['hint']],
        );
        $this->assertSame(65535, $satker['alamat_pdf_header']['max_bytes']);

        $needSatker = $fields('sub-group-jabatan')['need_satker'];
        $this->assertSame(['select', true, [['value' => '1', 'label' => 'Ya'], ['value' => '2', 'label' => 'Tidak']]], [$needSatker['type'], $needSatker['required'], $needSatker['options']]);

        $tukin = $fields('kelas-jabatan')['tukin'];
        $this->assertSame(['int', true, 0, 2147483647], [$tukin['type'], $tukin['required'], $tukin['min'], $tukin['max']]);

        $jabatan = $fields('jabatan');
        $this->assertSame(['id_group_jabatan', 'id_sub_group_jabatan', 'id_satker', 'kelas_jabatan', 'umur_pensiun'], array_keys($jabatan));
        $this->assertSame(
            [
                ['ref', true, 'group-jabatan', null],
                ['ref', true, 'sub-group-jabatan', 'id_group_jabatan'],
                ['ref', false, 'satker', null],
                ['ref', false, 'kelas-jabatan', null],
            ],
            array_map(static fn (string $name): array => [$jabatan[$name]['type'], $jabatan[$name]['required'], $jabatan[$name]['entity'], $jabatan[$name]['depends_on']], ['id_group_jabatan', 'id_sub_group_jabatan', 'id_satker', 'kelas_jabatan']),
        );
        $this->assertSame([false, 50, 80], [$jabatan['umur_pensiun']['required'], $jabatan['umur_pensiun']['min'], $jabatan['umur_pensiun']['max']]);

        $json = (string) json_encode(array_intersect_key($meta, array_flip(self::G02)));
        $this->assertStringNotContainsString('logo_uns', $json);
        $this->assertStringNotContainsString('id_jenjang_jf', $json);
    }

    /**
     * Dropdown keenam master = UL_ALL (ISSUE-012; dipakai riwayat jabatan B-07, akun, presensi): setiap role login mendapat
     * options berisi entri. Dikunci eksplisit karena RbacMasterEndpointsTest membaca daftar master admin-only dari config.
     */
    public function testOptionsAreOpenToEveryRole(): void
    {
        foreach (self::G02 as $key) {
            $this->assertTrue(service('masterRegistry')->get($key)->publicOptions, "{$key}: publicOptions");
        }

        foreach (Role::all() as $role) {
            $this->asRole($role);

            foreach (self::G02 as $key) {
                $this->assertNotSame([], $this->optionIds($key), "{$key} role {$role}");
            }

            $this->assertSame(['1', '2'], $this->optionIds('satker', '1'), "satker per unit role {$role}");
            $this->assertSame(['1', '2'], $this->optionIds('sub-group-jabatan', '1'), "sub group per group role {$role}");
        }

        foreach (self::G02 as $key) {
            $this->withHeaders(['Authorization' => ''])->get("api/v1/master/{$key}/options")->assertStatus(401);
        }
    }

    /**
     * Satker: nama unik per unit (nama sama di unit lain boleh), unit wajib aktif, urutan per unit.
     */
    public function testSatkerNameIsUniquePerUnitAndUnitMustBeActive(): void
    {
        $created = $this->sendJson('POST', 'api/v1/master/satker', ['id_unit' => '2', 'satker' => 'Biro Umum', 'zonasi' => '0']);
        $created->assertStatus(201);
        $this->assertSame(['2', '2'], [(string) $this->json($created)['data']['id_unit'], (string) $this->json($created)['data']['order']]);

        $result = $this->sendJson('POST', 'api/v1/master/satker', ['id_unit' => '1', 'satker' => 'biro umum', 'zonasi' => '0']);
        $result->assertStatus(422);
        $this->assertStringContainsString('sudah ada dengan kode 2', $this->json($result)['errors']['satker'][0]);

        // Unit tidak aktif / tidak ada → 422 pada induk.
        $this->sendJson('PATCH', 'api/v1/master/unit/2/status', ['status' => '2'])->assertStatus(200);
        $result = $this->sendJson('POST', 'api/v1/master/satker', ['id_unit' => '2', 'satker' => 'Bagian Baru', 'zonasi' => '0']);
        $result->assertStatus(422);
        $this->assertSame(['Unit Kerja Deputi Bidang Sumber Daya dan Kelembagaan sedang non-aktif.'], $this->json($result)['errors']['id_unit']);
        $this->sendJson('POST', 'api/v1/master/satker', ['id_unit' => '99', 'satker' => 'Bagian Baru', 'zonasi' => '0'])->assertStatus(422);
        $this->sendJson('POST', 'api/v1/master/satker', ['satker' => 'Tanpa Unit', 'zonasi' => '0'])->assertStatus(422);
    }

    /**
     * DoD G-02: `zonasi` = offset jam presensi dari WIB dalam menit, wajib, 0–120 (form legacy "WIB + N menit"). Nilai di
     * luar rentang, bukan bilangan bulat, atau kosong → 422 `zonasi` (CHECK chk_satker_zonasi tidak pernah tercapai).
     */
    public function testZonasiIsRequiredAndBounded(): void
    {
        $base = ['id_unit' => '1', 'satker' => 'Biro Zona'];

        foreach (['-1', '121', 'abc', '1.5', '', ' ', null] as $value) {
            $result = $this->sendJson('POST', 'api/v1/master/satker', $base + ['zonasi' => $value]);
            $result->assertStatus(422);
            $this->assertArrayHasKey('zonasi', $this->json($result)['errors'], var_export($value, true));
        }

        $result = $this->sendJson('POST', 'api/v1/master/satker', $base);
        $result->assertStatus(422);
        $this->assertSame(['Zonasi Presensi (menit) wajib diisi.'], $this->json($result)['errors']['zonasi']);

        $created = $this->json($this->sendJson('POST', 'api/v1/master/satker', $base + ['zonasi' => '60']))['data'];
        $this->assertSame('60', (string) $created['zonasi']);
        $id = (string) $created['id_satker'];

        $this->sendJson('PUT', "api/v1/master/satker/{$id}", ['zonasi' => '121'])->assertStatus(422);
        $this->sendJson('PUT', "api/v1/master/satker/{$id}", ['zonasi' => ''])->assertStatus(422);
        $this->sendJson('PUT', "api/v1/master/satker/{$id}", ['zonasi' => '120'])->assertStatus(200);
        $this->seeInDatabase('satker', ['id_satker' => $id, 'zonasi' => 120]);

        // Seed: satker 3 (UPT, WITA) tersimpan 60; is_upt boolean 1/0.
        $this->assertSame(['60', '1'], [(string) $this->json($this->get('api/v1/master/satker/3'))['data']['zonasi'], (string) $this->json($this->get('api/v1/master/satker/3'))['data']['is_upt']]);
        $this->sendJson('PUT', 'api/v1/master/satker/3', ['is_upt' => '2'])->assertStatus(422);
        $this->sendJson('PUT', 'api/v1/master/satker/3', ['is_upt' => false])->assertStatus(200);
        $this->seeInDatabase('satker', ['id_satker' => 3, 'is_upt' => 0]);
    }

    /**
     * Dropdown satker per unit hanya memuat satker aktif yang unitnya aktif (statusChain); `logo_uns` disimpan tetapi
     * tidak pernah dikirim maupun ditulis lewat API (hiddenColumns, belum ada fitur unggah).
     */
    public function testSatkerOptionsFollowUnitStatusAndLogoIsHidden(): void
    {
        // Tanpa induk: urut `order` (per unit) lalu nama — satker urutan 1 kedua unit lebih dulu.
        $this->assertSame(['3'], $this->optionIds('satker', '2'));
        $this->assertSame(['1', '3', '2'], $this->optionIds('satker'));

        $this->sendJson('PATCH', 'api/v1/master/unit/2/status', ['status' => '2'])->assertStatus(200);
        $this->assertSame([], $this->optionIds('satker', '2'));
        $this->assertSame(['1', '2'], $this->optionIds('satker'));
        $this->seeInDatabase('satker', ['id_satker' => 3, 'status' => 1]);

        $this->sendJson('PATCH', 'api/v1/master/unit/2/status', ['status' => '1'])->assertStatus(200);
        $this->assertSame(['3'], $this->optionIds('satker', '2'));

        $this->db->table('satker')->where('id_satker', 1)->update(['logo_uns' => 'logo-biro-sdm.jpg']);

        foreach ([$this->get('api/v1/master/satker'), $this->get('api/v1/master/satker/1')] as $response) {
            $response->assertStatus(200);
            $this->assertStringNotContainsString('logo_uns', (string) $response->getJSON());
        }

        $updated = $this->sendJson('PUT', 'api/v1/master/satker/1', ['logo_uns' => 'lain.jpg', 'satker' => 'Biro SDM']);
        $updated->assertStatus(200);
        $this->assertArrayNotHasKey('logo_uns', $this->json($updated)['data']);
        $this->seeInDatabase('satker', ['id_satker' => 1, 'satker' => 'Biro SDM', 'logo_uns' => 'logo-biro-sdm.jpg']);
    }

    /**
     * Sub group: nama unik per group, `need_satker` wajib 1/2, dropdown ikut status group, dan pindah group ditolak bila
     * sub group sudah dirujuk jabatan (SubGroupJabatanHooks) — tanpa rujukan boleh.
     */
    public function testSubGroupRules(): void
    {
        $this->sendJson('POST', 'api/v1/master/sub-group-jabatan', ['id_group_jabatan' => '2', 'sub_group_jabatan' => 'Administrator', 'need_satker' => '2'])->assertStatus(201);
        $this->sendJson('POST', 'api/v1/master/sub-group-jabatan', ['id_group_jabatan' => '1', 'sub_group_jabatan' => 'administrator', 'need_satker' => '1'])->assertStatus(422);

        foreach (['0', '3', 'ya', '', null] as $value) {
            $result = $this->sendJson('POST', 'api/v1/master/sub-group-jabatan', ['id_group_jabatan' => '1', 'sub_group_jabatan' => 'Pengawas', 'need_satker' => $value]);
            $result->assertStatus(422);
            $this->assertArrayHasKey('need_satker', $this->json($result)['errors'], var_export($value, true));
        }

        $this->sendJson('POST', 'api/v1/master/sub-group-jabatan', ['id_group_jabatan' => '1', 'sub_group_jabatan' => 'Pengawas'])->assertStatus(422);

        // Pindah group: sub group 2 (dirujuk jabatan 2) ditolak tanpa tulis; sub group 5 (tanpa jabatan) boleh.
        $result = $this->sendJson('PUT', 'api/v1/master/sub-group-jabatan/2', ['id_group_jabatan' => '3']);
        $result->assertStatus(422);
        $this->assertSame(
            ['Sub group jabatan ini sudah dipakai 1 jabatan; pindah group tidak diizinkan. Buat sub group baru di group tujuan.'],
            $this->json($result)['errors']['id_group_jabatan'],
        );
        $this->seeInDatabase('sub_group_jabatan', ['id_sub_group_jabatan' => 2, 'id_group_jabatan' => 1, 'order' => 2]);
        $this->dontSeeInDatabase('audit_logs', ['entity' => 'sub_group_jabatan', 'entity_id' => '2']);

        // Ubah selain group tetap boleh walaupun dirujuk jabatan.
        $this->sendJson('PUT', 'api/v1/master/sub-group-jabatan/2', ['sub_group_jabatan' => 'Administrator Utama', 'need_satker' => '2'])->assertStatus(200);

        $this->sendJson('PUT', 'api/v1/master/sub-group-jabatan/5', ['id_group_jabatan' => '3'])->assertStatus(200);
        $this->seeInDatabase('sub_group_jabatan', ['id_sub_group_jabatan' => 5, 'id_group_jabatan' => 3, 'order' => 2]);

        // Jabatan Tidak Aktif/Dihapus tetap dihitung (barisnya tetap dirujuk riwayat).
        $this->delete('api/v1/master/jabatan/4')->assertStatus(200);
        $this->sendJson('PUT', 'api/v1/master/sub-group-jabatan/4', ['id_group_jabatan' => '2'])->assertStatus(422);

        // Dropdown sub group ikut status group (statusChain).
        $this->sendJson('PATCH', 'api/v1/master/group-jabatan/1/status', ['status' => '2'])->assertStatus(200);
        $this->assertSame([], $this->optionIds('sub-group-jabatan', '1'));
        $this->sendJson('PATCH', 'api/v1/master/group-jabatan/1/status', ['status' => '1'])->assertStatus(200);
        $this->assertSame(['1', '2'], $this->optionIds('sub-group-jabatan', '1'));
    }

    /**
     * Kelas jabatan (codeAsName, CR-026): nomor kelas = kode = nama, 1–20 tanpa nol di depan, unik, tidak bisa diubah;
     * yang bisa diubah hanya `tukin` dan status. Tanpa urutan tampil (urutan alami = nomor kelas).
     */
    public function testKelasJabatanCodeIsNameAndImmutable(): void
    {
        foreach (['0', '21', '7a', '07', '-1', '1.5', '', null] as $value) {
            $result = $this->sendJson('POST', 'api/v1/master/kelas-jabatan', ['kelas_jabatan' => $value, 'tukin' => '1000']);
            $result->assertStatus(422);
            $this->assertArrayHasKey('kelas_jabatan', $this->json($result)['errors'], var_export($value, true));
        }

        $this->assertSame(
            ['Kelas Jabatan harus bilangan bulat 1 sampai 20 (tanpa nol di depan).'],
            $this->json($this->sendJson('POST', 'api/v1/master/kelas-jabatan', ['kelas_jabatan' => '07', 'tukin' => '1000']))['errors']['kelas_jabatan'],
        );
        $this->assertSame(
            ['Kelas Jabatan maksimal 20.'],
            $this->json($this->sendJson('POST', 'api/v1/master/kelas-jabatan', ['kelas_jabatan' => '21', 'tukin' => '1000']))['errors']['kelas_jabatan'],
        );

        foreach (['', '-1', 'abc', '2147483648'] as $value) {
            $this->sendJson('POST', 'api/v1/master/kelas-jabatan', ['kelas_jabatan' => '15', 'tukin' => $value])->assertStatus(422);
        }

        $result = $this->sendJson('POST', 'api/v1/master/kelas-jabatan', ['kelas_jabatan' => '7', 'tukin' => '1000']);
        $result->assertStatus(422);
        $this->assertSame(['Kode 7 sudah dipakai.'], $this->json($result)['errors']['kelas_jabatan']);
        $this->assertSame(4, $this->db->table('kelas_jabatan')->countAllResults());

        $created = $this->sendJson('POST', 'api/v1/master/kelas-jabatan', ['kelas_jabatan' => '15', 'tukin' => '17064000']);
        $created->assertStatus(201);
        $this->assertSame(['15', '17064000', '1'], [(string) $this->json($created)['data']['kelas_jabatan'], (string) $this->json($created)['data']['tukin'], (string) $this->json($created)['data']['status']]);

        $this->get('api/v1/master/kelas-jabatan/15')->assertStatus(200);
        $this->get('api/v1/master/kelas-jabatan/015')->assertStatus(404);

        // Hanya tukin yang berubah; nomor yang sama boleh ikut terkirim (form edit), nomor lain 422 tanpa tulis.
        $this->sendJson('PUT', 'api/v1/master/kelas-jabatan/15', ['tukin' => '17100000'])->assertStatus(200);
        $this->sendJson('PUT', 'api/v1/master/kelas-jabatan/15', ['kelas_jabatan' => '15', 'tukin' => '17200000'])->assertStatus(200);
        $result = $this->sendJson('PUT', 'api/v1/master/kelas-jabatan/15', ['kelas_jabatan' => '16', 'tukin' => '1']);
        $result->assertStatus(422);
        $this->assertSame(['Kelas Jabatan adalah kode entri dan tidak dapat diubah.'], $this->json($result)['errors']['kelas_jabatan']);
        $this->sendJson('PUT', 'api/v1/master/kelas-jabatan/15', ['kelas_jabatan' => ''])->assertStatus(422);
        $this->seeInDatabase('kelas_jabatan', ['kelas_jabatan' => 15, 'tukin' => 17200000]);
        $this->dontSeeInDatabase('kelas_jabatan', ['kelas_jabatan' => 16]);

        // Dropdown: id = nama = nomor kelas, urut numerik.
        $options = $this->json($this->get('api/v1/master/kelas-jabatan/options'))['data'];
        $this->assertSame(['7', '9', '11', '13', '15'], array_column($options, 'id'));
        $this->assertSame(['id' => '7', 'nama' => '7', 'parent' => null], $options[0]);

        $this->sendJson('PATCH', 'api/v1/master/kelas-jabatan/7/order', ['order' => 1])->assertStatus(422);
    }

    /**
     * Jabatan: group wajib, sub group wajib dan harus di bawah group yang dipilih; group/sub group/satker/kelas yang
     * dirujuk wajib ada dan aktif (hanya bila nilainya berubah); `umur_pensiun` opsional 50–80.
     */
    public function testJabatanReferencesMustBeConsistentAndActive(): void
    {
        $base = ['id_group_jabatan' => '1', 'id_sub_group_jabatan' => '1', 'id_satker' => '1', 'kelas_jabatan' => '13', 'jabatan' => 'Kepala Biro Uji'];

        $cases = [
            'id_group_jabatan'     => [['id_group_jabatan' => ''], ['id_group_jabatan' => '99'], ['id_group_jabatan' => '01']],
            'id_sub_group_jabatan' => [['id_sub_group_jabatan' => ''], ['id_sub_group_jabatan' => '99'], ['id_group_jabatan' => '2']],
            'id_satker'            => [['id_satker' => '99'], ['id_satker' => '1abc']],
            'kelas_jabatan'        => [['kelas_jabatan' => '8'], ['kelas_jabatan' => '07'], ['kelas_jabatan' => '123']],
            'umur_pensiun'         => [['umur_pensiun' => '49'], ['umur_pensiun' => '81'], ['umur_pensiun' => 'abc']],
            'jabatan'              => [['jabatan' => ''], ['jabatan' => str_repeat('a', 251)]],
        ];

        foreach ($cases as $field => $overrides) {
            foreach ($overrides as $override) {
                $result = $this->sendJson('POST', 'api/v1/master/jabatan', $override + $base);
                $result->assertStatus(422);
                $this->assertArrayHasKey($field, $this->json($result)['errors'], $field . ' ' . json_encode($override));
            }
        }

        $this->assertSame(
            ['Sub Group Jabatan Pimpinan Tinggi Pratama tidak berada di bawah Group Jabatan yang dipilih.'],
            $this->json($this->sendJson('POST', 'api/v1/master/jabatan', ['id_group_jabatan' => '2'] + $base))['errors']['id_sub_group_jabatan'],
        );

        // Rujukan non-aktif ditolak saat dipilih.
        $this->sendJson('PATCH', 'api/v1/master/satker/2/status', ['status' => '2'])->assertStatus(200);
        $this->sendJson('PATCH', 'api/v1/master/kelas-jabatan/11/status', ['status' => '2'])->assertStatus(200);
        $this->sendJson('PATCH', 'api/v1/master/sub-group-jabatan/3/status', ['status' => '2'])->assertStatus(200);
        $this->assertSame(['Satuan Kerja Biro Umum sedang non-aktif.'], $this->json($this->sendJson('POST', 'api/v1/master/jabatan', ['id_satker' => '2'] + $base))['errors']['id_satker']);
        $this->assertSame(['Kelas Jabatan 11 sedang non-aktif.'], $this->json($this->sendJson('POST', 'api/v1/master/jabatan', ['kelas_jabatan' => '11'] + $base))['errors']['kelas_jabatan']);
        $this->sendJson('POST', 'api/v1/master/jabatan', ['id_group_jabatan' => '2', 'id_sub_group_jabatan' => '3'] + $base)->assertStatus(422);

        // Jabatan lama yang merujuk kelas/satker non-aktif tetap bisa diubah kolom lainnya (E6).
        $this->sendJson('PUT', 'api/v1/master/jabatan/2', ['jabatan' => 'Kepala Bagian Mutasi dan Promosi'])->assertStatus(200);
        // Ganti group saja → sub group lama tidak lagi di bawahnya → 422 pada sub group.
        $this->sendJson('PUT', 'api/v1/master/jabatan/1', ['id_group_jabatan' => '2'])->assertStatus(422);
        $this->seeInDatabase('jabatan', ['id_jabatan' => 1, 'id_group_jabatan' => 1]);

        // Satker & kelas opsional (kosong = NULL); umur pensiun 50–80 atau kosong.
        $created = $this->json($this->sendJson('POST', 'api/v1/master/jabatan', ['id_satker' => '', 'kelas_jabatan' => '', 'umur_pensiun' => '58'] + $base))['data'];
        $this->seeInDatabase('jabatan', ['id_jabatan' => $created['id_jabatan'], 'id_satker' => null, 'kelas_jabatan' => null, 'umur_pensiun' => 58, 'id_group_jabatan' => 1]);
        $this->sendJson('PUT', "api/v1/master/jabatan/{$created['id_jabatan']}", ['umur_pensiun' => ''])->assertStatus(200);
        $this->seeInDatabase('jabatan', ['id_jabatan' => $created['id_jabatan'], 'umur_pensiun' => null]);
    }

    /**
     * Nama jabatan unik per (sub group, satker), case-insensitive — termasuk jabatan tanpa satker, yang tidak ditegakkan
     * UNIQUE DB (NULL) sehingga hanya dijaga aplikasi. Dropdown bisa disaring per sub group, satker, group, dan kelas.
     */
    public function testJabatanNameIsUniquePerSubGroupAndSatkerAndOptionsCanBeFiltered(): void
    {
        $this->assertSame(['1'], $this->optionIds('jabatan', null, ['id_sub_group_jabatan' => '1', 'id_satker' => '1']));
        $this->assertSame(['3'], $this->optionIds('jabatan', null, ['id_sub_group_jabatan' => '3']));
        $this->assertSame(['2', '1'], $this->optionIds('jabatan', null, ['id_group_jabatan' => '1']), 'tanpa order: urut nama');
        $this->assertSame(['4'], $this->optionIds('jabatan', null, ['kelas_jabatan' => '7']));
        $this->get('api/v1/master/jabatan/options', ['id_satker' => 'x y'])->assertStatus(422);
        $this->get('api/v1/master/jabatan/options', ['id_satker' => ['1']])->assertStatus(422);

        $struktural = ['id_group_jabatan' => '1', 'id_sub_group_jabatan' => '1'];
        $result     = $this->sendJson('POST', 'api/v1/master/jabatan', $struktural + ['id_satker' => '1', 'jabatan' => 'kepala biro sumber daya manusia']);
        $result->assertStatus(422);
        $this->assertStringContainsString('sudah ada dengan kode 1', $this->json($result)['errors']['jabatan'][0]);

        // Satker lain → boleh.
        $this->sendJson('POST', 'api/v1/master/jabatan', $struktural + ['id_satker' => '2', 'jabatan' => 'Kepala Biro Sumber Daya Manusia'])->assertStatus(201);

        // Tanpa satker (NULL): ditegakkan aplikasi.
        $fungsional = ['id_group_jabatan' => '2', 'id_sub_group_jabatan' => '3'];
        $this->sendJson('POST', 'api/v1/master/jabatan', $fungsional + ['jabatan' => 'ANALIS SUMBER DAYA MANUSIA APARATUR AHLI PERTAMA'])->assertStatus(422);
        $this->sendJson('POST', 'api/v1/master/jabatan', $fungsional + ['id_satker' => '1', 'jabatan' => 'Analis Sumber Daya Manusia Aparatur Ahli Pertama'])->assertStatus(201);

        // Ubah satker jabatan 2 (sub group 2) ke satker yang sudah punya nama sama di sub group itu → 422.
        $this->sendJson('POST', 'api/v1/master/jabatan', ['id_group_jabatan' => '1', 'id_sub_group_jabatan' => '2', 'id_satker' => '2', 'jabatan' => 'Kepala Bagian Mutasi'])->assertStatus(201);
        $this->sendJson('PUT', 'api/v1/master/jabatan/2', ['id_satker' => '2'])->assertStatus(422);
        $this->seeInDatabase('jabatan', ['id_jabatan' => 2, 'id_satker' => 1]);
    }

    /**
     * G-TC #2 / DoD G-02: satker yang masih dirujuk (di Fase 2: jabatan; pegawai menyusul di B-01) hanya bisa
     * di-soft-delete — status 10, jabatan tetap utuh, hilang dari dropdown; hard delete ditolak FK RESTRICT. Sama untuk
     * unit yang masih punya satker dan kelas jabatan yang masih dipakai jabatan.
     */
    public function testReferencedMastersAreOnlySoftDeleted(): void
    {
        foreach ([['satker', '1', 'satker', 'id_satker'], ['unit', '1', 'unit', 'id_unit'], ['kelas-jabatan', '13', 'kelas_jabatan', 'kelas_jabatan'], ['sub-group-jabatan', '1', 'sub_group_jabatan', 'id_sub_group_jabatan']] as [$key, $id, $table, $pk]) {
            $result = $this->delete("api/v1/master/{$key}/{$id}");
            $result->assertStatus(200);
            $this->assertTrue($this->json($result)['data']['soft_delete'], $key);
            $this->seeInDatabase($table, [$pk => $id, 'status' => 10]);
            $this->assertNotContains($id, $this->optionIds($key), $key);

            $failed = false;

            try {
                $failed = $this->db->table($table)->where($pk, $id)->delete() === false;
            } catch (Throwable) {
                $failed = true;
            }

            $this->assertTrue($failed, "{$table}: FK RESTRICT harus menolak hard delete");
            $this->seeInDatabase($table, [$pk => $id]);
        }

        $this->seeInDatabase('jabatan', ['id_jabatan' => 1, 'id_satker' => 1, 'kelas_jabatan' => 13, 'id_sub_group_jabatan' => 1, 'status' => 1]);
        $this->seeInDatabase('satker', ['id_satker' => 2, 'id_unit' => 1, 'status' => 1]);

        // Pulihkan → kembali di dropdown.
        $this->sendJson('PATCH', 'api/v1/master/satker/1/status', ['status' => '1'])->assertStatus(200);
        $this->sendJson('PATCH', 'api/v1/master/unit/1/status', ['status' => '1'])->assertStatus(200);
        $this->assertContains('1', $this->optionIds('satker', '1'));
    }

    /**
     * `jabatan.id_jenjang_jf` (tabel jenjang_jf ditunda) disimpan tetapi tidak dikirim maupun ditulis lewat API.
     */
    public function testJenjangJfColumnIsNotManaged(): void
    {
        $this->db->table('jabatan')->where('id_jabatan', 3)->update(['id_jenjang_jf' => 5]);

        foreach ([$this->get('api/v1/master/jabatan'), $this->get('api/v1/master/jabatan/3')] as $response) {
            $response->assertStatus(200);
            $this->assertStringNotContainsString('id_jenjang_jf', (string) $response->getJSON());
        }

        $this->sendJson('PUT', 'api/v1/master/jabatan/3', ['id_jenjang_jf' => '9', 'jabatan' => 'Analis SDM Aparatur Ahli Pertama'])->assertStatus(200);
        $this->seeInDatabase('jabatan', ['id_jabatan' => 3, 'id_jenjang_jf' => 5, 'jabatan' => 'Analis SDM Aparatur Ahli Pertama']);

        $created = $this->json($this->sendJson('POST', 'api/v1/master/jabatan', [
            'id_group_jabatan' => '2', 'id_sub_group_jabatan' => '3', 'jabatan' => 'Analis Uji', 'id_jenjang_jf' => '9',
        ]))['data'];
        $this->seeInDatabase('jabatan', ['id_jabatan' => $created['id_jabatan'], 'id_jenjang_jf' => null]);
    }

    /**
     * Audit: tambah/ubah/hapus keenam master tercatat di audit_logs dengan aktor, kolom audit legacy diisi (created_at
     * saat tambah, updated_by = id_pengguna aktor), termasuk kelas jabatan ber-PK alami.
     */
    public function testAuditLogsCarryActorForKelasJabatan(): void
    {
        $this->sendJson('POST', 'api/v1/master/kelas-jabatan', ['kelas_jabatan' => '17', 'tukin' => '33240000'])->assertStatus(201);
        $this->sendJson('PUT', 'api/v1/master/kelas-jabatan/17', ['tukin' => '33250000'])->assertStatus(200);
        $this->delete('api/v1/master/kelas-jabatan/17')->assertStatus(200);

        foreach (['create', 'update', 'delete'] as $event) {
            $this->seeInDatabase('audit_logs', ['entity' => 'kelas_jabatan', 'entity_id' => '17', 'event' => $event, 'nip_actor' => self::ADMIN_NIP]);
        }

        $adminId = (int) $this->db->table('pengguna')->select('id_pengguna')->where('nip', self::ADMIN_NIP)->get()->getRowArray()['id_pengguna'];
        $this->seeInDatabase('kelas_jabatan', ['kelas_jabatan' => 17, 'status' => 10, 'updated_by' => $adminId]);
    }
}
