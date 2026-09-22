<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Libraries\CacheService;
use CodeIgniter\Test\TestResponse;
use Config\Services;

/**
 * Helper feature test Modul G. Dipakai bersama AuthTestTrait + DatabaseTestTrait + FeatureTestTrait.
 */
trait MasterDataTestTrait
{
    /**
     * Fixture per master (key Config\MasterData) untuk G-TC generik.
     *   new       : payload entri baru yang valid
     *   duplicate : nama yang SUDAH ada di induk yang sama (seed MasterDataSeeder)
     *   existing  : kode entri seed (untuk update/status/delete)
     *   parent    : [field, id induk] atau null
     *
     * @return array<string, array{new: array<string, string>, duplicate: string, existing: string, parent: array{0: string, 1: string}|null}>
     */
    public static function masterFixtures(): array
    {
        return [
            'agama' => [
                'new'       => ['id_agama' => '7', 'nama_agama' => 'Kepercayaan'],
                'duplicate' => 'islam',
                'existing'  => '6',
                'parent'    => null,
            ],
            'jenis-pegawai' => [
                'new'       => ['id_jenis_pegawai' => 'CPNS', 'nama_jenis_pegawai' => 'Calon PNS'],
                'duplicate' => 'Pegawai Tidak Tetap',
                'existing'  => 'PTT',
                'parent'    => null,
            ],
            'jenis-status' => [
                'new'       => ['id_jenis_status' => 'CLTN', 'nama_jenis_status' => 'Cuti di Luar Tanggungan Negara'],
                'duplicate' => 'Pensiun',
                'existing'  => 'PSN',
                'parent'    => null,
            ],
            'provinsi' => [
                'new'       => ['id_provinsi' => '33', 'nama_provinsi' => 'Jawa Tengah'],
                'duplicate' => 'JAWA BARAT',
                'existing'  => '32',
                'parent'    => null,
            ],
            'kabupaten-kota' => [
                'new'       => ['id_kabupaten_kota' => '3173', 'id_provinsi' => '31', 'nama_kabupaten_kota' => 'Jakarta Barat'],
                'duplicate' => 'Jakarta Utara',
                'existing'  => '3172',
                'parent'    => ['id_provinsi', '31'],
            ],
            'kecamatan' => [
                'new'       => ['id_kecamatan' => '317103', 'id_kabupaten_kota' => '3171', 'nama_kecamatan' => 'Kemayoran'],
                'duplicate' => 'Sawah Besar',
                'existing'  => '317102',
                'parent'    => ['id_kabupaten_kota', '3171'],
            ],
            'kelurahan' => [
                'new'       => ['id_kelurahan' => '3171011003', 'id_kecamatan' => '317101', 'nama_kelurahan' => 'Petojo Utara'],
                'duplicate' => 'Cideng',
                'existing'  => '3171011002',
                'parent'    => ['id_kecamatan', '317101'],
            ],
        ];
    }

    protected function resetMasterState(): void
    {
        foreach (['masterRegistry', 'masterService', 'cacheService'] as $name) {
            Services::resetSingle($name);
        }

        // CIUnitTestCase memasang MockCache baru tiap test (tanpa prefix key), sedangkan CacheService shared bisa
        // masih memegang instance lama. Pasang CacheService segar di atas MockCache aktif dengan prefix yang sama
        // (''), supaya invalidasi dropdown diuji dengan semantik yang sama seperti driver file di runtime.
        Services::injectMock('cacheService', new CacheService(service('cache'), ''));
    }

    /**
     * @param array<string, mixed> $body
     */
    protected function sendJson(string $method, string $uri, array $body = []): TestResponse
    {
        return $this->withBodyFormat('json')->call($method, $uri, $body);
    }

    /**
     * Daftar id dari endpoint options (dropdown).
     *
     * @return list<string>
     */
    protected function optionIds(string $entity, ?string $parent = null): array
    {
        $result = $this->get("api/v1/master/{$entity}/options", $parent !== null ? ['parent' => $parent] : []);
        $result->assertStatus(200);

        return array_map(static fn (array $o): string => (string) $o['id'], $this->json($result)['data']);
    }
}
