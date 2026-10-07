<?php

declare(strict_types=1);

namespace Tests\MasterData;

use App\Constants\Role;
use CodeIgniter\I18n\Time;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\AuthTestTrait;
use Tests\Support\Database\Seeds\MasterDataSeeder;
use Tests\Support\DatabaseTestCase;
use Tests\Support\MasterDataTestTrait;

/**
 * DBV-003/CR-010 — master `kantor` (G-07) lewat engine + KantorHooks (keputusan F8): kode wilayah berjenjang yang
 * boleh LAIN-LAIN (baris sistem, field ber-allowSystem), rantai wilayah konsisten untuk kode non-sentinel, sentinel
 * berjenjang, isian `*_lain`, `kode_pos` 5 digit + harus salah satu `kelurahan.kd_pos` bila terisi (usulan #12), dan
 * nama kantor unik global. G-TC generik (keunikan, soft delete, toggle, urutan, audit, RBAC) ikut lewat fixture
 * `kantor` di MasterGenericTcTest/RbacMasterEndpointsTest.
 *
 * Seed: kantor 1 "Kantor Pusat" (31/3171/3171010/3171010001, kode pos 10110), kantor 2 "Kantor Perwakilan Luar
 * Negeri" (LAIN-LAIN di keempat level). Kelurahan 3171010001 kd_pos 10110, 3171010002 kd_pos 10150.
 *
 * @internal
 */
final class KantorTest extends DatabaseTestCase
{
    use FeatureTestTrait;
    use AuthTestTrait;
    use MasterDataTestTrait;

    protected $seed = MasterDataSeeder::class;

    private const BASE = 'api/v1/master/kantor';

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

    public function testCreateValidKantorStoresAuditAndNullsOtherColumns(): void
    {
        Time::setTestNow('2021-03-04 05:06:07', 'UTC');

        $result = $this->sendJson('POST', self::BASE, $this->payload('Kantor Cabang Cideng', [
            'id_kelurahan'  => '3171010002',
            'kode_pos'      => '10150',
            'provinsi_lain' => 'Diabaikan',
            'telp'          => '021-555',
            'remark'        => 'Catatan',
        ]));
        $result->assertStatus(201);
        $row = $this->json($result)['data'];

        $this->assertSame(['3', 3], [(string) $row['id_kantor'], (int) $row['order']], 'order kosong = MAX+1');
        $this->assertSame($this->adminId(), (int) $row['created_by']);
        $this->assertNull($row['updated_by']);
        $this->assertSame('2021-03-04 05:06:07', $row['created_at']);
        $this->seeInDatabase('kantor', [
            'id_kantor' => 3, 'id_kelurahan' => '3171010002', 'kode_pos' => '10150', 'provinsi_lain' => null, 'telp' => '021-555', 'status' => 1,
        ]);
        $this->seeInDatabase('audit_logs', ['entity' => 'kantor', 'entity_id' => '3', 'event' => 'create', 'nip_actor' => self::ADMIN_NIP]);

        // Alamat wajib; nama kosong/duplikat global (case-insensitive) ditolak.
        $result = $this->sendJson('POST', self::BASE, $this->payload('Kantor Tanpa Alamat', ['alamat' => '']));
        $result->assertStatus(422);
        $this->assertSame(['Alamat wajib diisi.'], $this->json($result)['errors']['alamat']);

        $result = $this->sendJson('POST', self::BASE, $this->payload('kantor cabang CIDENG', ['id_provinsi' => '32', 'id_kabupaten' => '3273', 'id_kecamatan' => '3273010', 'id_kelurahan' => '3273010001', 'kode_pos' => '40152']));
        $result->assertStatus(422);
        $this->assertArrayHasKey('nama_kantor', $this->json($result)['errors']);
        $this->assertSame(3, $this->db->table('kantor')->countAllResults());
    }

    /**
     * Rantai non-sentinel: kabupaten/kota milik provinsi terpilih, dst. → 422 di field level bawah.
     */
    public function testNonSentinelChainMustBeConsistent(): void
    {
        $cases = [
            'id_kabupaten' => [['id_provinsi' => '32'], 'Kabupaten/Kota Jakarta Pusat tidak berada di bawah Provinsi yang dipilih.'],
            'id_kecamatan' => [['id_kecamatan' => '3273010'], 'Kecamatan Sukasari tidak berada di bawah Kabupaten/Kota yang dipilih.'],
            'id_kelurahan' => [['id_kelurahan' => '3273010001'], 'Kelurahan/Desa Sukarasa tidak berada di bawah Kecamatan yang dipilih.'],
        ];

        foreach ($cases as $field => [$override, $message]) {
            $result = $this->sendJson('POST', self::BASE, $this->payload("Kantor Salah {$field}", $override));
            $result->assertStatus(422);
            $this->assertSame([$message], $this->json($result)['errors'][$field] ?? null, $field);
        }

        $this->assertSame(2, $this->db->table('kantor')->countAllResults());

        // Ubah satu level ke rantai lain tanpa menyesuaikan level di bawahnya juga ditolak.
        $result = $this->sendJson('PUT', self::BASE . '/1', ['id_provinsi' => '32']);
        $result->assertStatus(422);
        $this->assertArrayHasKey('id_kabupaten', $this->json($result)['errors']);
        $this->seeInDatabase('kantor', ['id_kantor' => 1, 'id_provinsi' => '31']);

        // Pindah seluruh rantai sekaligus boleh.
        $this->sendJson('PUT', self::BASE . '/1', [
            'id_provinsi' => '32', 'id_kabupaten' => '3273', 'id_kecamatan' => '3273010', 'id_kelurahan' => '3273010001', 'kode_pos' => '40152',
        ])->assertStatus(200);
        $this->seeInDatabase('kantor', ['id_kantor' => 1, 'id_kelurahan' => '3273010001', 'kode_pos' => '40152', 'updated_by' => $this->adminId()]);
    }

    /**
     * LAIN-LAIN berjenjang: level di bawah LAIN-LAIN wajib LAIN-LAIN; induk riil dengan anak LAIN-LAIN boleh (legacy
     * kantor/form.php:274). Isian *_lain wajib untuk level LAIN-LAIN dan dipaksa NULL untuk level riil.
     */
    public function testSentinelCascadeAndOtherColumns(): void
    {
        $result = $this->sendJson('POST', self::BASE, $this->payload('KBRI Salah', ['id_provinsi' => '99', 'provinsi_lain' => 'Jepang']));
        $result->assertStatus(422);
        $this->assertSame(['Kabupaten/Kota harus LAIN-LAIN bila Provinsi LAIN-LAIN.'], $this->json($result)['errors']['id_kabupaten']);

        $result = $this->sendJson('POST', self::BASE, $this->payload('Kecamatan Lain Salah', ['id_kecamatan' => '9999999', 'kecamatan_lain' => 'X']));
        $result->assertStatus(422);
        $this->assertSame(['Kelurahan/Desa harus LAIN-LAIN bila Kecamatan LAIN-LAIN.'], $this->json($result)['errors']['id_kelurahan']);

        // Induk riil + anak LAIN-LAIN: diterima, *_lain level riil dibuang, kode pos bebas 5 digit (kelurahan LAIN-LAIN).
        $result = $this->sendJson('POST', self::BASE, $this->payload('Kantor Pulau Seribu', [
            'id_kecamatan'   => '9999999', 'kecamatan_lain' => '  Kepulauan Seribu Utara ', 'id_kelurahan' => '9999999999',
            'kelurahan_lain' => 'Pulau Panggang', 'provinsi_lain' => 'Harus hilang', 'kode_pos' => '14530',
        ]));
        $result->assertStatus(201);
        $this->seeInDatabase('kantor', [
            'nama_kantor'    => 'Kantor Pulau Seribu', 'id_provinsi' => '31', 'provinsi_lain' => null, 'id_kecamatan' => '9999999',
            'kecamatan_lain' => 'Kepulauan Seribu Utara', 'kelurahan_lain' => 'Pulau Panggang', 'kode_pos' => '14530',
        ]);

        // *_lain wajib untuk setiap level LAIN-LAIN.
        $lainLain = $this->payload('KBRI Tokyo', [
            'id_provinsi'    => '99', 'provinsi_lain' => 'Jepang', 'id_kabupaten' => '9999', 'kabupaten_lain' => 'Tokyo',
            'id_kecamatan'   => '9999999', 'kecamatan_lain' => 'Minato', 'id_kelurahan' => '9999999999',
            'kelurahan_lain' => 'Akasaka', 'kode_pos' => '',
        ]);

        foreach (['provinsi_lain' => 'Provinsi Lainnya wajib diisi bila Provinsi LAIN-LAIN.', 'kelurahan_lain' => 'Kelurahan/Desa Lainnya wajib diisi bila Kelurahan/Desa LAIN-LAIN.'] as $field => $message) {
            $result = $this->sendJson('POST', self::BASE, [$field => '   '] + $lainLain);
            $result->assertStatus(422);
            $this->assertSame([$message], $this->json($result)['errors'][$field], $field);
        }

        $this->sendJson('POST', self::BASE, $lainLain)->assertStatus(201);
        $this->seeInDatabase('kantor', ['nama_kantor' => 'KBRI Tokyo', 'id_kelurahan' => '9999999999', 'kelurahan_lain' => 'Akasaka', 'kode_pos' => null]);

        // Ubah parsial kantor LAIN-LAIN tanpa mengirim ulang *_lain: nilai tersimpan dipakai (tidak dianggap kosong).
        $this->sendJson('PUT', self::BASE . '/2', ['kode_pos' => '12345'])->assertStatus(200);
        $this->seeInDatabase('kantor', [
            'id_kantor'      => 2, 'kode_pos' => '12345', 'provinsi_lain' => 'Jepang', 'kabupaten_lain' => 'Tokyo',
            'kecamatan_lain' => 'Shinagawa', 'kelurahan_lain' => 'Higashi-Gotanda',
        ]);
        $result = $this->sendJson('PUT', self::BASE . '/2', ['provinsi_lain' => '']);
        $result->assertStatus(422);
        $this->assertSame(['Provinsi Lainnya wajib diisi bila Provinsi LAIN-LAIN.'], $this->json($result)['errors']['provinsi_lain']);
        $this->seeInDatabase('kantor', ['id_kantor' => 2, 'provinsi_lain' => 'Jepang']);

        // Kantor LAIN-LAIN dipindah ke rantai riil: seluruh *_lain dikosongkan; kode pos bebas (12345) kini harus
        // salah satu kd_pos kelurahan riil (10150).
        $this->sendJson('PUT', self::BASE . '/2', [
            'id_provinsi' => '31', 'id_kabupaten' => '3171', 'id_kecamatan' => '3171020', 'id_kelurahan' => '3171010002', 'kode_pos' => '10150',
        ])->assertStatus(422);
        $result = $this->sendJson('PUT', self::BASE . '/2', [
            'id_provinsi' => '31', 'id_kabupaten' => '3171', 'id_kecamatan' => '3171010', 'id_kelurahan' => '3171010002',
        ]);
        $result->assertStatus(422);
        $this->assertSame(['Kode Pos harus salah satu kode pos kelurahan terpilih: 10150.'], $this->json($result)['errors']['kode_pos']);
        $this->sendJson('PUT', self::BASE . '/2', [
            'id_provinsi' => '31', 'id_kabupaten' => '3171', 'id_kecamatan' => '3171010', 'id_kelurahan' => '3171010002', 'kode_pos' => '10150',
        ])->assertStatus(200);
        $this->seeInDatabase('kantor', [
            'id_kantor'      => 2, 'id_kelurahan' => '3171010002', 'provinsi_lain' => null, 'kabupaten_lain' => null,
            'kecamatan_lain' => null, 'kelurahan_lain' => null, 'kode_pos' => '10150',
        ]);

        // Mengubah hanya *_lain dari level riil tetap dibuang.
        $this->sendJson('PUT', self::BASE . '/2', ['kabupaten_lain' => 'Tidak berlaku'])->assertStatus(200);
        $this->seeInDatabase('kantor', ['id_kantor' => 2, 'kabupaten_lain' => null]);
    }

    public function testKodePosMustBeFiveDigitsAndBelongToKelurahan(): void
    {
        foreach (['1011' => 'Kode Pos tidak sesuai format.', '1011a' => 'Kode Pos tidak sesuai format.', '101100' => 'Kode Pos maksimal 5 karakter.'] as $kodePos => $message) {
            $result = $this->sendJson('POST', self::BASE, $this->payload("Kantor {$kodePos}", ['kode_pos' => (string) $kodePos]));
            $result->assertStatus(422);
            $this->assertStringStartsWith($message, $this->json($result)['errors']['kode_pos'][0], (string) $kodePos);
        }

        // Kelurahan 3171010001 punya daftar kd_pos (10110) → kode pos lain ditolak, pesan menyebut daftarnya.
        $result = $this->sendJson('POST', self::BASE, $this->payload('Kantor Kode Pos Lain', ['kode_pos' => '10120']));
        $result->assertStatus(422);
        $this->assertSame(['Kode Pos harus salah satu kode pos kelurahan terpilih: 10110.'], $this->json($result)['errors']['kode_pos']);

        // Beberapa kode pos (dipisah koma): salah satunya diterima; kosong = NULL (opsional seperti legacy).
        $this->db->table('kelurahan')->where('id_kelurahan', '3171010001')->update(['kd_pos' => '10110,10120']);
        $this->sendJson('POST', self::BASE, $this->payload('Kantor Kode Pos Kedua', ['kode_pos' => '10120']))->assertStatus(201);
        $this->sendJson('POST', self::BASE, $this->payload('Kantor Tanpa Kode Pos', ['kode_pos' => '']))->assertStatus(201);
        $this->seeInDatabase('kantor', ['nama_kantor' => 'Kantor Tanpa Kode Pos', 'kode_pos' => null]);

        // Kelurahan tanpa daftar kd_pos → bebas 5 digit.
        $this->db->table('kelurahan')->insert(['id_kelurahan' => '3171010003', 'id_kecamatan' => '3171010', 'kelurahan' => 'Petojo Utara', 'order' => 3, 'status' => 1]);
        $this->sendJson('POST', self::BASE, $this->payload('Kantor Petojo', ['id_kelurahan' => '3171010003', 'kode_pos' => '12345']))->assertStatus(201);

        // Ubah kode pos saja juga diperiksa terhadap kelurahan tersimpan.
        $result = $this->sendJson('PUT', self::BASE . '/1', ['kode_pos' => '40152']);
        $result->assertStatus(422);
        $this->assertArrayHasKey('kode_pos', $this->json($result)['errors']);
        $this->seeInDatabase('kantor', ['id_kantor' => 1, 'kode_pos' => '10110']);

        // Ganti kelurahan saja: kode pos tersimpan (10110) diperiksa ulang terhadap daftar kelurahan baru (10150).
        $result = $this->sendJson('PUT', self::BASE . '/1', ['id_kelurahan' => '3171010002']);
        $result->assertStatus(422);
        $this->assertSame(['Kode Pos harus salah satu kode pos kelurahan terpilih: 10150.'], $this->json($result)['errors']['kode_pos']);
        $this->seeInDatabase('kantor', ['id_kantor' => 1, 'id_kelurahan' => '3171010001', 'kode_pos' => '10110']);
        $this->sendJson('PUT', self::BASE . '/1', ['id_kelurahan' => '3171010002', 'kode_pos' => '10150'])->assertStatus(200);
        $this->seeInDatabase('kantor', ['id_kantor' => 1, 'id_kelurahan' => '3171010002', 'kode_pos' => '10150']);
    }

    /**
     * Pola E6: data lama (mis. impor) dengan rantai tidak konsisten atau wilayah yang kini non-aktif tetap bisa diubah
     * kolom non-wilayahnya; aturan diperiksa ulang begitu kolom wilayah/kode pos ikut diubah.
     */
    public function testRulesAreRecheckedOnlyWhenWilayahColumnsChange(): void
    {
        $this->db->table('kantor')->insert([
            'id_kantor'   => 10, 'order' => 3, 'nama_kantor' => 'Kantor Impor Lama', 'alamat' => 'Jl. Lama',
            'id_provinsi' => '32', 'id_kabupaten' => '3171', 'id_kecamatan' => '3171010', 'id_kelurahan' => '3171010001',
            'kode_pos'    => '99999', 'provinsi_lain' => 'Sisa impor', 'status' => 1,
        ]);

        $this->sendJson('PUT', self::BASE . '/10', ['nama_kantor' => 'Kantor Impor Lama (rapi)', 'telp' => '021-1'])->assertStatus(200);
        $this->seeInDatabase('kantor', ['id_kantor' => 10, 'nama_kantor' => 'Kantor Impor Lama (rapi)', 'id_provinsi' => '32', 'provinsi_lain' => 'Sisa impor']);

        $result = $this->sendJson('PUT', self::BASE . '/10', ['kode_pos' => '10110']);
        $result->assertStatus(422);
        $this->assertArrayHasKey('id_kabupaten', $this->json($result)['errors']);

        $this->sendJson('PUT', self::BASE . '/10', ['id_provinsi' => '31', 'kode_pos' => '10110'])->assertStatus(200);
        $this->seeInDatabase('kantor', ['id_kantor' => 10, 'id_provinsi' => '31', 'kode_pos' => '10110', 'provinsi_lain' => null]);

        // Wilayah non-aktif: ditolak saat dipilih, tetapi kantor yang sudah merujuknya tetap bisa diubah.
        $this->sendJson('PATCH', 'api/v1/master/kelurahan/3171010001/status', ['status' => '2'])->assertStatus(200);
        $this->sendJson('PUT', self::BASE . '/1', ['alamat' => 'Jl. Medan Merdeka Barat No. 17-18', 'kode_pos' => '10110'])->assertStatus(200);

        $result = $this->sendJson('POST', self::BASE, $this->payload('Kantor Kelurahan Nonaktif'));
        $result->assertStatus(422);
        $this->assertSame(['Kelurahan/Desa Gambir sedang non-aktif.'], $this->json($result)['errors']['id_kelurahan']);
    }

    /**
     * Kode LAIN-LAIN hanya bisa dipilih lewat kolom wilayah kantor (allowSystem); dropdown, daftar admin wilayah, dan
     * nama kantor unik termasuk entri terhapus tetap seperti master lain. Options kantor UL_ALL.
     */
    public function testKantorDropdownMetaAndDeletedNames(): void
    {
        $this->delete(self::BASE . '/1')->assertStatus(200);

        $result = $this->sendJson('POST', self::BASE, $this->payload('KANTOR PUSAT'));
        $result->assertStatus(422);
        $this->assertStringContainsString('dihapus', $this->json($result)['errors']['nama_kantor'][0]);

        $this->asRole(Role::PEGAWAI);
        $this->assertSame(['2'], $this->optionIds('kantor'));

        $this->asRole(Role::SUPER_ADMIN);
        $meta   = array_column($this->json($this->get('api/v1/master/meta'))['data'], null, 'key');
        $fields = array_column($meta['kantor']['fields'], null, 'name');

        $this->assertSame(
            ['alamat', 'id_provinsi', 'provinsi_lain', 'id_kabupaten', 'kabupaten_lain', 'id_kecamatan', 'kecamatan_lain', 'id_kelurahan', 'kelurahan_lain', 'kode_pos', 'telp', 'faks', 'remark'],
            array_keys($fields),
        );
        $this->assertSame([true, 'kabupaten-kota', 'id_provinsi', true], [
            $fields['id_kabupaten']['allow_system'], $fields['id_kabupaten']['entity'], $fields['id_kabupaten']['depends_on'], $fields['id_kabupaten']['required'],
        ]);
        $this->assertSame('id_kelurahan', $fields['kelurahan_lain']['other_for']);
        $this->assertNull($fields['telp']['other_for']);

        $detail = $this->json($this->get(self::BASE . '/2'))['data'];
        $this->assertSame(['99', 'Jepang', '9999999999'], [$detail['id_provinsi'], $detail['provinsi_lain'], $detail['id_kelurahan']]);
    }

    /**
     * @param array<string, string> $override
     *
     * @return array<string, string>
     */
    private function payload(string $nama, array $override = []): array
    {
        return $override + [
            'nama_kantor'  => $nama,
            'alamat'       => 'Jl. Uji No. 1',
            'id_provinsi'  => '31',
            'id_kabupaten' => '3171',
            'id_kecamatan' => '3171010',
            'id_kelurahan' => '3171010001',
            'kode_pos'     => '10110',
        ];
    }

    private function adminId(): int
    {
        return (int) $this->db->table('pengguna')->select('id_pengguna')->where('nip', self::ADMIN_NIP)->get()->getRowArray()['id_pengguna'];
    }
}
