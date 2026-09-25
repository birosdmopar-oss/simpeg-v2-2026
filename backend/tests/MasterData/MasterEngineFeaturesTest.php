<?php

declare(strict_types=1);

namespace Tests\MasterData;

use App\Constants\Role;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\AuthTestTrait;
use Tests\Support\Database\Seeds\MasterDataSeeder;
use Tests\Support\Libraries\UjiJurusanHooks;
use Tests\Support\MasterDataTestTrait;
use Tests\Support\MasterUjiTestTrait;

/**
 * CR-009 — fitur engine master generik untuk grup DBV-003/004/005, diuji lewat HTTP pada master UJI
 * (Tests\Support\Config\MasterDataUji, tabel Tests\Support CreateMasterUjiTables; bukan master grup mana pun):
 *
 *  - mode urutan manual (uji-level, pola level pangkat DBV-004): disimpan apa adanya, tidak menggeser/menomori ulang;
 *  - orderScope (uji-diklat, pola diklat per jenis DBV-005): urutan & penggeseran per jenis;
 *  - uniqueFields (uji-jurusan): UNIQUE selain nama → 422 pada field itu, termasuk balapan 1062;
 *  - batas angka per tipe kolom (TINYINT, INT UNSIGNED, INT) → 422, bukan 500/terpotong;
 *  - field boolean 1/0 (termasuk JSON true/false);
 *  - statusChain (uji-jurusan di bawah uji-bidang, uji-dusun di bawah wilayah 4 level): dropdown ikut rantai status;
 *  - filter options/daftar dengan allowlist kolom;
 *  - field ref + dependsOn (uji-kantor → provinsi, kabupaten-kota).
 *
 * @internal
 */
final class MasterEngineFeaturesTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use AuthTestTrait;
    use MasterDataTestTrait;
    use MasterUjiTestTrait;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = null;
    protected $seed      = MasterDataSeeder::class;

    private const ADMIN_NIP = '198501012010011001';

    protected function setUp(): void
    {
        parent::setUp();
        $this->clearAuthState();
        $this->useMasterUji();
        $this->resetMasterState();
        $this->seedUji();
        $this->asRole(Role::SUPER_ADMIN);
    }

    protected function tearDown(): void
    {
        UjiJurusanHooks::$concurrentRow = null;
        $this->clearAuthState();
        $this->forgetMasterUji();
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Mode urutan manual
    // ------------------------------------------------------------------

    /**
     * Mode manual: `order` = nilai bisnis (level). Tambah/ubah/pindah/hapus/pulihkan tidak pernah menggeser atau
     * menomori ulang entri lain; nilai kembar boleh; kosong saat tambah = MAX+1.
     */
    public function testManualOrderIsStoredAsIsAndNeverShiftsOthers(): void
    {
        $base = 'api/v1/master/uji-level';

        $created = $this->sendJson('POST', $base, ['level' => 'II/a', 'kategori' => '2', 'order' => 3]);
        $created->assertStatus(201);
        $this->assertSame(3, (int) $this->json($created)['data']['order']);
        $this->assertSame([1, 2, 1, 5, 3], $this->orders('uji_level', 'id_level', ['1', '2', '3', '4', '5']));

        // Nilai sama dengan entri lain boleh (level kembar), tidak ada yang digeser.
        $this->sendJson('POST', $base, ['level' => 'II/b', 'kategori' => '2', 'order' => 1])->assertStatus(201);
        $this->assertSame([1, 2, 1, 5, 3, 1], $this->orders('uji_level', 'id_level', ['1', '2', '3', '4', '5', '6']));

        // Tanpa order = MAX+1.
        $auto = $this->json($this->sendJson('POST', $base, ['level' => 'II/c', 'kategori' => '2']))['data'];
        $this->assertSame(6, (int) $auto['order']);

        // PATCH order & PUT order: hanya entri itu yang berubah (dan di-stamp), entri lain tetap.
        $this->sendJson('PATCH', "{$base}/2/order", ['order' => 10])->assertStatus(200);
        $this->seeInDatabase('uji_level', ['id_level' => 2, 'order' => 10, 'updated_by' => $this->adminId()]);
        $this->sendJson('PUT', "{$base}/1", ['order' => 7])->assertStatus(200);
        $this->assertSame([7, 10, 1, 5], $this->orders('uji_level', 'id_level', ['1', '2', '3', '4']));

        // Hapus tidak merapatkan urutan; pulihkan mempertahankan nilai urutan.
        $this->delete("{$base}/4")->assertStatus(200);
        $this->assertSame([7, 10, 1, 5], $this->orders('uji_level', 'id_level', ['1', '2', '3', '4']));
        $this->sendJson('PATCH', "{$base}/4/status", ['status' => '1'])->assertStatus(200);
        $this->assertSame([7, 10, 1, 5], $this->orders('uji_level', 'id_level', ['1', '2', '3', '4']));

        // Tidak ada audit "geser" untuk entri yang tidak diedit.
        $this->dontSeeInDatabase('audit_logs', ['entity' => 'uji_level', 'entity_id' => '3', 'event' => 'update']);

        // Daftar & dropdown tetap urut nilai urutan lalu nama.
        $this->assertSame(['3', '6', '5', '4', '7', '1', '2'], $this->optionIds('uji-level'));

        // Nilai di atas jumlah entri disimpan apa adanya (bukan posisi tampil yang dijepit ke jumlah entri + 1).
        $high = $this->json($this->sendJson('POST', $base, ['level' => 'IV/e', 'kategori' => '2', 'order' => 50]))['data'];
        $this->assertSame(50, (int) $high['order']);
        $this->assertSame([7, 10, 1, 5], $this->orders('uji_level', 'id_level', ['1', '2', '3', '4']));
    }

    /**
     * Mode manual: nilai urutan dibatasi tipe kolom `order` (TINYINT = 127) → 422, di tambah, ubah, dan PATCH order;
     * MAX+1 otomatis yang melewati batas juga 422 (bukan 500 / terpotong diam-diam).
     */
    public function testManualOrderIsBoundedByOrderColumnType(): void
    {
        $base = 'api/v1/master/uji-level';

        foreach ([
            ['POST', $base, ['level' => 'Terlalu Tinggi', 'kategori' => '2', 'order' => 128]],
            ['PUT', "{$base}/1", ['order' => 128]],
            ['PATCH', "{$base}/1/order", ['order' => 128]],
        ] as [$method, $uri, $body]) {
            $result = $this->sendJson($method, $uri, $body);
            $result->assertStatus(422);
            $this->assertSame(['Urutan maksimal 127.'], $this->json($result)['errors']['order'], "{$method} {$uri}");
        }

        $this->seeInDatabase('uji_level', ['id_level' => 1, 'order' => 1]);
        $this->sendJson('PATCH', "{$base}/1/order", ['order' => 127])->assertStatus(200);

        $result = $this->sendJson('POST', $base, ['level' => 'Otomatis', 'kategori' => '2']);
        $result->assertStatus(422);
        $this->assertSame(['Urutan Level Uji sudah mencapai batas maksimal 127.'], $this->json($result)['errors']['order']);
        $this->dontSeeInDatabase('uji_level', ['level' => 'Otomatis']);

        $meta = $this->metaByKey();
        $this->assertSame(['manual', 127, []], [$meta['uji-level']['order_mode'], $meta['uji-level']['order_max'], $meta['uji-level']['order_scope']]);
        // Master mode shift (bawaan) tidak punya batas nilai urutan di meta (posisi dijepit ke jumlah entri).
        $this->assertSame(['shift', null], [$meta['agama']['order_mode'], $meta['agama']['order_max']]);
        $this->assertSame(['shift', null], [$meta['faq-article']['order_mode'], $meta['faq-article']['order_max']]);
    }

    // ------------------------------------------------------------------
    // orderScope
    // ------------------------------------------------------------------

    /**
     * orderScope `jenis`: urutan 1..n dan penggeseran berlaku per jenis — tambah, sisip, reorder, pindah jenis, hapus,
     * dan pulihkan hanya menyentuh jenis yang bersangkutan. Daftar & dropdown dikelompokkan per jenis lalu urutan.
     */
    public function testOrderScopeKeepsOrderingPerScope(): void
    {
        $base = 'api/v1/master/uji-diklat';
        $ids  = ['1', '2', '3', '4', '5', '6', '7'];

        // Tanpa order: akhir jenisnya (3), bukan akhir global.
        $teknisC = $this->json($this->sendJson('POST', $base, ['diklat' => 'Teknis C', 'jenis' => '2']))['data'];
        $this->assertSame(['6', 3], [(string) $teknisC['id_diklat'], (int) $teknisC['order']]);

        // Sisip di posisi 1 jenis 1: hanya jenis 1 yang bergeser.
        $this->sendJson('POST', $base, ['diklat' => 'Diklatpim I', 'jenis' => '1', 'order' => 1])->assertStatus(201);
        $this->assertSame([2, 3, 4, 1, 2, 3, 1], $this->orders('uji_diklat', 'id_diklat', $ids));

        // Reorder di jenis 2.
        $this->sendJson('PATCH', "{$base}/6/order", ['order' => 1])->assertStatus(200);
        $this->assertSame([2, 3, 4, 2, 3, 1, 1], $this->orders('uji_diklat', 'id_diklat', $ids));

        // Pindah jenis: taruh di akhir jenis baru, jenis lama dirapatkan.
        $this->sendJson('PUT', "{$base}/1", ['jenis' => '2'])->assertStatus(200);
        $this->seeInDatabase('uji_diklat', ['id_diklat' => 1, 'jenis' => 2, 'order' => 4]);
        $this->assertSame([4, 2, 3, 2, 3, 1, 1], $this->orders('uji_diklat', 'id_diklat', $ids));

        // Hapus: hanya jenis entri itu yang dirapatkan; pulihkan: akhir jenisnya.
        $this->delete("{$base}/2")->assertStatus(200);
        $this->assertSame([4, 2, 2, 1, 1], $this->orders('uji_diklat', 'id_diklat', ['1', '3', '4', '6', '7']));
        $this->assertSame([2, 3], $this->orders('uji_diklat', 'id_diklat', ['4', '5']));
        $this->sendJson('PATCH', "{$base}/2/status", ['status' => '1'])->assertStatus(200);
        $this->seeInDatabase('uji_diklat', ['id_diklat' => 2, 'order' => 3]);

        $list = $this->json($this->get($base))['data'];
        $this->assertSame(['7', '3', '2', '6', '4', '5', '1'], array_map('strval', array_column($list['items'], 'id_diklat')));
        $this->assertSame(['7', '3', '2', '6', '4', '5', '1'], $this->optionIds('uji-diklat'));
        $this->assertSame(['jenis'], $this->metaByKey()['uji-diklat']['order_scope']);
    }

    /**
     * Filter per jenis di daftar & dropdown, nama unik per jenis (uniqueScope), dan batas kapasitas urutan per jenis
     * (TINYINT): MAX+1 = 128 → 422.
     */
    public function testOrderScopeFilterUniquenessAndCapacity(): void
    {
        $base = 'api/v1/master/uji-diklat';

        $list = $this->json($this->get($base, ['jenis' => '2']))['data'];
        $this->assertSame([2, ['4', '5']], [$list['total'], array_map('strval', array_column($list['items'], 'id_diklat'))]);
        $this->assertSame(['4', '5'], $this->optionIds('uji-diklat', null, ['jenis' => '2']));
        $this->assertSame(['1', '2', '3'], $this->optionIds('uji-diklat', null, ['jenis' => '1']));

        $this->sendJson('POST', $base, ['diklat' => 'Teknis A', 'jenis' => '1'])->assertStatus(201);
        $result = $this->sendJson('POST', $base, ['diklat' => 'teknis a', 'jenis' => '2']);
        $result->assertStatus(422);
        $this->assertArrayHasKey('diklat', $this->json($result)['errors']);

        $this->db->table('uji_diklat')->insert(['id_diklat' => 50, 'jenis' => 3, 'diklat' => 'Fungsional Penuh', 'order' => 127, 'status' => 1]);
        $result = $this->sendJson('POST', $base, ['diklat' => 'Fungsional Baru', 'jenis' => '3']);
        $result->assertStatus(422);
        $this->assertSame(['Urutan Diklat Uji sudah mencapai batas maksimal 127.'], $this->json($result)['errors']['order']);
        // Jenis lain tidak terpengaruh.
        $this->sendJson('POST', $base, ['diklat' => 'Fungsional Baru', 'jenis' => '4'])->assertStatus(201);
    }

    // ------------------------------------------------------------------
    // Filter allowlist
    // ------------------------------------------------------------------

    /**
     * Filter `?kolom=nilai` hanya untuk kolom opsi `filters`; kolom lain diabaikan; nilai tidak sah → 422. Cache
     * dropdown dipisah per filter dan ikut di-invalidate saat master ditulis. Dropdown tetap UL_ALL.
     */
    public function testOptionsFiltersAreAllowlistedAndCachedPerFilter(): void
    {
        $this->assertSame(['3', '1', '2', '4'], $this->optionIds('uji-level'));
        $this->assertSame(['3'], $this->optionIds('uji-level', null, ['kategori' => '1']));
        $this->assertSame(['1', '2', '4'], $this->optionIds('uji-level', null, ['kategori' => '2']));

        // Kolom di luar allowlist (termasuk kolom nama) diabaikan.
        $this->assertSame(['3', '1', '2', '4'], $this->optionIds('uji-level', null, ['level' => 'I/a', 'order' => '1']));

        foreach ([['kategori' => '9'], ['kategori' => ['1']], ['kategori' => '1 OR 1=1']] as $query) {
            $result = $this->get('api/v1/master/uji-level/options', $query);
            $result->assertStatus(422);
            $this->assertSame(['Filter Kategori tidak valid.'], $this->json($result)['errors']['kategori']);
        }

        // Entri baru langsung muncul di dropdown terfilter yang sudah ter-cache.
        $created = $this->json($this->sendJson('POST', 'api/v1/master/uji-level', ['level' => 'CPNS I/b', 'kategori' => '1', 'order' => 2]))['data'];
        $this->assertSame(['3', (string) $created['id_level']], $this->optionIds('uji-level', null, ['kategori' => '1']));

        $list = $this->json($this->get('api/v1/master/uji-level', ['kategori' => '1']))['data'];
        $this->assertSame(['3', (string) $created['id_level']], array_map('strval', array_column($list['items'], 'id_level')));

        $this->asRole(Role::PEGAWAI);
        $this->assertSame(['3', (string) $created['id_level']], $this->optionIds('uji-level', null, ['kategori' => '1']));
        $this->assertSame(['kategori'], $this->metaByKey(Role::SUPER_ADMIN)['uji-level']['filters']);
    }

    // ------------------------------------------------------------------
    // uniqueFields
    // ------------------------------------------------------------------

    /**
     * uniqueFields: `singkat` unik global, `kode_lama` unik per bidang — case-insensitive, termasuk entri tidak aktif,
     * nilai sendiri saat ubah bukan bentrok, pindah lingkup diperiksa ulang, kosong/NULL tidak dibatasi.
     */
    public function testUniqueFieldsAreRejectedWith422OnTheirField(): void
    {
        $base = 'api/v1/master/uji-jurusan';

        $result = $this->sendJson('POST', $base, ['id_bidang' => '2', 'jurusan' => 'Manajemen', 'singkat' => 'ts', 'flag_s1' => '1']);
        $result->assertStatus(422);
        $this->assertSame(['Singkatan "ts" sudah dipakai Jurusan "Teknik Sipil" (kode 1).'], $this->json($result)['errors']['singkat']);

        // Entri tidak aktif ikut dicek, dengan saran mengaktifkan kembali.
        $result = $this->sendJson('POST', $base, ['id_bidang' => '1', 'jurusan' => 'Teknik Kimia', 'singkat' => 'AK', 'flag_s1' => '1']);
        $result->assertStatus(422);
        $this->assertStringContainsString('tidak aktif', $this->json($result)['errors']['singkat'][0]);

        // kode_lama per bidang: 101 dipakai di bidang 1, boleh di bidang 2.
        $manajemen = $this->json($this->sendJson('POST', $base, ['id_bidang' => '2', 'jurusan' => 'Manajemen', 'kode_lama' => '101', 'flag_s1' => '1']))['data'];
        $result    = $this->sendJson('POST', $base, ['id_bidang' => '1', 'jurusan' => 'Teknik Kimia', 'kode_lama' => '101', 'flag_s1' => '1']);
        $result->assertStatus(422);
        $this->assertSame(['Kode Lama "101" sudah dipakai Jurusan "Teknik Sipil" (kode 1).'], $this->json($result)['errors']['kode_lama']);

        // Ubah: nilai sendiri bukan bentrok; nilai entri lain ditolak; pindah bidang memeriksa ulang lingkup.
        $this->sendJson('PUT', "{$base}/2", ['singkat' => 'TM', 'kode_lama' => '102'])->assertStatus(200);
        $this->sendJson('PUT', "{$base}/2", ['singkat' => 'ts'])->assertStatus(422);
        $result = $this->sendJson('PUT', "{$base}/{$manajemen['id_jurusan']}", ['id_bidang' => '1']);
        $result->assertStatus(422);
        $this->assertArrayHasKey('kode_lama', $this->json($result)['errors']);
        $this->seeInDatabase('uji_jurusan', ['id_jurusan' => $manajemen['id_jurusan'], 'id_bidang' => 2]);

        // Kosong/NULL tidak dibatasi (UNIQUE mengizinkan banyak NULL).
        $this->sendJson('POST', $base, ['id_bidang' => '2', 'jurusan' => 'Tanpa Singkatan A', 'singkat' => '', 'flag_s1' => '0'])->assertStatus(201);
        $this->sendJson('POST', $base, ['id_bidang' => '2', 'jurusan' => 'Tanpa Singkatan B', 'flag_s1' => '0'])->assertStatus(201);
        $this->assertSame(2, $this->db->table('uji_jurusan')->where('singkat', null)->whereIn('jurusan', ['Tanpa Singkatan A', 'Tanpa Singkatan B'])->countAllResults());
    }

    /**
     * Balapan: permintaan lain meng-commit nilai yang sama SETELAH cek aplikasi lolos (disimulasikan hook lewat koneksi
     * DB kedua). UNIQUE index menolak tulisan (1062); tambah & ubah harus 422 pada field itu, bukan 500.
     */
    public function testUniqueFieldRaceIsTranslatedTo422(): void
    {
        $base = 'api/v1/master/uji-jurusan';

        UjiJurusanHooks::$concurrentRow = ['id_bidang' => 2, 'jurusan' => 'Pemenang Balapan', 'singkat' => 'BAL', 'flag_s1' => 1, 'order' => 9, 'status' => 1];
        $result                         = $this->sendJson('POST', $base, ['id_bidang' => '1', 'jurusan' => 'Kalah Balapan', 'singkat' => 'BAL', 'flag_s1' => '1']);
        $result->assertStatus(422);
        $this->assertSame(['Singkatan "BAL" sudah dipakai Jurusan "Pemenang Balapan" (kode 4).'], $this->json($result)['errors']['singkat']);
        $this->dontSeeInDatabase('uji_jurusan', ['jurusan' => 'Kalah Balapan']);

        UjiJurusanHooks::$concurrentRow = ['id_bidang' => 1, 'jurusan' => 'Pemenang Dua', 'kode_lama' => 777, 'flag_s1' => 1, 'order' => 9, 'status' => 1];
        $result                         = $this->sendJson('PUT', "{$base}/2", ['kode_lama' => '777']);
        $result->assertStatus(422);
        $this->assertArrayHasKey('kode_lama', $this->json($result)['errors']);
        $this->seeInDatabase('uji_jurusan', ['id_jurusan' => 2, 'kode_lama' => 102]);
        $this->assertTrue($this->db->transStatus());
    }

    // ------------------------------------------------------------------
    // Batas angka & boolean
    // ------------------------------------------------------------------

    /**
     * Field int dibatasi rentang tipe kolomnya (TINYINT 127, INT UNSIGNED 4.294.967.295, INT bawaan 2.147.483.647) dan
     * batas eksplisit (min) → 422 berpesan Indonesia; nilai tepat di batas diterima.
     */
    public function testIntegerFieldsAreBoundedByColumnType(): void
    {
        $base  = 'api/v1/master/uji-jurusan';
        $cases = [
            ['bobot', '128', 'Bobot maksimal 127.'],
            ['bobot', '-1', 'Bobot harus bilangan bulat tidak negatif.'],
            ['kuota', '0', 'Kuota minimal 1.'],
            ['kuota', '4294967296', 'Kuota maksimal 4.294.967.295.'],
            ['kode_lama', '2147483648', 'Kode Lama maksimal 2.147.483.647.'],
            ['kode_lama', '99999999999999999999', 'Kode Lama maksimal 2.147.483.647.'],
        ];

        foreach ($cases as $i => [$field, $value, $message]) {
            $result = $this->sendJson('POST', $base, ['id_bidang' => '2', 'jurusan' => "Uji Batas {$i}", 'flag_s1' => '1', $field => $value]);
            $result->assertStatus(422);
            $this->assertSame([$message], $this->json($result)['errors'][$field], "{$field}={$value}");
        }

        $this->dontSeeInDatabase('uji_jurusan', ['id_bidang' => 2, 'jurusan' => 'Uji Batas 0']);

        $this->sendJson('POST', $base, [
            'id_bidang' => '2', 'jurusan' => 'Tepat Batas', 'flag_s1' => '1', 'bobot' => '127', 'kuota' => '4294967295', 'kode_lama' => '2147483647',
        ])->assertStatus(201);
        $this->seeInDatabase('uji_jurusan', ['jurusan' => 'Tepat Batas', 'bobot' => 127, 'kuota' => 4294967295, 'kode_lama' => 2147483647]);

        $this->sendJson('PUT', "{$base}/1", ['bobot' => '200'])->assertStatus(422);

        $fields = array_column($this->metaByKey()['uji-jurusan']['fields'], null, 'name');
        $this->assertSame([0, 127], [$fields['bobot']['min'], $fields['bobot']['max']]);
        $this->assertSame([1, 4294967295], [$fields['kuota']['min'], $fields['kuota']['max']]);
        $this->assertSame([0, 2147483647], [$fields['kode_lama']['min'], $fields['kode_lama']['max']]);
    }

    /**
     * Field boolean: 1/0 (string atau angka) dan JSON true/false; tidak dikirim saat tambah = default DB 0; selain 1/0
     * → 422; boolean wajib tetap menerima false/'0'.
     */
    public function testBooleanFieldsAcceptOnlyOneOrZero(): void
    {
        $base = 'api/v1/master/uji-jurusan';

        $this->sendJson('POST', $base, ['id_bidang' => '2', 'jurusan' => 'Statistika', 'flag_d3' => true, 'flag_s1' => false])->assertStatus(201);
        $this->seeInDatabase('uji_jurusan', ['jurusan' => 'Statistika', 'flag_d3' => 1, 'flag_s1' => 0]);

        $this->sendJson('POST', $base, ['id_bidang' => '2', 'jurusan' => 'Aktuaria', 'flag_s1' => '1'])->assertStatus(201);
        $this->seeInDatabase('uji_jurusan', ['jurusan' => 'Aktuaria', 'flag_d3' => 0, 'flag_s1' => 1]);

        foreach (['2', 'ya', '-1'] as $value) {
            $result = $this->sendJson('POST', $base, ['id_bidang' => '2', 'jurusan' => 'Salah Flag', 'flag_d3' => $value, 'flag_s1' => '1']);
            $result->assertStatus(422);
            $this->assertSame(['Jenjang D-III hanya boleh 1 (ya) atau 0 (tidak).'], $this->json($result)['errors']['flag_d3'], $value);
        }

        $result = $this->sendJson('POST', $base, ['id_bidang' => '2', 'jurusan' => 'Tanpa S1']);
        $result->assertStatus(422);
        $this->assertSame(['Jenjang S-1 wajib diisi.'], $this->json($result)['errors']['flag_s1']);

        $this->sendJson('PUT', "{$base}/1", ['flag_d3' => '0'])->assertStatus(200);
        $this->seeInDatabase('uji_jurusan', ['id_jurusan' => 1, 'flag_d3' => 0]);
        $this->sendJson('PUT', "{$base}/1", ['flag_d3' => 1])->assertStatus(200);
        $this->seeInDatabase('uji_jurusan', ['id_jurusan' => 1, 'flag_d3' => 1]);

        $this->assertSame('boolean', array_column($this->metaByKey()['uji-jurusan']['fields'], null, 'name')['flag_d3']['type']);
    }

    // ------------------------------------------------------------------
    // statusChain
    // ------------------------------------------------------------------

    /**
     * statusChain: dropdown hanya memuat entri yang SELURUH rantai induknya aktif (pola U3 FAQ). Menonaktifkan/menghapus
     * leluhur langsung ter-reflect (cache dropdown turunan ikut di-invalidate), termasuk rantai 5 level di bawah
     * wilayah. Master tanpa statusChain tetap seperti semula.
     */
    public function testStatusChainHidesEntriesUnderInactiveAncestors(): void
    {
        $this->assertSame(['1', '2'], $this->optionIds('uji-jurusan'));
        $this->assertSame(['1'], $this->optionIds('uji-jurusan', null, ['flag_d3' => '1']));

        $this->sendJson('PATCH', 'api/v1/master/uji-bidang/1/status', ['status' => '2'])->assertStatus(200);
        $this->assertSame([], $this->optionIds('uji-jurusan'));
        $this->assertSame([], $this->optionIds('uji-jurusan', '1'));
        $this->assertSame([], $this->optionIds('uji-jurusan', null, ['flag_d3' => '1']));

        $this->sendJson('PATCH', 'api/v1/master/uji-bidang/1/status', ['status' => '1'])->assertStatus(200);
        $this->assertSame(['1', '2'], $this->optionIds('uji-jurusan'));
        $this->delete('api/v1/master/uji-bidang/1')->assertStatus(200);
        $this->assertSame([], $this->optionIds('uji-jurusan'));

        // Rantai 5 level: dusun → kelurahan → kecamatan → kabupaten/kota → provinsi.
        $this->assertSame(['1', '2'], $this->sortedOptionIds('uji-dusun'));
        $this->sendJson('PATCH', 'api/v1/master/provinsi/31/status', ['status' => '2'])->assertStatus(200);
        $this->assertSame(['2'], $this->sortedOptionIds('uji-dusun'));
        $this->sendJson('PATCH', 'api/v1/master/kecamatan/3273010/status', ['status' => '2'])->assertStatus(200);
        $this->assertSame([], $this->sortedOptionIds('uji-dusun'));

        // Master tanpa statusChain: kabupaten/kota provinsi non-aktif tetap ada di dropdown (perilaku lama).
        $this->assertSame(['3171', '3172'], $this->optionIds('kabupaten-kota', '31'));

        $this->sendJson('PATCH', 'api/v1/master/provinsi/31/status', ['status' => '1'])->assertStatus(200);
        $this->sendJson('PATCH', 'api/v1/master/kecamatan/3273010/status', ['status' => '1'])->assertStatus(200);
        $this->assertSame(['1', '2'], $this->sortedOptionIds('uji-dusun'));

        $meta = $this->metaByKey();
        $this->assertSame([true, false], [$meta['uji-jurusan']['status_chain'], $meta['kecamatan']['status_chain']]);
    }

    // ------------------------------------------------------------------
    // Field ref
    // ------------------------------------------------------------------

    /**
     * Field ref: kode wajib bentuk kanonik master rujukan, ada, aktif; dependsOn wajib konsisten (kabupaten/kota milik
     * provinsi yang dipilih). Ubah hanya memeriksa ref yang berubah (atau yang dependsOn-nya berubah).
     */
    public function testRefFieldsMustExistBeActiveAndFollowDependsOn(): void
    {
        $base = 'api/v1/master/uji-kantor';

        $this->sendJson('POST', $base, ['kantor' => 'Kantor Jakarta Utara', 'id_provinsi' => '31', 'id_kabupaten' => '3172'])->assertStatus(201);
        $this->seeInDatabase('uji_kantor', ['kantor' => 'Kantor Jakarta Utara', 'id_provinsi' => '31', 'id_kabupaten' => '3172']);

        $result = $this->sendJson('POST', $base, ['kantor' => 'Salah Rantai', 'id_provinsi' => '31', 'id_kabupaten' => '3273']);
        $result->assertStatus(422);
        $this->assertSame(['Kabupaten/Kota Kota Bandung tidak berada di bawah Provinsi yang dipilih.'], $this->json($result)['errors']['id_kabupaten']);

        foreach (['99', '3', '3A', '0x'] as $bad) {
            $result = $this->sendJson('POST', $base, ['kantor' => "Provinsi {$bad}", 'id_provinsi' => $bad]);
            $result->assertStatus(422);
            $this->assertSame(['Provinsi tidak ditemukan.'], $this->json($result)['errors']['id_provinsi'], $bad);
        }

        $result = $this->sendJson('POST', $base, ['kantor' => 'Terlalu Panjang', 'id_provinsi' => '310']);
        $result->assertStatus(422);
        $this->assertSame(['Provinsi tidak valid.'], $this->json($result)['errors']['id_provinsi']);

        $result = $this->sendJson('POST', $base, ['kantor' => 'Tanpa Provinsi', 'id_kabupaten' => '3171']);
        $result->assertStatus(422);
        $this->assertSame(['Pilih Provinsi terlebih dahulu.'], $this->json($result)['errors']['id_kabupaten']);

        // Opsional: boleh kosong; angka JSON diterima sebagai kode.
        $this->sendJson('POST', $base, ['kantor' => 'Tanpa Wilayah'])->assertStatus(201);
        $this->seeInDatabase('uji_kantor', ['kantor' => 'Tanpa Wilayah', 'id_provinsi' => null, 'id_kabupaten' => null]);
        $this->sendJson('POST', $base, ['kantor' => 'Kode Angka', 'id_provinsi' => 31])->assertStatus(201);
        $this->seeInDatabase('uji_kantor', ['kantor' => 'Kode Angka', 'id_provinsi' => '31']);

        // Rujukan non-aktif ditolak saat dipilih, tetapi data lama yang merujuknya tetap bisa diubah kolom lain.
        $this->sendJson('PATCH', 'api/v1/master/provinsi/32/status', ['status' => '2'])->assertStatus(200);
        $result = $this->sendJson('POST', $base, ['kantor' => 'Bandung Dua', 'id_provinsi' => '32']);
        $result->assertStatus(422);
        $this->assertSame(['Provinsi Jawa Barat sedang non-aktif.'], $this->json($result)['errors']['id_provinsi']);
        $this->sendJson('PUT', "{$base}/2", ['kantor' => 'Kantor Bandung Raya', 'id_provinsi' => '32'])->assertStatus(200);
        $this->sendJson('PATCH', 'api/v1/master/provinsi/32/status', ['status' => '1'])->assertStatus(200);

        // dependsOn berubah → ref yang tidak berubah ikut diperiksa rantainya.
        $result = $this->sendJson('PUT', "{$base}/1", ['id_provinsi' => '32']);
        $result->assertStatus(422);
        $this->assertArrayHasKey('id_kabupaten', $this->json($result)['errors']);
        $this->seeInDatabase('uji_kantor', ['id_kantor' => 1, 'id_provinsi' => '31']);
        $this->sendJson('PUT', "{$base}/1", ['id_provinsi' => '32', 'id_kabupaten' => '3273'])->assertStatus(200);
        $this->seeInDatabase('uji_kantor', ['id_kantor' => 1, 'id_provinsi' => '32', 'id_kabupaten' => '3273']);

        // Filter daftar per ref (allowlist).
        $list = $this->json($this->get($base, ['id_provinsi' => '32']))['data'];
        $this->assertSame(['1', '2'], array_map('strval', array_column($list['items'], 'id_kantor')));

        $fields = array_column($this->metaByKey()['uji-kantor']['fields'], null, 'name');
        $this->assertSame(['ref', 'kabupaten-kota', 'id_provinsi'], [$fields['id_kabupaten']['type'], $fields['id_kabupaten']['entity'], $fields['id_kabupaten']['depends_on']]);
        $this->assertSame(['provinsi', null], [$fields['id_provinsi']['entity'], $fields['id_provinsi']['depends_on']]);

        // Hanya dependsOn yang berubah: rantai ref yang tidak berubah diperiksa, statusnya tidak (data lama tidak konsisten
        // dengan kabupaten/kota yang kini non-aktif tetap bisa dirapikan provinsinya).
        $this->db->table('uji_kantor')->insert(['id_kantor' => 10, 'kantor' => 'Data Lama', 'id_provinsi' => '31', 'id_kabupaten' => '3273', 'order' => 9, 'status' => 1]);
        $this->sendJson('PATCH', 'api/v1/master/kabupaten-kota/3273/status', ['status' => '2'])->assertStatus(200);
        $this->sendJson('PUT', "{$base}/10", ['id_provinsi' => '32'])->assertStatus(200);
        $this->seeInDatabase('uji_kantor', ['id_kantor' => 10, 'id_provinsi' => '32', 'id_kabupaten' => '3273']);
        // Memilih kabupaten/kota non-aktif itu sebagai nilai baru tetap ditolak.
        $result = $this->sendJson('POST', $base, ['kantor' => 'Bandung Tiga', 'id_provinsi' => '32', 'id_kabupaten' => '3273']);
        $result->assertStatus(422);
        $this->assertSame(['Kabupaten/Kota Kota Bandung sedang non-aktif.'], $this->json($result)['errors']['id_kabupaten']);
    }

    // ------------------------------------------------------------------
    // Helper
    // ------------------------------------------------------------------

    /**
     * Data master UJI (MasterDataSeeder sudah mengisi wilayah & akun).
     */
    private function seedUji(): void
    {
        $this->db->table('uji_level')->insertBatch([
            ['id_level' => 1, 'level' => 'I/a', 'kategori' => 2, 'order' => 1, 'status' => 1],
            ['id_level' => 2, 'level' => 'I/b', 'kategori' => 2, 'order' => 2, 'status' => 1],
            ['id_level' => 3, 'level' => 'CPNS I/a', 'kategori' => 1, 'order' => 1, 'status' => 1],
            ['id_level' => 4, 'level' => 'I/c', 'kategori' => 2, 'order' => 5, 'status' => 1],
        ]);

        $this->db->table('uji_diklat')->insertBatch([
            ['id_diklat' => 1, 'jenis' => 1, 'diklat' => 'Diklatpim II', 'order' => 1, 'status' => 1],
            ['id_diklat' => 2, 'jenis' => 1, 'diklat' => 'Diklatpim III', 'order' => 2, 'status' => 1],
            ['id_diklat' => 3, 'jenis' => 1, 'diklat' => 'Diklatpim IV', 'order' => 3, 'status' => 1],
            ['id_diklat' => 4, 'jenis' => 2, 'diklat' => 'Teknis A', 'order' => 1, 'status' => 1],
            ['id_diklat' => 5, 'jenis' => 2, 'diklat' => 'Teknis B', 'order' => 2, 'status' => 1],
        ]);

        $this->db->table('uji_bidang')->insertBatch([
            ['id_bidang' => 1, 'bidang' => 'Teknik', 'order' => 1, 'status' => 1],
            ['id_bidang' => 2, 'bidang' => 'Ekonomi', 'order' => 2, 'status' => 1],
        ]);

        $this->db->table('uji_jurusan')->insertBatch([
            ['id_jurusan' => 1, 'id_bidang' => 1, 'jurusan' => 'Teknik Sipil', 'singkat' => 'TS', 'kode_lama' => 101, 'flag_d3' => 1, 'flag_s1' => 1, 'order' => 1, 'status' => 1],
            ['id_jurusan' => 2, 'id_bidang' => 1, 'jurusan' => 'Teknik Mesin', 'singkat' => 'TM', 'kode_lama' => 102, 'flag_d3' => 0, 'flag_s1' => 1, 'order' => 2, 'status' => 1],
            ['id_jurusan' => 3, 'id_bidang' => 2, 'jurusan' => 'Akuntansi', 'singkat' => 'AK', 'kode_lama' => 201, 'flag_d3' => 1, 'flag_s1' => 1, 'order' => 1, 'status' => 2],
        ]);

        $this->db->table('uji_kantor')->insertBatch([
            ['id_kantor' => 1, 'kantor' => 'Kantor Pusat', 'id_provinsi' => '31', 'id_kabupaten' => '3171', 'order' => 1, 'status' => 1],
            ['id_kantor' => 2, 'kantor' => 'Kantor Bandung', 'id_provinsi' => '32', 'id_kabupaten' => '3273', 'order' => 2, 'status' => 1],
        ]);

        $this->db->table('uji_dusun')->insertBatch([
            ['id_dusun' => 1, 'id_kelurahan' => '3171010001', 'dusun' => 'Dusun Satu', 'order' => 1, 'status' => 1],
            ['id_dusun' => 2, 'id_kelurahan' => '3273010001', 'dusun' => 'Dusun Dua', 'order' => 1, 'status' => 1],
        ]);
    }

    /**
     * @return list<string>
     */
    private function sortedOptionIds(string $entity): array
    {
        $ids = $this->optionIds($entity);
        sort($ids);

        return $ids;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function metaByKey(int $role = Role::SUPER_ADMIN): array
    {
        $this->asRole($role);

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
