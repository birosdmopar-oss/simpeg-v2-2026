<?php

declare(strict_types=1);

namespace Tests\MasterData;

use App\Constants\Role;
use App\Exceptions\ValidationException;
use CodeIgniter\I18n\Time;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\AuthTestTrait;
use Tests\Support\Database\Seeds\MasterDataSeeder;
use Tests\Support\DatabaseTestCase;
use Tests\Support\MasterDataTestTrait;

/**
 * G-04 — Kenaikan Pangkat (DBV-004/CR-011): `pangkat`, `jenis-kp`, `gol-pppk` lewat engine master generik.
 *
 *  - `pangkat.order` = level pangkat (P2): mode urutan manual, dibatasi TINYINT (127), tambah tanpa `order` = MAX+1
 *    seluruh pangkat (tanpa lingkup cpns, C6); G-TC #4 (penggeseran) tidak berlaku.
 *  - `pangkat` nama = `gol_ruang` unik (P1); `cpns`/`gol`/`ruang` pilihan tetap form legacy; dropdown `?cpns=`.
 *  - `jenis-kp` tetap mode geser (pembeda dari pangkat).
 *  - `gol-pppk`: soft delete menyimpan 10 (regresi ENUM legacy, P4), `uang_makan` dapat diubah role 1 (K5, C3),
 *    `keterangan` ≤ 255 byte (TINYTEXT).
 *  - Kolom audit: pangkat/jenis_kp pola D1 (`updated_at` + `updated_by`, tanpa `created_*`); gol_pppk pola FAQ.
 *
 * CRUD generik, RBAC, dan audit log ketiga master ikut diuji MasterGenericTcTest & RbacMasterEndpointsTest (fixture
 * MasterDataTestTrait). Data seed: MasterDataSeeder::seedKenaikanPangkat().
 *
 * @internal
 */
final class KenaikanPangkatTest extends DatabaseTestCase
{
    use FeatureTestTrait;
    use AuthTestTrait;
    use MasterDataTestTrait;

    protected $seed = MasterDataSeeder::class;

    private const ADMIN_NIP = '198501012010011001';

    private const PANGKAT = 'api/v1/master/pangkat';

    private const PANGKAT_IDS = ['7', '13', '14', '15'];

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

    // ------------------------------------------------------------------
    // pangkat — urutan = level (mode manual)
    // ------------------------------------------------------------------

    /**
     * P2: nilai `order` pangkat disimpan apa adanya dan tidak pernah menggeser/menomori ulang pangkat lain — tambah,
     * ubah (PUT/PATCH), hapus, dan pulihkan. Level sama antara CPNS dan PNS boleh (P3). Tambah tanpa `order` = MAX+1
     * seluruh pangkat (C6).
     */
    public function testPangkatManualOrderNeverShiftsOthers(): void
    {
        $this->assertSame([9, 9, 10, 11], $this->orders('pangkat', 'id_pangkat', self::PANGKAT_IDS));

        // Level 10 sudah dipakai III/b (PNS): CPNS III/b tetap level 10, tidak ada yang bergeser.
        $cpns = $this->sendJson('POST', self::PANGKAT, ['cpns' => '1', 'pangkat' => 'Penata Muda Tingkat I', 'gol' => 'III', 'ruang' => 'b', 'gol_ruang' => 'CPNS III/b', 'order' => 10]);
        $cpns->assertStatus(201);
        $cpnsId = (string) $this->json($cpns)['data']['id_pangkat'];
        $this->assertSame(10, (int) $this->json($cpns)['data']['order']);
        $this->assertSame([9, 9, 10, 11], $this->orders('pangkat', 'id_pangkat', self::PANGKAT_IDS));

        // Tanpa order: MAX+1 seluruh pangkat (CPNS maupun PNS).
        $auto = $this->json($this->sendJson('POST', self::PANGKAT, ['cpns' => '2', 'pangkat' => 'Penata Tingkat I', 'gol' => 'III', 'ruang' => 'd', 'gol_ruang' => 'III/d']))['data'];
        $this->assertSame(12, (int) $auto['order']);

        // PATCH order & PUT order: hanya entri itu yang berubah (dan di-stamp).
        $this->sendJson('PATCH', self::PANGKAT . '/15/order', ['order' => 20])->assertStatus(200);
        $this->seeInDatabase('pangkat', ['id_pangkat' => 15, 'order' => 20, 'updated_by' => $this->adminId()]);
        $this->sendJson('PUT', self::PANGKAT . '/13', ['order' => 8])->assertStatus(200);
        $this->assertSame([9, 8, 10, 20, 10, 12], $this->orders('pangkat', 'id_pangkat', [...self::PANGKAT_IDS, $cpnsId, (string) $auto['id_pangkat']]));

        // Hapus tidak merapatkan; pulihkan mempertahankan level.
        $this->delete(self::PANGKAT . '/14')->assertStatus(200);
        $this->seeInDatabase('pangkat', ['id_pangkat' => 14, 'status' => 10, 'order' => 10]);
        $this->assertSame([9, 8, 20, 10, 12], $this->orders('pangkat', 'id_pangkat', ['7', '13', '15', $cpnsId, (string) $auto['id_pangkat']]));
        $this->sendJson('PATCH', self::PANGKAT . '/14/status', ['status' => '1'])->assertStatus(200);
        $this->seeInDatabase('pangkat', ['id_pangkat' => 14, 'status' => 1, 'order' => 10]);

        // Tidak ada audit "geser" untuk pangkat yang tidak diedit.
        $this->dontSeeInDatabase('audit_logs', ['entity' => 'pangkat', 'entity_id' => '7', 'event' => 'update']);

        // Dropdown urut level lalu gol_ruang.
        $this->assertSame(['13', '7', $cpnsId, '14', (string) $auto['id_pangkat'], '15'], $this->optionIds('pangkat'));
    }

    /**
     * Level pangkat dibatasi TINYINT signed (127) di tambah, ubah, dan PATCH order; MAX+1 otomatis yang melewati batas
     * juga 422 (bukan 500 / terpotong diam-diam di koneksi non-strict).
     */
    public function testPangkatOrderIsBoundedByTinyint(): void
    {
        $payload = ['cpns' => '2', 'pangkat' => 'Pembina', 'gol' => 'IV', 'ruang' => 'a', 'gol_ruang' => 'IV/a'];

        foreach ([
            ['POST', self::PANGKAT, $payload + ['order' => 128]],
            ['PUT', self::PANGKAT . '/14', ['order' => 128]],
            ['PATCH', self::PANGKAT . '/14/order', ['order' => 128]],
        ] as [$method, $uri, $body]) {
            $result = $this->sendJson($method, $uri, $body);
            $result->assertStatus(422);
            $this->assertSame(['Urutan maksimal 127.'], $this->json($result)['errors']['order'], "{$method} {$uri}");
        }

        $result = $this->sendJson('POST', self::PANGKAT, $payload + ['order' => 0]);
        $result->assertStatus(422);
        $this->assertSame(['Urutan harus bilangan bulat minimal 1.'], $this->json($result)['errors']['order']);
        $this->dontSeeInDatabase('pangkat', ['gol_ruang' => 'IV/a']);
        $this->seeInDatabase('pangkat', ['id_pangkat' => 14, 'order' => 10]);

        $this->sendJson('PATCH', self::PANGKAT . '/14/order', ['order' => 127])->assertStatus(200);
        $this->seeInDatabase('pangkat', ['id_pangkat' => 14, 'order' => 127]);

        $result = $this->sendJson('POST', self::PANGKAT, $payload);
        $result->assertStatus(422);
        $this->assertSame(['Urutan Pangkat/Golongan sudah mencapai batas maksimal 127.'], $this->json($result)['errors']['order']);
        $this->dontSeeInDatabase('pangkat', ['gol_ruang' => 'IV/a']);

        $meta = $this->metaByKey()['pangkat'];
        $this->assertSame(['manual', 127, [], ['cpns']], [$meta['order_mode'], $meta['order_max'], $meta['order_scope'], $meta['filters']]);
        $this->assertSame(['gol_ruang', 'Gol./Ruang', 10], [$meta['name_field'], $meta['name_label'], $meta['name_max_length']]);
    }

    /**
     * P1: `gol_ruang` unik case-insensitive termasuk terhadap pangkat yang dihapus (dengan saran pulihkan); `cpns`, `gol`,
     * `ruang` hanya pilihan form legacy (peka huruf); panjang mengikuti kolom (`pangkat` 50, `gol_ruang` 10).
     */
    public function testPangkatGolRuangIsUniqueAndFieldsValidated(): void
    {
        $valid = ['cpns' => '1', 'pangkat' => 'Penata Muda Tingkat I', 'gol' => 'III', 'ruang' => 'b'];

        $result = $this->sendJson('POST', self::PANGKAT, $valid + ['gol_ruang' => 'iii/b']);
        $result->assertStatus(422);
        $this->assertSame(['Gol./Ruang "iii/b" sudah ada dengan kode 14.'], $this->json($result)['errors']['gol_ruang']);
        $this->sendJson('PUT', self::PANGKAT . '/7', ['gol_ruang' => 'III/A'])->assertStatus(422);

        $this->delete(self::PANGKAT . '/15')->assertStatus(200);
        $result = $this->sendJson('POST', self::PANGKAT, ['gol_ruang' => 'III/C'] + $valid);
        $result->assertStatus(422);
        $this->assertStringContainsString('sudah dihapus', $this->json($result)['errors']['gol_ruang'][0]);

        $cases = [
            ['cpns', ['cpns' => '3'], 'Jenis Pangkat tidak valid.'],
            ['cpns', ['cpns' => ''], 'Jenis Pangkat wajib diisi.'],
            ['gol', ['gol' => 'V'], 'Golongan tidak valid.'],
            ['gol', ['gol' => 'iii'], 'Golongan tidak valid.'],
            ['ruang', ['ruang' => 'f'], 'Ruang tidak valid.'],
            ['ruang', ['ruang' => 'B'], 'Ruang tidak valid.'],
            ['gol', ['gol' => ''], 'Golongan wajib diisi.'],
            ['ruang', ['ruang' => ''], 'Ruang wajib diisi.'],
            ['pangkat', ['pangkat' => str_repeat('a', 51)], 'Pangkat maksimal 50 karakter.'],
            ['pangkat', ['pangkat' => ''], 'Pangkat wajib diisi.'],
            ['gol_ruang', ['gol_ruang' => 'CPNS III/bb'], 'Gol./Ruang maksimal 10 karakter.'],
        ];

        foreach ($cases as [$field, $override, $message]) {
            $result = $this->sendJson('POST', self::PANGKAT, $override + $valid + ['gol_ruang' => 'CPNS III/b']);
            $result->assertStatus(422);
            $this->assertSame([$message], $this->json($result)['errors'][$field], (string) json_encode($override));
        }

        $this->dontSeeInDatabase('pangkat', ['gol_ruang' => 'CPNS III/b']);

        // Tepat 10 karakter (label CPNS terpanjang) muat.
        $this->sendJson('POST', self::PANGKAT, $valid + ['gol_ruang' => 'CPNS III/b'])->assertStatus(201);
        $this->seeInDatabase('pangkat', ['gol_ruang' => 'CPNS III/b', 'cpns' => 1, 'gol' => 'III', 'ruang' => 'b', 'pangkat' => 'Penata Muda Tingkat I']);

        // Pencarian daftar admin juga mencari nama pangkat (extraSearch), bukan hanya gol_ruang/kode.
        $found = $this->json($this->get(self::PANGKAT, ['search' => 'Tingkat', 'cpns' => '2']))['data']['items'];
        $this->assertSame(['14'], array_map('strval', array_column($found, 'id_pangkat')));
    }

    /**
     * Nilai `order` yang dianggap kosong oleh rule permit_empty (' ', false) tidak pernah menjadi level 0: tambah =
     * MAX+1, ubah = level tidak berubah; array → 422. Batas bawah juga dijaga service (lapis kedua controller). Mode
     * geser (jenis KP): ubah dengan `order` kosong tidak memindah entri.
     */
    public function testPangkatEmptyOrderNeverStoresZero(): void
    {
        $payload = ['cpns' => '2', 'pangkat' => 'Pembina', 'gol' => 'IV', 'ruang' => 'a'];

        foreach ([[' ', 'IV/a', 12], [false, 'IV/b', 13]] as [$order, $golRuang, $expected]) {
            $result = $this->sendJson('POST', self::PANGKAT, $payload + ['gol_ruang' => $golRuang, 'order' => $order]);
            $result->assertStatus(201);
            $this->assertSame($expected, (int) $this->json($result)['data']['order'], $golRuang);

            $this->sendJson('PUT', self::PANGKAT . '/14', ['order' => $order, 'pangkat' => "Penata Muda Tingkat I {$golRuang}"])->assertStatus(200);
            $this->seeInDatabase('pangkat', ['id_pangkat' => 14, 'order' => 10, 'pangkat' => "Penata Muda Tingkat I {$golRuang}"]);
        }

        $result = $this->sendJson('POST', self::PANGKAT, $payload + ['gol_ruang' => 'IV/c', 'order' => []]);
        $result->assertStatus(422);
        $this->assertSame(['Urutan tidak valid.'], $this->json($result)['errors']['order']);
        $this->dontSeeInDatabase('pangkat', ['order' => 0]);

        $def = service('masterRegistry')->get('pangkat');

        try {
            service('masterService')->update($def, '14', ['order' => 0]);
            $this->fail('Level pangkat 0 harus ditolak service.');
        } catch (ValidationException $e) {
            $this->assertSame(['order' => ['Urutan harus bilangan bulat minimal 1.']], $e->getErrors());
        }

        $this->seeInDatabase('pangkat', ['id_pangkat' => 14, 'order' => 10]);

        $this->sendJson('PUT', 'api/v1/master/jenis-kp/3', ['order' => ' ', 'jenis_kp' => 'Reguler Baru'])->assertStatus(200);
        $this->assertSame([1, 2, 3, 4], $this->orders('jenis_kp', 'id_jenis_kp', ['1', '2', '3', '5']));
    }

    /**
     * C6 (mode manual): level pangkat yang dihapus tetap dipegang dan kembali saat dipulihkan, jadi tambah tanpa `order`
     * = MAX+1 seluruh pangkat TERMASUK yang dihapus (tidak ada dua pangkat berlevel sama setelah pemulihan).
     */
    public function testPangkatAutoOrderCountsDeletedLevels(): void
    {
        $this->delete(self::PANGKAT . '/15')->assertStatus(200);

        $created = $this->json($this->sendJson('POST', self::PANGKAT, ['cpns' => '2', 'pangkat' => 'Penata Tingkat I', 'gol' => 'III', 'ruang' => 'd', 'gol_ruang' => 'III/d']))['data'];
        $this->assertSame(12, (int) $created['order']);

        $this->sendJson('PATCH', self::PANGKAT . '/15/status', ['status' => '1'])->assertStatus(200);
        $this->assertSame([11, 12], $this->orders('pangkat', 'id_pangkat', ['15', (string) $created['id_pangkat']]));
    }

    /**
     * PK TINYINT signed yang habis (ID 127 terpakai; InnoDB mengulang nilai maksimum → 1062 PRIMARY): tambah → 422
     * dengan penjelasan, bukan 500. Berlaku untuk semua master AUTO_INCREMENT (G-doc 6.3 #16). Di MariaDB 10.4 kasus
     * yang sama nyata memberi 167 "Out of range value" dengan hasil 422 yang sama (simulasi di MasterGenericTcTest).
     */
    public function testPangkatFullTinyintKeyGives422(): void
    {
        $this->db->table('pangkat')->insert(['id_pangkat' => 127, 'pangkat' => 'Pembina Utama', 'gol' => 'IV', 'ruang' => 'e', 'gol_ruang' => 'IV/e', 'cpns' => 2, 'order' => 17, 'status' => 1]);

        $result = $this->sendJson('POST', self::PANGKAT, ['cpns' => '2', 'pangkat' => 'Pembina', 'gol' => 'IV', 'ruang' => 'a', 'gol_ruang' => 'IV/a', 'order' => 13]);
        $result->assertStatus(422);
        $this->assertSame(
            'Kode Pangkat/Golongan sudah mencapai batas maksimal tipe kolom, sehingga entri baru tidak bisa ditambahkan. Hubungi admin database.',
            $this->json($result)['message'],
        );
        $this->dontSeeInDatabase('pangkat', ['gol_ruang' => 'IV/a']);
    }

    /**
     * Dropdown pangkat per jenis KP legacy (`L_kp.php:56, 67`): `?cpns=1|2` di options dan daftar admin; label = gol_ruang;
     * nilai filter tidak sah → 422; kolom lain di query diabaikan; cache per filter ikut di-invalidate saat ditulis.
     */
    public function testPangkatOptionsAndListFilterByCpns(): void
    {
        $this->assertSame(['7'], $this->optionIds('pangkat', null, ['cpns' => '1']));
        $this->assertSame(['13', '14', '15'], $this->optionIds('pangkat', null, ['cpns' => '2']));
        $this->assertSame(['7', '13', '14', '15'], $this->optionIds('pangkat'));
        $this->assertSame(['7', '13', '14', '15'], $this->optionIds('pangkat', null, ['gol' => 'IV', 'pangkat' => 'Penata']));

        $first = $this->json($this->get(self::PANGKAT . '/options', ['cpns' => '2']))['data'][0];
        $this->assertSame(['id' => '13', 'nama' => 'III/a', 'parent' => null], $first);

        foreach ([['cpns' => '3'], ['cpns' => ['1']], ['cpns' => '1 OR 1=1']] as $query) {
            $result = $this->get(self::PANGKAT . '/options', $query);
            $result->assertStatus(422);
            $this->assertSame(['Filter Jenis Pangkat tidak valid.'], $this->json($result)['errors']['cpns']);
        }

        $list = $this->json($this->get(self::PANGKAT, ['cpns' => '1']))['data'];
        $this->assertSame([1, ['7']], [$list['total'], array_map('strval', array_column($list['items'], 'id_pangkat'))]);
        $this->get(self::PANGKAT, ['cpns' => '9'])->assertStatus(422);

        // Dropdown terfilter yang sudah ter-cache langsung mengikuti perubahan cpns.
        $this->sendJson('PUT', self::PANGKAT . '/14', ['cpns' => '1'])->assertStatus(200);
        $this->assertSame(['7', '14'], $this->optionIds('pangkat', null, ['cpns' => '1']));
        $this->assertSame(['13', '15'], $this->optionIds('pangkat', null, ['cpns' => '2']));

        // Terbuka untuk semua role yang login (form riwayat KP Fase 3).
        $this->asRole(Role::PEGAWAI);
        $this->assertSame(['13', '15'], $this->optionIds('pangkat', null, ['cpns' => '2']));
    }

    // ------------------------------------------------------------------
    // jenis-kp
    // ------------------------------------------------------------------

    /**
     * Jenis KP tetap mode geser (G-TC #4): sisip di posisi 1 menggeser, hapus merapatkan — pembeda dari pangkat.
     */
    public function testJenisKpKeepsShiftOrder(): void
    {
        $created = $this->json($this->sendJson('POST', 'api/v1/master/jenis-kp', ['jenis_kp' => 'Pilihan', 'order' => 1]))['data'];
        $newId   = (string) $created['id_jenis_kp'];
        $this->assertSame([1, 2, 3, 4, 5], $this->orders('jenis_kp', 'id_jenis_kp', [$newId, '1', '2', '3', '5']));

        $this->delete('api/v1/master/jenis-kp/2')->assertStatus(200);
        $this->assertSame([1, 2, 3, 4], $this->orders('jenis_kp', 'id_jenis_kp', [$newId, '1', '3', '5']));
        $this->assertSame([$newId, '1', '3', '5'], $this->optionIds('jenis-kp'));

        $meta = $this->metaByKey()['jenis-kp'];
        $this->assertSame(['shift', null], [$meta['order_mode'], $meta['order_max']]);
    }

    // ------------------------------------------------------------------
    // gol-pppk
    // ------------------------------------------------------------------

    /**
     * P4 (regresi bug ENUM legacy): hapus menyimpan status 10 di `gol_pppk.status` (bukan string kosong), tersembunyi dari
     * daftar default & dropdown, lalu bisa dipulihkan.
     */
    public function testGolPppkSoftDeleteStoresStatus10AndRestores(): void
    {
        $this->assertContains('9', $this->optionIds('gol-pppk'));

        $result = $this->delete('api/v1/master/gol-pppk/9');
        $result->assertStatus(200);
        $this->assertSame('10', (string) $this->json($result)['data']['item']['status']);
        $this->seeInDatabase('gol_pppk', ['id_gol_pppk' => 9, 'status' => 10]);

        $default = $this->json($this->get('api/v1/master/gol-pppk'))['data'];
        $this->assertNotContains('9', array_map('strval', array_column($default['items'], 'id_gol_pppk')));
        $deleted = $this->json($this->get('api/v1/master/gol-pppk', ['status' => '10']))['data'];
        $this->assertSame(['9'], array_map('strval', array_column($deleted['items'], 'id_gol_pppk')));
        $this->assertNotContains('9', $this->optionIds('gol-pppk'));

        $this->sendJson('PATCH', 'api/v1/master/gol-pppk/9/status', ['status' => '2'])->assertStatus(200);
        $this->seeInDatabase('gol_pppk', ['id_gol_pppk' => 9, 'status' => 2]);
        $this->sendJson('PATCH', 'api/v1/master/gol-pppk/9/status', ['status' => '1'])->assertStatus(200);
        $this->seeInDatabase('gol_pppk', ['id_gol_pppk' => 9, 'status' => 1]);
        $this->assertContains('9', $this->optionIds('gol-pppk'));
    }

    /**
     * K5 c(ii) + C3: `uang_makan` dapat diubah role 1, wajib, angka 0..10.000.000 (desimal boleh); role lain 403.
     */
    public function testGolPppkUangMakanIsEditableAndValidated(): void
    {
        $this->sendJson('PUT', 'api/v1/master/gol-pppk/9', ['uang_makan' => '41000.5'])->assertStatus(200);
        $this->assertSame(41000.5, (float) $this->golPppk(9)['uang_makan']);

        $result = $this->sendJson('POST', 'api/v1/master/gol-pppk', ['gol_pppk' => 'XIII']);
        $result->assertStatus(422);
        $this->assertSame(['Uang Makan wajib diisi.'], $this->json($result)['errors']['uang_makan']);
        $this->dontSeeInDatabase('gol_pppk', ['gol_pppk' => 'XIII']);

        $cases = [
            ['', 'Uang Makan wajib diisi.'],
            ['-1', 'Uang Makan minimal 0.'],
            ['abc', 'Uang Makan harus angka (pakai titik untuk desimal).'],
            ['1e9', 'Uang Makan harus angka (pakai titik untuk desimal).'],
            ['10000000.01', 'Uang Makan maksimal 10.000.000.'],
            ['99999999999999999999999999999999999999', 'Uang Makan maksimal 10.000.000.'],
        ];

        foreach ($cases as [$value, $message]) {
            $result = $this->sendJson('PUT', 'api/v1/master/gol-pppk/9', ['uang_makan' => $value]);
            $result->assertStatus(422);
            $this->assertSame([$message], $this->json($result)['errors']['uang_makan'], $value);
        }

        $this->assertSame(41000.5, (float) $this->golPppk(9)['uang_makan']);

        foreach (['0', '10000000', 38000] as $value) {
            $this->sendJson('PUT', 'api/v1/master/gol-pppk/9', ['uang_makan' => $value])->assertStatus(200);
            $this->assertSame((float) $value, (float) $this->golPppk(9)['uang_makan']);
        }

        $this->asRole(Role::ADMIN_SATKER)->sendJson('PUT', 'api/v1/master/gol-pppk/9', ['uang_makan' => '1'])->assertStatus(403);
        $this->assertSame(38000.0, (float) $this->golPppk(9)['uang_makan']);

        $this->asRole(Role::SUPER_ADMIN);
        $fields = array_column($this->metaByKey()['gol-pppk']['fields'], null, 'name');
        $this->assertSame(['decimal', true, 0, 10000000], [$fields['uang_makan']['type'], $fields['uang_makan']['required'], $fields['uang_makan']['min'], $fields['uang_makan']['max']]);
    }

    /**
     * `keterangan` TINYTEXT [K] dibatasi 255 BYTE (128 × 'é' = 256 byte → 422); kosong = NULL.
     */
    public function testGolPppkKeteranganIsLimitedTo255Bytes(): void
    {
        $result = $this->sendJson('PUT', 'api/v1/master/gol-pppk/9', ['keterangan' => str_repeat('é', 128)]);
        $result->assertStatus(422);
        $this->assertSame(['Keterangan maksimal 255 byte.'], $this->json($result)['errors']['keterangan']);

        $fits = str_repeat('é', 127) . 'a';
        $this->sendJson('PUT', 'api/v1/master/gol-pppk/9', ['keterangan' => $fits])->assertStatus(200);
        $this->assertSame($fits, $this->golPppk(9)['keterangan']);

        $this->sendJson('PUT', 'api/v1/master/gol-pppk/9', ['keterangan' => ''])->assertStatus(200);
        $this->assertNull($this->golPppk(9)['keterangan']);

        // Spasi saja = kosong (NULL, bukan ''); array/objek JSON → 422, bukan 500.
        $this->sendJson('PUT', 'api/v1/master/gol-pppk/9', ['keterangan' => $fits])->assertStatus(200);
        $this->sendJson('PUT', 'api/v1/master/gol-pppk/9', ['keterangan' => '   '])->assertStatus(200);
        $this->assertNull($this->golPppk(9)['keterangan']);

        foreach ([[], ['a']] as $value) {
            $result = $this->sendJson('PUT', 'api/v1/master/gol-pppk/9', ['keterangan' => $value]);
            $result->assertStatus(422);
            $this->assertSame(['Keterangan tidak valid.'], $this->json($result)['errors']['keterangan']);
        }
    }

    // ------------------------------------------------------------------
    // Kolom audit
    // ------------------------------------------------------------------

    /**
     * D1: pangkat & jenis_kp tanpa `created_*` — tambah mengisi `updated_at` (jam aplikasi) + `updated_by`. gol_pppk (DDL
     * [K], pola FAQ E1): tambah mengisi `created_at`/`created_by` dan juga `updated_at` (perilaku CI4, preseden G-10 #9),
     * `updated_by` baru terisi saat ubah.
     */
    public function testAuditColumns(): void
    {
        $adminId = $this->adminId();
        Time::setTestNow('2020-01-02 03:04:05', 'UTC');

        $created = [
            'pangkat'  => $this->json($this->sendJson('POST', self::PANGKAT, ['cpns' => '2', 'pangkat' => 'Pembina', 'gol' => 'IV', 'ruang' => 'a', 'gol_ruang' => 'IV/a']))['data'],
            'jenis-kp' => $this->json($this->sendJson('POST', 'api/v1/master/jenis-kp', ['jenis_kp' => 'Pilihan']))['data'],
        ];

        foreach ($created as $entity => $row) {
            $this->assertArrayNotHasKey('created_at', $row, $entity);
            $this->assertArrayNotHasKey('created_by', $row, $entity);
            $this->assertSame('2020-01-02 03:04:05', $row['updated_at'], $entity);
            $this->assertSame($adminId, (int) $row['updated_by'], $entity);
        }

        $gol = $this->json($this->sendJson('POST', 'api/v1/master/gol-pppk', ['gol_pppk' => 'V', 'uang_makan' => '35000']))['data'];
        $this->assertSame(['2020-01-02 03:04:05', $adminId, '2020-01-02 03:04:05', null], [$gol['created_at'], (int) $gol['created_by'], $gol['updated_at'], $gol['updated_by']]);

        Time::setTestNow('2020-02-03 04:05:06', 'UTC');
        $this->sendJson('PUT', 'api/v1/master/gol-pppk/' . $gol['id_gol_pppk'], ['uang_makan' => '36000'])->assertStatus(200);
        $this->seeInDatabase('gol_pppk', ['id_gol_pppk' => $gol['id_gol_pppk'], 'created_at' => '2020-01-02 03:04:05', 'created_by' => $adminId, 'updated_at' => '2020-02-03 04:05:06', 'updated_by' => $adminId]);

        $this->sendJson('PUT', self::PANGKAT . '/13', ['pangkat' => 'Penata Muda (III/a)'])->assertStatus(200);
        $this->seeInDatabase('pangkat', ['id_pangkat' => 13, 'updated_at' => '2020-02-03 04:05:06', 'updated_by' => $adminId]);
    }

    // ------------------------------------------------------------------
    // Helper
    // ------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function golPppk(int $id): array
    {
        /** @var array<string, mixed> $row */
        $row = $this->db->table('gol_pppk')->where('id_gol_pppk', $id)->get()->getRowArray();

        return $row;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function metaByKey(): array
    {
        return array_column($this->json($this->get('api/v1/master/meta'))['data'], null, 'key');
    }

    private function adminId(): int
    {
        return (int) $this->db->table('pengguna')->select('id_pengguna')->where('nip', self::ADMIN_NIP)->get()->getRowArray()['id_pengguna'];
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
