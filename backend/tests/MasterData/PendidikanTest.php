<?php

declare(strict_types=1);

namespace Tests\MasterData;

use App\Constants\Role;
use App\Exceptions\ValidationException;
use App\Libraries\MasterData\JurusanPendidikanHooks;
use App\Libraries\MasterData\MasterDefinition;
use App\Libraries\MasterData\MasterService;
use CodeIgniter\I18n\Time;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use ReflectionMethod;
use Tests\Support\AuthTestTrait;
use Tests\Support\Database\Seeds\MasterDataSeeder;
use Tests\Support\MasterDataTestTrait;

/**
 * G-05 — Pendidikan (DBV-004/CR-011): `jenjang-pendidikan`, `bidang-pendidikan`, `jurusan-pendidikan` lewat engine
 * master generik.
 *
 *  - jenjang: singkatan unik → 422 pada field singkatan, termasuk balapan 1062 (P6, uniqueFields); `row_jurusan` hanya
 *    dari daftar tetap `D_I`..`S_3`, kosong = NULL (P6/D5); `bobot_ipasn` tidak pernah diekspos/diubah (P6).
 *  - jurusan: minimal satu flag jenjang (JurusanPendidikanHooks, legacy `Lm_pendidikan.php:584-587`; C2); nama unik per
 *    bidang (P7); dropdown mengikuti rantai status bidang → jurusan (statusChain, P7); induk wajib aktif (E6).
 *  - kolom audit pola D1 (tanpa `created_*`).
 *
 * CRUD generik, RBAC, dan audit log ketiga master ikut diuji MasterGenericTcTest & RbacMasterEndpointsTest (fixture
 * MasterDataTestTrait). Data seed: MasterDataSeeder::seedPendidikan().
 *
 * @internal
 */
final class PendidikanTest extends CIUnitTestCase
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

    private const JENJANG = 'api/v1/master/jenjang-pendidikan';
    private const BIDANG  = 'api/v1/master/bidang-pendidikan';
    private const JURUSAN = 'api/v1/master/jurusan-pendidikan';

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
    // jenjang_pendidikan
    // ------------------------------------------------------------------

    /**
     * P6: singkatan jenjang dibandingkan sebagai kode oleh modul lain → unik case-insensitive, termasuk terhadap entri
     * Tidak Aktif/Dihapus (dengan saran), 422 pada field singkatan (bukan nama). Balapan yang lolos cek aplikasi dan
     * ditolak `uq_jenjang_pendidikan_singkat` (1062) juga 422 pada field itu.
     */
    public function testJenjangSingkatanIsUniqueOnItsField(): void
    {
        $result = $this->sendJson('POST', self::JENJANG, ['jenjang_pendidikan' => 'Sarjana', 'jenjang_pendidikan_singkat' => 's.1']);
        $result->assertStatus(422);
        $errors = $this->json($result)['errors'];
        $this->assertSame(['Singkatan "s.1" sudah dipakai Jenjang Pendidikan "Strata 1" (kode 8).'], $errors['jenjang_pendidikan_singkat']);
        $this->assertArrayNotHasKey('jenjang_pendidikan', $errors);
        $this->dontSeeInDatabase('jenjang_pendidikan', ['jenjang_pendidikan' => 'Sarjana']);

        $this->sendJson('PUT', self::JENJANG . '/9', ['jenjang_pendidikan_singkat' => 'S.1'])->assertStatus(422);
        // Nilai sendiri (beda kapitalisasi) bukan bentrok.
        $this->sendJson('PUT', self::JENJANG . '/8', ['jenjang_pendidikan_singkat' => 's.1'])->assertStatus(200);
        $this->seeInDatabase('jenjang_pendidikan', ['id_jenjang_pendidikan' => 8, 'jenjang_pendidikan_singkat' => 's.1']);

        $this->sendJson('PATCH', self::JENJANG . '/9/status', ['status' => '2'])->assertStatus(200);
        $result = $this->sendJson('POST', self::JENJANG, ['jenjang_pendidikan' => 'Magister', 'jenjang_pendidikan_singkat' => 's.2']);
        $result->assertStatus(422);
        $this->assertStringContainsString('tidak aktif', $this->json($result)['errors']['jenjang_pendidikan_singkat'][0]);

        $this->delete(self::JENJANG . '/9')->assertStatus(200);
        $result = $this->sendJson('POST', self::JENJANG, ['jenjang_pendidikan' => 'Magister', 'jenjang_pendidikan_singkat' => 'S.2']);
        $result->assertStatus(422);
        $this->assertStringContainsString('sudah dihapus', $this->json($result)['errors']['jenjang_pendidikan_singkat'][0]);

        // Balapan: pemenang sudah meng-commit singkatan 'S.1'; INSERT yang kalah menabrak UNIQUE → 422 pada singkatan.
        $service = service('masterService');
        $def     = service('masterRegistry')->get('jenjang-pendidikan');
        $invoke  = static fn (string $method, mixed ...$args): mixed => (new ReflectionMethod(MasterService::class, $method))->invoke($service, ...$args);
        $row     = ['jenjang_pendidikan' => 'Sarjana', 'jenjang_pendidikan_singkat' => 'S.1', MasterDefinition::ORDER_FIELD => 6, MasterDefinition::STATUS_FIELD => '1'];

        try {
            $invoke(
                'translateDuplicate',
                static fn () => $invoke('transactional', static fn () => $invoke('model', $def)->insert($row)),
                static function () use ($invoke, $def, $row): void {
                    $invoke('assertNameUnique', $def, $row['jenjang_pendidikan'], null);
                    $invoke('assertFieldsUnique', $def, $row, null);
                },
            );
            $this->fail('Pelanggaran uq_jenjang_pendidikan_singkat harus diterjemahkan menjadi ValidationException (422).');
        } catch (ValidationException $e) {
            $this->assertSame(['jenjang_pendidikan_singkat'], array_keys((array) $e->getErrors()));
        }

        $this->assertSame(1, $this->db->table('jenjang_pendidikan')->where('jenjang_pendidikan_singkat', 'S.1')->countAllResults());
        $this->assertTrue($this->db->transStatus());
    }

    /**
     * Aturan field G-05 dari definisi (Config\MasterData): wajib & panjang maksimal per kolom (koneksi produksi tidak
     * strict, jadi teks kepanjangan akan terpotong diam-diam tanpa rule ini) dan kolom pencarian tambahan (extraSearch).
     */
    public function testPendidikanFieldRulesAndExtraSearch(): void
    {
        $cases = [
            [self::JENJANG, ['jenjang_pendidikan' => 'Jenjang Uji', 'jenjang_pendidikan_singkat' => ''], 'jenjang_pendidikan_singkat', 'Singkatan wajib diisi.'],
            [self::JENJANG, ['jenjang_pendidikan' => 'Jenjang Uji', 'jenjang_pendidikan_singkat' => str_repeat('a', 51)], 'jenjang_pendidikan_singkat', 'Singkatan maksimal 50 karakter.'],
            [self::BIDANG, ['bidang_pendidikan' => 'Bidang Uji', 'bidang_pendidikan_english' => str_repeat('a', 101)], 'bidang_pendidikan_english', 'Nama (Inggris) maksimal 100 karakter.'],
            [self::JURUSAN, ['id_bidang_pendidikan' => '1', 'jurusan_pendidikan' => 'Jurusan Uji', 'S_1' => '1', 'jurusan_pendidikan_english' => str_repeat('a', 256)], 'jurusan_pendidikan_english', 'Nama (Inggris) maksimal 255 karakter.'],
            [self::JURUSAN, ['id_bidang_pendidikan' => '1', 'jurusan_pendidikan' => 'Jurusan Uji', 'S_1' => '1', 'gelar' => str_repeat('a', 51)], 'gelar', 'Gelar maksimal 50 karakter.'],
            [self::BIDANG, ['bidang_pendidikan' => 'Bidang Uji', 'bidang_pendidikan_english' => []], 'bidang_pendidikan_english', 'Nama (Inggris) tidak valid.'],
            [self::JURUSAN, ['id_bidang_pendidikan' => '1', 'jurusan_pendidikan' => 'Jurusan Uji', 'S_1' => '1', 'gelar' => ['S.T.']], 'gelar', 'Gelar tidak valid.'],
        ];

        foreach ($cases as [$uri, $payload, $field, $message]) {
            $result = $this->sendJson('POST', $uri, $payload);
            $result->assertStatus(422);
            $this->assertSame([$message], $this->json($result)['errors'][$field], "{$uri} {$field}");
        }

        $this->dontSeeInDatabase('jenjang_pendidikan', ['jenjang_pendidikan' => 'Jenjang Uji']);
        $this->dontSeeInDatabase('bidang_pendidikan', ['bidang_pendidikan' => 'Bidang Uji']);
        $this->dontSeeInDatabase('jurusan_pendidikan', ['jurusan_pendidikan' => 'Jurusan Uji']);

        // Batas tepat muat; spasi saja di field opsional = NULL.
        $this->sendJson('POST', self::JURUSAN, ['id_bidang_pendidikan' => '1', 'jurusan_pendidikan' => 'Jurusan Uji', 'S_1' => '1', 'gelar' => str_repeat('a', 50), 'jurusan_pendidikan_english' => '  '])->assertStatus(201);
        $this->seeInDatabase('jurusan_pendidikan', ['jurusan_pendidikan' => 'Jurusan Uji', 'gelar' => str_repeat('a', 50), 'jurusan_pendidikan_english' => null]);

        // Pencarian daftar admin: singkatan jenjang dan nama Inggris bidang/jurusan (extraSearch).
        $this->sendJson('PUT', self::BIDANG . '/1', ['bidang_pendidikan_english' => 'Engineering'])->assertStatus(200);
        $this->sendJson('PUT', self::JURUSAN . '/3', ['jurusan_pendidikan_english' => 'Accountancy'])->assertStatus(200);

        foreach ([[self::JENJANG, 'SLTP', 'id_jenjang_pendidikan', ['2']], [self::BIDANG, 'Engineer', 'id_bidang_pendidikan', ['1']], [self::JURUSAN, 'Accountan', 'id_jurusan_pendidikan', ['3']]] as [$uri, $search, $pk, $ids]) {
            $items = $this->json($this->get($uri, ['search' => $search]))['data']['items'];
            $this->assertSame($ids, array_map('strval', array_column($items, $pk)), "{$uri}?search={$search}");
        }
    }

    /**
     * P6/D5: `row_jurusan` = nama kolom flag di jurusan_pendidikan, dipilih dari daftar tetap (peka huruf; ditolak 422
     * sebelum CHECK DB); tidak dikirim / kosong = NULL, dan bisa dikosongkan lagi lewat ubah.
     */
    public function testJenjangRowJurusanIsChosenFromFixedList(): void
    {
        $this->sendJson('POST', self::JENJANG, ['jenjang_pendidikan' => 'Diploma Tiga', 'jenjang_pendidikan_singkat' => 'D.III', 'row_jurusan' => 'D_III'])->assertStatus(201);
        $this->seeInDatabase('jenjang_pendidikan', ['jenjang_pendidikan_singkat' => 'D.III', 'row_jurusan' => 'D_III']);

        $this->sendJson('POST', self::JENJANG, ['jenjang_pendidikan' => 'Profesi Dokter', 'jenjang_pendidikan_singkat' => 'Dokter'])->assertStatus(201);
        $this->sendJson('POST', self::JENJANG, ['jenjang_pendidikan' => 'Profesi Apoteker', 'jenjang_pendidikan_singkat' => 'Apoteker', 'row_jurusan' => ''])->assertStatus(201);
        $this->seeInDatabase('jenjang_pendidikan', ['jenjang_pendidikan_singkat' => 'Dokter', 'row_jurusan' => null]);
        $this->seeInDatabase('jenjang_pendidikan', ['jenjang_pendidikan_singkat' => 'Apoteker', 'row_jurusan' => null]);

        foreach (['s_1', 'X', 'S1', 'S_4', 'S_1 OR 1=1'] as $i => $value) {
            $result = $this->sendJson('POST', self::JENJANG, ['jenjang_pendidikan' => "Jenjang Salah {$i}", 'jenjang_pendidikan_singkat' => "JS{$i}", 'row_jurusan' => $value]);
            $result->assertStatus(422);
            $this->assertSame(['Kolom Jurusan tidak valid.'], $this->json($result)['errors']['row_jurusan'], $value);
        }

        $this->dontSeeInDatabase('jenjang_pendidikan', ['jenjang_pendidikan_singkat' => 'JS0']);
        $this->sendJson('PUT', self::JENJANG . '/8', ['row_jurusan' => 's_1'])->assertStatus(422);

        $this->sendJson('PUT', self::JENJANG . '/8', ['row_jurusan' => ''])->assertStatus(200);
        $this->seeInDatabase('jenjang_pendidikan', ['id_jenjang_pendidikan' => 8, 'row_jurusan' => null]);
        $this->sendJson('PUT', self::JENJANG . '/8', ['row_jurusan' => 'S_1'])->assertStatus(200);
        $this->seeInDatabase('jenjang_pendidikan', ['id_jenjang_pendidikan' => 8, 'row_jurusan' => 'S_1']);

        // Nilai yang dianggap kosong oleh permit_empty (spasi, tab, false) disimpan NULL — bukan '' yang ditolak CHECK
        // (3819 → 500) — baik saat tambah maupun saat mengosongkan baris ber-'S_1'. Array/objek JSON → 422.
        foreach ([' ', "\t", false] as $i => $blank) {
            $this->sendJson('POST', self::JENJANG, ['jenjang_pendidikan' => "Jenjang Kosong {$i}", 'jenjang_pendidikan_singkat' => "JK{$i}", 'row_jurusan' => $blank])->assertStatus(201);
            $this->seeInDatabase('jenjang_pendidikan', ['jenjang_pendidikan_singkat' => "JK{$i}", 'row_jurusan' => null]);

            $this->sendJson('PUT', self::JENJANG . '/8', ['row_jurusan' => 'S_1'])->assertStatus(200);
            $this->sendJson('PUT', self::JENJANG . '/8', ['row_jurusan' => $blank])->assertStatus(200);
            $this->seeInDatabase('jenjang_pendidikan', ['id_jenjang_pendidikan' => 8, 'row_jurusan' => null]);
        }

        foreach ([[], ['S_1']] as $value) {
            $result = $this->sendJson('POST', self::JENJANG, ['jenjang_pendidikan' => 'Jenjang Array', 'jenjang_pendidikan_singkat' => 'JA', 'row_jurusan' => $value]);
            $result->assertStatus(422);
            $this->assertSame(['Kolom Jurusan tidak valid.'], $this->json($result)['errors']['row_jurusan']);
        }

        $this->dontSeeInDatabase('jenjang_pendidikan', ['jenjang_pendidikan_singkat' => 'JA']);

        $fields = array_column($this->metaByKey()['jenjang-pendidikan']['fields'], null, 'name');
        $this->assertSame(['select', false], [$fields['row_jurusan']['type'], $fields['row_jurusan']['required']]);
        $this->assertSame(JurusanPendidikanHooks::FLAGS, array_column($fields['row_jurusan']['options'], 'value'));
        $this->assertSame(['D.I', 'D.II', 'D.III', 'D.IV', 'S.1', 'S.2', 'S.3'], array_column($fields['row_jurusan']['options'], 'label'));
    }

    /**
     * P6: `bobot_ipasn` (skor kualifikasi IP ASN, `L_user.php:878-884`) tidak ada di daftar, detail, hasil
     * tambah/ubah/status/urutan/hapus, meta, maupun options; nilai yang dikirim diabaikan dan nilai DB tidak berubah.
     * Jenjang baru memakai default kolom DB (D13), tidak diisi aplikasi.
     */
    public function testJenjangBobotIpasnIsNeverExposedOrChanged(): void
    {
        $responses = [
            'list'   => $this->json($this->get(self::JENJANG))['data']['items'],
            'detail' => [$this->json($this->get(self::JENJANG . '/8'))['data']],
            'create' => [$this->json($this->sendJson('POST', self::JENJANG, ['jenjang_pendidikan' => 'Diploma Empat', 'jenjang_pendidikan_singkat' => 'D.IV', 'row_jurusan' => 'D_IV', 'bobot_ipasn' => 99]))['data']],
            'update' => [$this->json($this->sendJson('PUT', self::JENJANG . '/8', ['jenjang_pendidikan' => 'Strata Satu', 'bobot_ipasn' => 99]))['data']],
            'status' => [$this->json($this->sendJson('PATCH', self::JENJANG . '/8/status', ['status' => '2']))['data']],
            'order'  => [$this->json($this->sendJson('PATCH', self::JENJANG . '/8/order', ['order' => 1]))['data']],
            'delete' => [$this->json($this->delete(self::JENJANG . '/8'))['data']['item']],
        ];

        foreach ($responses as $case => $rows) {
            $this->assertNotSame([], $rows, $case);

            foreach ($rows as $row) {
                $this->assertArrayHasKey('jenjang_pendidikan_singkat', $row, $case);
                $this->assertArrayNotHasKey('bobot_ipasn', $row, $case);
            }
        }

        $this->seeInDatabase('jenjang_pendidikan', ['id_jenjang_pendidikan' => 8, 'jenjang_pendidikan' => 'Strata Satu', 'bobot_ipasn' => 20]);

        $newId = (string) $responses['create'][0]['id_jenjang_pendidikan'];
        $this->assertSame($this->columnDefault('jenjang_pendidikan', 'bobot_ipasn'), $this->db->table('jenjang_pendidikan')->where('id_jenjang_pendidikan', $newId)->get()->getRowArray()['bobot_ipasn']);

        $meta = $this->metaByKey()['jenjang-pendidikan'];
        $this->assertNotContains('bobot_ipasn', array_column($meta['fields'], 'name'));
        $this->assertStringNotContainsString('bobot_ipasn', (string) json_encode($meta));

        $this->assertSame(['id', 'nama', 'parent'], array_keys($this->json($this->get(self::JENJANG . '/options'))['data'][0]));
    }

    // ------------------------------------------------------------------
    // jurusan_pendidikan
    // ------------------------------------------------------------------

    /**
     * Legacy `Lm_pendidikan.php:584-587`: jurusan wajib tersedia untuk minimal satu jenjang. Diperiksa saat tambah dan
     * saat ubah yang menyentuh flag (C2): baris impor yang ketujuh flag-nya 0 tetap bisa diubah kolom lain.
     */
    public function testJurusanRequiresAtLeastOneFlag(): void
    {
        $base = ['id_bidang_pendidikan' => '1', 'jurusan_pendidikan' => 'Teknik Kimia'];
        $none = array_fill_keys(JurusanPendidikanHooks::FLAGS, '0');

        foreach (['tanpa flag' => $base, 'semua 0' => $base + $none, 'semua false' => $base + array_fill_keys(JurusanPendidikanHooks::FLAGS, false)] as $case => $payload) {
            $result = $this->sendJson('POST', self::JURUSAN, $payload);
            $result->assertStatus(422);
            $this->assertSame(['D_I' => ['Pilih minimal satu jenjang pendidikan.']], $this->json($result)['errors'], $case);
        }

        $this->dontSeeInDatabase('jurusan_pendidikan', ['jurusan_pendidikan' => 'Teknik Kimia']);

        $result = $this->sendJson('POST', self::JURUSAN, $base + ['S_1' => 'ya']);
        $result->assertStatus(422);
        $this->assertSame(['S.1 hanya boleh 1 (ya) atau 0 (tidak).'], $this->json($result)['errors']['S_1']);

        // JSON true diterima sebagai 1; flag yang tidak dikirim = 0.
        $this->sendJson('POST', self::JURUSAN, $base + ['D_IV' => true])->assertStatus(201);
        $this->seeInDatabase('jurusan_pendidikan', ['jurusan_pendidikan' => 'Teknik Kimia', 'D_IV' => 1, 'S_1' => 0, 'D_I' => 0]);

        // Ubah: mematikan salah satu dari dua flag boleh; mematikan satu-satunya flag ditolak.
        $this->sendJson('PUT', self::JURUSAN . '/1', ['D_III' => '0'])->assertStatus(200);
        $result = $this->sendJson('PUT', self::JURUSAN . '/1', ['S_1' => '0', 'jurusan_pendidikan' => 'Teknik Sipil Baru']);
        $result->assertStatus(422);
        $this->assertSame(['Pilih minimal satu jenjang pendidikan.'], $this->json($result)['errors']['D_I']);
        $this->seeInDatabase('jurusan_pendidikan', ['id_jurusan_pendidikan' => 1, 'jurusan_pendidikan' => 'Teknik Sipil', 'D_III' => 0, 'S_1' => 1]);

        // Tukar flag dalam satu ubah.
        $this->sendJson('PUT', self::JURUSAN . '/2', ['S_1' => '0', 'S_2' => '0', 'S_3' => '1'])->assertStatus(200);
        $this->seeInDatabase('jurusan_pendidikan', ['id_jurusan_pendidikan' => 2, 'S_1' => 0, 'S_2' => 0, 'S_3' => 1]);

        // C2: baris impor tanpa flag (hanya mungkin lewat SQL) tetap bisa diubah kolom lain, termasuk lewat form edit yang
        // mengirim ulang flag 0 yang tidak berubah; menyalakan flag memperbaikinya, mematikannya lagi ditolak.
        $this->db->table('jurusan_pendidikan')->insert(['id_jurusan_pendidikan' => 50, 'id_bidang_pendidikan' => 2, 'jurusan_pendidikan' => 'Impor Tanpa Jenjang', 'order' => 2, 'status' => 1]);
        $this->sendJson('PUT', self::JURUSAN . '/50', ['jurusan_pendidikan' => 'Impor Dirapikan', 'gelar' => 'S.E.'] + $none)->assertStatus(200);
        $this->sendJson('PUT', self::JURUSAN . '/50', ['status' => '2'])->assertStatus(200);
        $this->seeInDatabase('jurusan_pendidikan', ['id_jurusan_pendidikan' => 50, 'jurusan_pendidikan' => 'Impor Dirapikan', 'gelar' => 'S.E.', 'status' => 2, 'S_1' => 0]);
        $this->sendJson('PUT', self::JURUSAN . '/50', ['S_1' => '1'])->assertStatus(200);
        $this->sendJson('PUT', self::JURUSAN . '/50', ['S_1' => '0'])->assertStatus(422);
        $this->seeInDatabase('jurusan_pendidikan', ['id_jurusan_pendidikan' => 50, 'S_1' => 1]);

        // Dua ubah bersamaan: snapshot service (dibaca sebelum transaksi) masih S_1=1, S_2=1, padahal ubah lain sudah
        // meng-commit S_2=0. Hook membaca ulang flag dengan kunci baris, jadi mematikan S_1 ditolak (bukan tujuh flag 0).
        $stale = $this->db->table('jurusan_pendidikan')->where('id_jurusan_pendidikan', 1185)->get()->getRowArray();
        $this->db->table('jurusan_pendidikan')->where('id_jurusan_pendidikan', 1185)->update(array_fill_keys(JurusanPendidikanHooks::FLAGS, 0) + ['S_1' => 1]);

        try {
            (new JurusanPendidikanHooks())->beforeWrite(['S_1' => 0], $stale);
            $this->fail('Flag terkini (hanya S_1) harus dibaca dari DB, bukan dari snapshot.');
        } catch (ValidationException $e) {
            $this->assertSame(['D_I' => [JurusanPendidikanHooks::MESSAGE]], $e->getErrors());
        }

        $fields = array_column($this->metaByKey()['jurusan-pendidikan']['fields'], null, 'name');

        foreach (JurusanPendidikanHooks::FLAGS as $flag) {
            $this->assertSame('boolean', $fields[$flag]['type'], $flag);
        }
    }

    /**
     * P7: nama jurusan unik per bidang (UNIQUE(id_bidang_pendidikan, jurusan_pendidikan)), case-insensitive; pindah bidang
     * diperiksa ulang, lalu ditaruh di akhir urutan bidang baru dan bidang lama dirapatkan.
     */
    public function testJurusanNameIsUniquePerBidang(): void
    {
        $created = $this->sendJson('POST', self::JURUSAN, ['id_bidang_pendidikan' => '2', 'jurusan_pendidikan' => 'Teknik Sipil', 'S_1' => '1']);
        $created->assertStatus(201);
        $newId = (string) $this->json($created)['data']['id_jurusan_pendidikan'];
        $this->seeInDatabase('jurusan_pendidikan', ['id_jurusan_pendidikan' => $newId, 'order' => 2]);

        $result = $this->sendJson('POST', self::JURUSAN, ['id_bidang_pendidikan' => '1', 'jurusan_pendidikan' => 'TEKNIK SIPIL', 'S_1' => '1']);
        $result->assertStatus(422);
        $this->assertSame(['Jurusan Pendidikan "TEKNIK SIPIL" sudah ada dengan kode 1.'], $this->json($result)['errors']['jurusan_pendidikan']);

        $result = $this->sendJson('PUT', self::JURUSAN . "/{$newId}", ['id_bidang_pendidikan' => '1']);
        $result->assertStatus(422);
        $this->assertArrayHasKey('jurusan_pendidikan', $this->json($result)['errors']);
        $this->seeInDatabase('jurusan_pendidikan', ['id_jurusan_pendidikan' => $newId, 'id_bidang_pendidikan' => 2]);

        // Akuntansi (3) pindah ke Teknik: akhir urutan bidang 1 (3), bidang 2 dirapatkan.
        $this->sendJson('PUT', self::JURUSAN . '/3', ['id_bidang_pendidikan' => '1'])->assertStatus(200);
        $this->seeInDatabase('jurusan_pendidikan', ['id_jurusan_pendidikan' => 3, 'id_bidang_pendidikan' => 1, 'order' => 3]);
        $this->seeInDatabase('jurusan_pendidikan', ['id_jurusan_pendidikan' => $newId, 'order' => 1]);
        $this->assertSame(['2', '1', '3'], $this->optionIds('jurusan-pendidikan', '1'));
    }

    /**
     * P7 (pola U3 FAQ): dropdown jurusan — dengan maupun tanpa `?parent=` — hanya memuat jurusan aktif yang bidangnya
     * aktif. Menonaktifkan/menghapus bidang langsung ter-reflect di dropdown yang sudah ter-cache; status jurusan tidak
     * diubah; memulihkan bidang memunculkan jurusannya kembali. Memperbaiki bug legacy `Local.php:83, 87`.
     */
    public function testJurusanOptionsFollowBidangStatusChain(): void
    {
        $this->assertSame(['3'], $this->optionIds('jurusan-pendidikan', '2'));
        $this->assertContains('3', $this->optionIds('jurusan-pendidikan'));
        $this->assertSame(['2', '98', '1'], $this->optionIds('bidang-pendidikan'));

        foreach (['PATCH-2' => fn () => $this->sendJson('PATCH', self::BIDANG . '/2/status', ['status' => '2']), 'DELETE-10' => fn () => $this->delete(self::BIDANG . '/2')] as $case => $hide) {
            $hide()->assertStatus(200);

            $this->assertSame([], $this->optionIds('jurusan-pendidikan', '2'), $case);
            $this->assertNotContains('3', $this->optionIds('jurusan-pendidikan'), $case);
            $this->assertSame(['2', '1'], $this->optionIds('jurusan-pendidikan', '1'), $case);
            $this->seeInDatabase('jurusan_pendidikan', ['id_jurusan_pendidikan' => 3, 'status' => 1]);

            // Pegawai (form riwayat) melihat hal yang sama.
            $this->asRole(Role::PEGAWAI);
            $this->assertSame([], $this->optionIds('jurusan-pendidikan', '2'), $case);
            $this->asRole(Role::SUPER_ADMIN);

            $this->sendJson('PATCH', self::BIDANG . '/2/status', ['status' => '1'])->assertStatus(200);
            $this->assertSame(['3'], $this->optionIds('jurusan-pendidikan', '2'), "{$case}: dipulihkan");
            $this->assertContains('3', $this->optionIds('jurusan-pendidikan'), $case);
        }

        // Jurusan non-aktif hilang walau bidangnya aktif.
        $this->sendJson('PATCH', self::JURUSAN . '/3/status', ['status' => '2'])->assertStatus(200);
        $this->assertSame([], $this->optionIds('jurusan-pendidikan', '2'));

        $this->assertTrue($this->metaByKey()['jurusan-pendidikan']['status_chain']);
    }

    /**
     * E6: jurusan baru / pindah bidang hanya ke bidang yang ada dan aktif; ubah jurusan tanpa pindah bidang tetap boleh
     * walau bidangnya non-aktif (data lama tetap bisa dirapikan). Kode bidang wajib bentuk kanonik.
     */
    public function testJurusanCannotBeAddedOrMovedUnderInactiveBidang(): void
    {
        $this->sendJson('PATCH', self::BIDANG . '/2/status', ['status' => '2'])->assertStatus(200);

        $result = $this->sendJson('POST', self::JURUSAN, ['id_bidang_pendidikan' => '2', 'jurusan_pendidikan' => 'Manajemen', 'S_1' => '1']);
        $result->assertStatus(422);
        $this->assertSame(['Bidang Pendidikan Ekonomi sedang non-aktif.'], $this->json($result)['errors']['id_bidang_pendidikan']);
        $this->dontSeeInDatabase('jurusan_pendidikan', ['jurusan_pendidikan' => 'Manajemen']);

        $this->sendJson('PUT', self::JURUSAN . '/1', ['id_bidang_pendidikan' => '2'])->assertStatus(422);
        $this->seeInDatabase('jurusan_pendidikan', ['id_jurusan_pendidikan' => 1, 'id_bidang_pendidikan' => 1]);

        $this->sendJson('PUT', self::JURUSAN . '/3', ['jurusan_pendidikan' => 'Akuntansi Keuangan'])->assertStatus(200);
        $this->seeInDatabase('jurusan_pendidikan', ['id_jurusan_pendidikan' => 3, 'jurusan_pendidikan' => 'Akuntansi Keuangan']);

        foreach (['99', '01', '1a'] as $bidang) {
            $result = $this->sendJson('POST', self::JURUSAN, ['id_bidang_pendidikan' => $bidang, 'jurusan_pendidikan' => 'Manajemen', 'S_1' => '1']);
            $result->assertStatus(422);
            $this->assertSame(['Bidang Pendidikan tidak ditemukan.'], $this->json($result)['errors']['id_bidang_pendidikan'], $bidang);
        }
    }

    /**
     * `bidang_pendidikan` PK TINYINT [K] (produksi mulai ID 100, sisa 28 ID): setelah ID 127 terpakai, tambah → 422
     * dengan penjelasan (1062 PRIMARY dari counter yang habis), bukan 500 (G-doc 6.3 #16).
     */
    public function testBidangFullTinyintKeyGives422(): void
    {
        $this->db->table('bidang_pendidikan')->insert(['id_bidang_pendidikan' => 127, 'bidang_pendidikan' => 'Kesehatan', 'order' => 4, 'status' => 1]);

        $result = $this->sendJson('POST', self::BIDANG, ['bidang_pendidikan' => 'Baru Setelah 127']);
        $result->assertStatus(422);
        $this->assertSame(
            'Kode Bidang Pendidikan sudah mencapai batas maksimal tipe kolom, sehingga entri baru tidak bisa ditambahkan. Hubungi admin database.',
            $this->json($result)['message'],
        );
        $this->dontSeeInDatabase('bidang_pendidikan', ['bidang_pendidikan' => 'Baru Setelah 127']);

        // Nama ganda tetap dilaporkan sebagai nama ganda (cek aplikasi lebih dulu), bukan batas kode.
        $result = $this->sendJson('POST', self::BIDANG, ['bidang_pendidikan' => 'kesehatan']);
        $result->assertStatus(422);
        $this->assertArrayHasKey('bidang_pendidikan', $this->json($result)['errors']);
    }

    // ------------------------------------------------------------------
    // Kolom audit
    // ------------------------------------------------------------------

    /**
     * D1: ketiga tabel tanpa `created_*` — tambah & ubah mengisi `updated_at` (jam aplikasi, UTC) + `updated_by`
     * (id_pengguna aktor). Saudara yang hanya tergeser urutannya tidak di-stamp (kolom `updated_at` NOT NULL ON UPDATE
     * tidak ikut bergeser).
     */
    public function testAuditColumnsWithoutCreatedAt(): void
    {
        $adminId = $this->adminId();
        Time::setTestNow('2020-01-02 03:04:05', 'UTC');

        $created = [
            'jenjang-pendidikan' => $this->json($this->sendJson('POST', self::JENJANG, ['jenjang_pendidikan' => 'Diploma Satu', 'jenjang_pendidikan_singkat' => 'D.I']))['data'],
            'bidang-pendidikan'  => $this->json($this->sendJson('POST', self::BIDANG, ['bidang_pendidikan' => 'Kesehatan', 'bidang_pendidikan_english' => 'Health']))['data'],
            'jurusan-pendidikan' => $this->json($this->sendJson('POST', self::JURUSAN, ['id_bidang_pendidikan' => '1', 'jurusan_pendidikan' => 'Teknik Mesin', 'S_1' => '1']))['data'],
        ];

        foreach ($created as $entity => $row) {
            $this->assertArrayNotHasKey('created_at', $row, $entity);
            $this->assertArrayNotHasKey('created_by', $row, $entity);
            $this->assertSame('2020-01-02 03:04:05', $row['updated_at'], $entity);
            $this->assertSame($adminId, (int) $row['updated_by'], $entity);
        }

        $before = $this->db->table('jurusan_pendidikan')->where('id_jurusan_pendidikan', 2)->get()->getRowArray();

        Time::setTestNow('2020-02-03 04:05:06', 'UTC');
        $this->sendJson('PUT', self::BIDANG . '/1', ['bidang_pendidikan_english' => 'Engineering'])->assertStatus(200);
        $this->seeInDatabase('bidang_pendidikan', ['id_bidang_pendidikan' => 1, 'bidang_pendidikan_english' => 'Engineering', 'updated_at' => '2020-02-03 04:05:06', 'updated_by' => $adminId]);

        // Teknik Sipil (1) ke posisi 1: Teknik Elektro (2) hanya bergeser.
        $this->sendJson('PATCH', self::JURUSAN . '/1/order', ['order' => 1])->assertStatus(200);
        $this->seeInDatabase('jurusan_pendidikan', ['id_jurusan_pendidikan' => 1, 'order' => 1, 'updated_at' => '2020-02-03 04:05:06', 'updated_by' => $adminId]);
        $this->seeInDatabase('jurusan_pendidikan', ['id_jurusan_pendidikan' => 2, 'order' => 2, 'updated_at' => $before['updated_at'], 'updated_by' => null]);
    }

    // ------------------------------------------------------------------
    // Helper
    // ------------------------------------------------------------------

    /**
     * Default kolom menurut information_schema (DDL migration), sebagai string seperti nilai yang dibaca dari DB.
     */
    private function columnDefault(string $table, string $column): ?string
    {
        $row = $this->db->query(
            'SELECT COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$this->db->prefixTable($table), $column],
        )->getRowArray();

        $this->assertNotNull($row, "{$table}.{$column} tidak ditemukan");

        return $row['COLUMN_DEFAULT'] === null || strtoupper((string) $row['COLUMN_DEFAULT']) === 'NULL' ? null : (string) $row['COLUMN_DEFAULT'];
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
}
