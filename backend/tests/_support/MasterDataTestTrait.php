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
     *   new       : payload entri baru yang valid (tanpa kode untuk master AUTO_INCREMENT)
     *   duplicate : nama yang SUDAH ada di lingkup yang sama (induk / uniqueScope) di seed MasterDataSeeder
     *   existing  : kode entri seed (untuk update/status/delete)
     *   parent    : [field, id induk] atau null
     *
     * @return array<string, array{new: array<string, string>, duplicate: string, existing: string, parent: array{0: string, 1: string}|null}>
     */
    public static function masterFixtures(): array
    {
        return [
            'agama' => [
                'new'       => ['agama' => 'Kepercayaan'],
                'duplicate' => 'islam',
                'existing'  => '6',
                'parent'    => null,
            ],
            'jenis-pegawai' => [
                'new'       => ['jenis_pegawai' => 'Calon PNS'],
                'duplicate' => 'Pegawai Tidak Tetap',
                'existing'  => '3',
                'parent'    => null,
            ],
            'jenis-status' => [
                'new'       => ['jenis_status' => 'Cuti di Luar Tanggungan Negara', 'status_pegawai' => '2'],
                'duplicate' => 'Pensiun',
                'existing'  => '3',
                'parent'    => null,
            ],
            'provinsi' => [
                'new'       => ['id_provinsi' => '33', 'provinsi' => 'Jawa Tengah'],
                'duplicate' => 'JAWA BARAT',
                'existing'  => '32',
                'parent'    => null,
            ],
            'kabupaten-kota' => [
                'new'       => ['id_kabupaten_kota' => '3173', 'id_provinsi' => '31', 'kabupaten_kota' => 'Jakarta Barat', 'kd_area' => '021'],
                'duplicate' => 'Jakarta Utara',
                'existing'  => '3172',
                'parent'    => ['id_provinsi', '31'],
            ],
            'kecamatan' => [
                'new'       => ['id_kecamatan' => '3171030', 'id_kabupaten_kota' => '3171', 'kecamatan' => 'Kemayoran'],
                'duplicate' => 'Sawah Besar',
                'existing'  => '3171020',
                'parent'    => ['id_kabupaten_kota', '3171'],
            ],
            'kelurahan' => [
                'new'       => ['id_kelurahan' => '3171010003', 'id_kecamatan' => '3171010', 'kelurahan' => 'Petojo Utara', 'kd_pos' => '10130'],
                'duplicate' => 'Cideng',
                'existing'  => '3171010002',
                'parent'    => ['id_kecamatan', '3171010'],
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
