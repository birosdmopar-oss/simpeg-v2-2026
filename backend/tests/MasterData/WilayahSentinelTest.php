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
 * DBV-003/CR-010 — baris sistem (opsi `systemIds`) di engine master, dipakai sentinel LAIN-LAIN wilayah
 * (99/9999/9999999/9999999999, migration 2026-09-25-100200_SeedWilayahLainLain):
 *
 *  - tidak pernah tampil di options maupun daftar admin (semua filter), tetapi detail GET {kode} tetap bisa dibaca;
 *  - tidak bisa diubah, dinonaktifkan, diurutkan, maupun dihapus (422, tanpa tulis & audit);
 *  - tidak bisa menjadi induk entri baru / tujuan pindah induk;
 *  - tidak ikut penomoran urutan (`order` 0 tidak pernah disentuh saat saudara riil diurutkan/dihapus);
 *  - namanya tidak bisa dipakai entri riil (pesan menyebut baris sistem, bukan saran pulihkan).
 *
 * Rujukan dari master lain (field ref) ditolak kecuali field ber-allowSystem: MasterEngineFeaturesTest (uji-kantor,
 * "tidak ditemukan") dan KantorTest (kantor, diterima).
 *
 * @internal
 */
final class WilayahSentinelTest extends DatabaseTestCase
{
    use FeatureTestTrait;
    use AuthTestTrait;
    use MasterDataTestTrait;

    protected $seed = MasterDataSeeder::class;

    /**
     * Sentinel per master wilayah: key => [tabel, kolom PK, kode, kolom nama].
     */
    private const SENTINELS = [
        'provinsi'       => ['provinsi', 'id_provinsi', '99', 'provinsi'],
        'kabupaten-kota' => ['kabupaten_kota', 'id_kabupaten_kota', '9999', 'kabupaten_kota'],
        'kecamatan'      => ['kecamatan', 'id_kecamatan', '9999999', 'kecamatan'],
        'kelurahan'      => ['kelurahan', 'id_kelurahan', '9999999999', 'kelurahan'],
    ];

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

    public function testSystemRowsAreHiddenFromOptionsAndAdminListButReadableByCode(): void
    {
        // Dropdown berjenjang: sentinel tidak ada di level mana pun, juga saat induknya sentinel.
        $this->assertSame(['31', '32'], $this->optionIds('provinsi'));
        $this->assertSame([], $this->optionIds('kabupaten-kota', '99'));
        $this->assertSame([], $this->optionIds('kecamatan', '9999'));
        $this->assertSame([], $this->optionIds('kelurahan', '9999999'));

        foreach (self::SENTINELS as $entity => [$table, $pk, $code, $nameField]) {
            // Baris sentinel memang ada di DB (status 1) ...
            $this->seeInDatabase($table, [$pk => $code, $nameField => 'LAIN-LAIN', 'status' => 1, 'order' => 0]);

            // ... tetapi tidak di options tanpa induk, dan tidak di daftar admin dengan filter apa pun.
            $this->assertNotContains($code, $this->optionIds($entity), $entity);

            foreach ([[], ['status' => '1'], ['search' => 'lain'], ['search' => $code], ['per_page' => '100']] as $query) {
                $list = $this->json($this->get("api/v1/master/{$entity}", $query))['data'];
                $this->assertNotContains($code, array_map('strval', array_column($list['items'], $pk)), "{$entity} " . json_encode($query));
            }

            // Detail tetap bisa dibaca (mis. menampilkan nama kode LAIN-LAIN yang tersimpan di data lain).
            $detail = $this->get("api/v1/master/{$entity}/{$code}");
            $detail->assertStatus(200);
            $this->assertSame('LAIN-LAIN', $this->json($detail)['data'][$nameField], $entity);
        }

        // Total daftar hanya menghitung baris riil; filter induk sentinel kosong.
        $this->assertSame(2, $this->json($this->get('api/v1/master/provinsi'))['data']['total']);
        $this->assertSame(0, $this->json($this->get('api/v1/master/kabupaten-kota', ['parent' => '99']))['data']['total']);
        $this->assertSame(0, $this->json($this->get('api/v1/master/kelurahan', ['search' => 'LAIN-LAIN']))['data']['total']);

        // Meta mengirim kode sistem (FE menambahkan pilihan LAIN-LAIN untuk field ref ber-allow_system).
        $meta = array_column($this->json($this->get('api/v1/master/meta'))['data'], null, 'key');

        foreach (self::SENTINELS as $entity => [, , $code]) {
            $this->assertSame([$code], $meta[$entity]['system_ids'], $entity);
        }

        $this->assertSame([], $meta['agama']['system_ids']);
    }

    public function testSystemRowsCannotBeChangedDeactivatedReorderedOrDeleted(): void
    {
        foreach (self::SENTINELS as $entity => [$table, $pk, $code, $nameField]) {
            $before = $this->db->table($table)->where($pk, $code)->get()->getRowArray();

            $calls = [
                ['PUT', "api/v1/master/{$entity}/{$code}", [$nameField => 'Luar Negeri']],
                ['PUT', "api/v1/master/{$entity}/{$code}", ['status' => '2']],
                ['PATCH', "api/v1/master/{$entity}/{$code}/status", ['status' => '2']],
                ['PATCH', "api/v1/master/{$entity}/{$code}/order", ['order' => 1]],
                ['DELETE', "api/v1/master/{$entity}/{$code}", []],
            ];

            foreach ($calls as [$method, $uri, $body]) {
                $result = $this->sendJson($method, $uri, $body);
                $result->assertStatus(422);
                $this->assertStringContainsString('adalah baris sistem', $this->json($result)['message'], "{$method} {$uri}");
            }

            // Tidak ada yang tertulis (termasuk updated_at/updated_by) dan tidak ada audit.
            $this->assertSame($before, $this->db->table($table)->where($pk, $code)->get()->getRowArray(), $entity);
            $this->dontSeeInDatabase('audit_logs', ['entity' => $table, 'entity_id' => $code]);
        }
    }

    public function testSystemRowsCannotBecomeParents(): void
    {
        $cases = [
            ['kabupaten-kota', ['id_kabupaten_kota' => '9901', 'id_provinsi' => '99', 'kabupaten_kota' => 'Kota Luar Negeri'], 'id_provinsi', 'Provinsi LAIN-LAIN tidak bisa dipilih sebagai induk.'],
            ['kecamatan', ['id_kecamatan' => '9999001', 'id_kabupaten_kota' => '9999', 'kecamatan' => 'Distrik Luar'], 'id_kabupaten_kota', 'Kabupaten/Kota LAIN-LAIN tidak bisa dipilih sebagai induk.'],
            ['kelurahan', ['id_kelurahan' => '9999999001', 'id_kecamatan' => '9999999', 'kelurahan' => 'Desa Luar'], 'id_kecamatan', 'Kecamatan LAIN-LAIN tidak bisa dipilih sebagai induk.'],
        ];

        foreach ($cases as [$entity, $payload, $parentField, $message]) {
            $result = $this->sendJson('POST', "api/v1/master/{$entity}", $payload);
            $result->assertStatus(422);
            $this->assertSame([$message], $this->json($result)['errors'][$parentField], $entity);
        }

        $this->dontSeeInDatabase('kabupaten_kota', ['id_kabupaten_kota' => '9901']);
        $this->dontSeeInDatabase('kecamatan', ['id_kecamatan' => '9999001']);
        $this->dontSeeInDatabase('kelurahan', ['id_kelurahan' => '9999999001']);

        // Pindah induk entri riil ke sentinel juga ditolak.
        $result = $this->sendJson('PUT', 'api/v1/master/kabupaten-kota/3172', ['id_provinsi' => '99']);
        $result->assertStatus(422);
        $this->assertArrayHasKey('id_provinsi', $this->json($result)['errors']);
        $this->seeInDatabase('kabupaten_kota', ['id_kabupaten_kota' => '3172', 'id_provinsi' => '31', 'order' => 2]);

        // Kode sentinel tetap terpakai (tidak bisa dibuat ulang sebagai entri riil).
        $result = $this->sendJson('POST', 'api/v1/master/provinsi', ['id_provinsi' => '99', 'provinsi' => 'Provinsi Baru']);
        $result->assertStatus(422);
        $this->assertSame(['Kode 99 sudah dipakai.'], $this->json($result)['errors']['id_provinsi']);
    }

    /**
     * Sentinel `order` 0 di luar urutan tampil: menggeser, menambah, menghapus, dan memulihkan saudara riil (lingkup
     * tanpa induk = seluruh provinsi) tidak pernah menomori ulang / mengaudit baris sistem.
     */
    public function testOrderingNeverTouchesSystemRows(): void
    {
        $this->sendJson('PATCH', 'api/v1/master/provinsi/32/order', ['order' => 1])->assertStatus(200);
        $this->assertSame(['32', '31'], $this->optionIds('provinsi'));
        $this->assertSame([1, 2], $this->orders(['32', '31']));

        // Posisi di luar jangkauan dijepit ke jumlah baris riil (2), bukan 3.
        $this->sendJson('PATCH', 'api/v1/master/provinsi/32/order', ['order' => 99])->assertStatus(200);
        $this->assertSame([1, 2], $this->orders(['31', '32']));

        // Tambah tanpa order = MAX+1 baris riil; tambah dengan order menyisipkan tanpa menyentuh sentinel.
        $this->sendJson('POST', 'api/v1/master/provinsi', ['id_provinsi' => '33', 'provinsi' => 'Jawa Tengah'])->assertStatus(201);
        $this->sendJson('POST', 'api/v1/master/provinsi', ['id_provinsi' => '34', 'provinsi' => 'DI Yogyakarta', 'order' => 1])->assertStatus(201);
        $this->assertSame([1, 2, 3, 4], $this->orders(['34', '31', '32', '33']));

        // Hapus lalu pulihkan: urutan riil dirapatkan 1..n dan dipulihkan di akhir.
        $this->delete('api/v1/master/provinsi/34')->assertStatus(200);
        $this->assertSame([1, 2, 3], $this->orders(['31', '32', '33']));
        $this->sendJson('PATCH', 'api/v1/master/provinsi/34/status', ['status' => '1'])->assertStatus(200);
        $this->assertSame([4], $this->orders(['34']));

        $this->seeInDatabase('provinsi', ['id_provinsi' => '99', 'order' => 0, 'updated_at' => null, 'updated_by' => null]);
        $this->dontSeeInDatabase('audit_logs', ['entity' => 'provinsi', 'entity_id' => '99']);

        // Nilai `order` baris sistem (mis. hasil impor) tidak memengaruhi MAX+1 entri riil.
        $this->db->table('provinsi')->where('id_provinsi', '99')->update(['order' => 50]);
        $this->sendJson('POST', 'api/v1/master/provinsi', ['id_provinsi' => '35', 'provinsi' => 'Jawa Timur'])->assertStatus(201);
        $this->assertSame([5, 50], $this->orders(['35', '99']));
    }

    public function testSystemRowNameCannotBeReusedByRealEntries(): void
    {
        $result = $this->sendJson('POST', 'api/v1/master/provinsi', ['id_provinsi' => '33', 'provinsi' => 'Lain-lain']);
        $result->assertStatus(422);
        $this->assertSame(['Nama Provinsi "Lain-lain" sudah dipakai baris sistem LAIN-LAIN (kode 99).'], $this->json($result)['errors']['provinsi']);

        $this->sendJson('PUT', 'api/v1/master/provinsi/32', ['provinsi' => 'LAIN-LAIN'])->assertStatus(422);
        $this->seeInDatabase('provinsi', ['id_provinsi' => '32', 'provinsi' => 'Jawa Barat']);

        // Nama unik per induk: "Lain-lain" di bawah provinsi riil tidak bentrok dengan sentinel 9999 (induk 99).
        $this->sendJson('POST', 'api/v1/master/kabupaten-kota', ['id_kabupaten_kota' => '3175', 'id_provinsi' => '31', 'kabupaten_kota' => 'Lain-lain'])->assertStatus(201);
    }

    /**
     * @param list<string> $ids
     *
     * @return list<int>
     */
    private function orders(array $ids): array
    {
        return array_map(
            fn (string $id): int => (int) $this->db->table('provinsi')->where('id_provinsi', $id)->get()->getRowArray()['order'],
            $ids,
        );
    }
}
