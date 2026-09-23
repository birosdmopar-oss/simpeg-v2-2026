<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Libraries\CacheService;
use App\Libraries\MasterData\MasterDefinition;
use CodeIgniter\Test\TestResponse;
use Config\Services;

/**
 * Helper feature test Modul G. Dipakai bersama AuthTestTrait + DatabaseTestTrait + FeatureTestTrait.
 */
trait MasterDataTestTrait
{
    /**
     * Fixture per master (key Config\MasterData) untuk G-TC generik — WAJIB ada untuk SETIAP master terdaftar
     * (dijaga MasterGenericTcTest::testEveryRegisteredMasterHasFixture).
     *
     *   new       : payload entri baru yang valid (tanpa PK kalau master-nya AUTO_INCREMENT)
     *   duplicate : nama yang SUDAH ada di induk yang sama (seed MasterDataSeeder)
     *   existing  : kode entri seed (untuk update/status/delete/reorder)
     *   parent    : [field, id induk] atau null
     *
     * @return array<string, array{new: array<string, string>, duplicate: string, existing: string, parent: array{0: string, 1: string}|null}>
     */
    public static function masterFixtures(): array
    {
        return [
            // G-02
            'group-jabatan' => [
                'new'       => ['group_jabatan' => 'Jabatan Pelaksana', 'kode_group_jabatan' => 'PLK'],
                'duplicate' => 'struktural',
                'existing'  => '3',
                'parent'    => null,
            ],
            'sub-group-jabatan' => [
                'new'       => ['id_group_jabatan' => '1', 'sub_group_jabatan' => 'Eselon III', 'kode_sub_group_jabatan' => 'ES3'],
                'duplicate' => 'Eselon II',
                'existing'  => '2',
                'parent'    => ['id_group_jabatan', '1'],
            ],
            'unit' => [
                'new'       => ['unit' => 'Inspektorat', 'is_upt' => '0'],
                'duplicate' => 'Sekretariat Kementerian',
                'existing'  => '2',
                'parent'    => null,
            ],
            'satker' => [
                'new'       => ['id_unit' => '1', 'satker' => 'Biro Hukum', 'zonasi' => '0'],
                'duplicate' => 'Biro Keuangan',
                'existing'  => '2',
                'parent'    => ['id_unit', '1'],
            ],
            'jabatan' => [
                'new'       => ['id_sub_group_jabatan' => '1', 'jabatan' => 'Inspektur Utama', 'kelas_jabatan' => '16'],
                'duplicate' => 'Sekretaris Kementerian',
                'existing'  => '2',
                'parent'    => ['id_sub_group_jabatan', '1'],
            ],

            // G-03
            'lokasi-presensi' => [
                'new'       => ['id_lokasi_presensi' => 'L04', 'nama_lokasi' => 'Kantor Cabang Surabaya', 'latitude' => '-7.2575000', 'longitude' => '112.7521000', 'radius_meter' => '30'],
                'duplicate' => 'Kantor Pusat',
                'existing'  => 'L03',
                'parent'    => null,
            ],
            'pegawai-lokasi-presensi' => [
                'new'       => ['id_lokasi_presensi' => 'L01', 'nip' => '199308082019082007'],
                'duplicate' => '198501012010011001',
                'existing'  => '2',
                'parent'    => ['id_lokasi_presensi', 'L01'],
            ],

            // G-04
            'pangkat' => [
                'new'       => ['pangkat' => 'Penata Tingkat I', 'gol_ruang' => 'III/d', 'gol' => 'III', 'ruang' => 'd', 'cpns' => '2'],
                'duplicate' => 'Penata Muda',
                'existing'  => '3',
                'parent'    => null,
            ],
            'jenis-kp' => [
                'new'       => ['jenis_kp' => 'Anumerta'],
                'duplicate' => 'Reguler',
                'existing'  => '3',
                'parent'    => null,
            ],
            'gol-pppk' => [
                'new'       => ['id_gol_pppk' => 'XII', 'nama_gol_pppk' => 'Golongan XII'],
                'duplicate' => 'Golongan IX',
                'existing'  => 'XI',
                'parent'    => null,
            ],

            // G-05
            'jenjang-pendidikan' => [
                'new'       => ['jenjang_pendidikan' => 'Strata 3', 'jenjang_pendidikan_singkat' => 'S-3'],
                'duplicate' => 'Strata 1',
                'existing'  => '3',
                'parent'    => null,
            ],
            'bidang-pendidikan' => [
                'new'       => ['bidang_pendidikan' => 'Hukum'],
                'duplicate' => 'Ekonomi',
                'existing'  => '3',
                'parent'    => null,
            ],
            'jurusan-pendidikan' => [
                'new'       => ['id_bidang_pendidikan' => '1', 'jurusan_pendidikan' => 'Ekonomi Pembangunan', 'gelar' => 'S.E.'],
                'duplicate' => 'Manajemen',
                'existing'  => '2',
                'parent'    => ['id_bidang_pendidikan', '1'],
            ],

            // G-06
            'diklat' => [
                'new'       => ['nama_diklat' => 'Diklat Kearsipan', 'jenis_diklat' => '2'],
                'duplicate' => 'Diklat Kepemimpinan Tingkat III',
                'existing'  => '3',
                'parent'    => null,
            ],
            'tingkat-hukdis' => [
                'new'       => ['tingkat_hukdis' => 'Sangat Berat'],
                'duplicate' => 'Ringan',
                'existing'  => '3',
                'parent'    => null,
            ],
            'jenis-hukdis' => [
                'new'       => ['id_tingkat_hukdis' => '1', 'jenis_hukdis' => 'Pernyataan Tidak Puas Secara Tertulis'],
                'duplicate' => 'Teguran Tertulis',
                'existing'  => '2',
                'parent'    => ['id_tingkat_hukdis', '1'],
            ],
            'jenis-konket' => [
                'new'       => ['id_jenis_konket' => 'TB', 'nama_jenis_konket' => 'Tugas Belajar', 'affect_tukin' => '1'],
                'duplicate' => 'Work From Home',
                'existing'  => 'DL',
                'parent'    => null,
            ],
            'tanda-jasa' => [
                'new'       => ['tanda_jasa' => 'Bintang Jasa Utama'],
                'duplicate' => 'Satyalancana Karya Satya X Tahun',
                'existing'  => '3',
                'parent'    => null,
            ],

            // G-07
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
            'kantor' => [
                'new'       => ['id_kantor' => 'K04', 'nama_kantor' => 'Kantor Perwakilan Surabaya', 'id_provinsi' => '31'],
                'duplicate' => 'Kantor Balai Bandung',
                'existing'  => 'K03',
                'parent'    => null,
            ],
            'provinsi' => [
                'new'       => ['id_provinsi' => '33', 'provinsi' => 'Jawa Tengah'],
                'duplicate' => 'JAWA BARAT',
                'existing'  => '32',
                'parent'    => null,
            ],
            'kabupaten-kota' => [
                'new'       => ['id_kabupaten_kota' => '3173', 'id_provinsi' => '31', 'kabupaten_kota' => 'Jakarta Barat'],
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
                'new'       => ['id_kelurahan' => '3171010003', 'id_kecamatan' => '3171010', 'kelurahan' => 'Petojo Utara'],
                'duplicate' => 'Cideng',
                'existing'  => '3171010002',
                'parent'    => ['id_kecamatan', '3171010'],
            ],

            // G-08 (jenis libur; hari_libur diuji terpisah di HariLiburTest)
            'jenis-libur' => [
                'new'       => ['id_jenis_libur' => 'DRT', 'nama_jenis_libur' => 'Libur Darurat'],
                'duplicate' => 'Cuti Bersama',
                'existing'  => 'KHS',
                'parent'    => null,
            ],

            // G-10
            'faq-topic' => [
                'new'       => ['id_topic' => 'T04', 'nama_topic' => 'Pengembangan Kompetensi'],
                'duplicate' => 'Kepegawaian',
                'existing'  => 'T03',
                'parent'    => null,
            ],
            'faq-sub-topic' => [
                'new'       => ['id_sub_topic' => 'S04', 'id_topic' => 'T01', 'nama_sub_topic' => 'Tugas Belajar'],
                'duplicate' => 'Kenaikan Pangkat',
                'existing'  => 'S02',
                'parent'    => ['id_topic', 'T01'],
            ],
            'faq-article' => [
                'new'       => ['id_article' => 'A04', 'id_sub_topic' => 'S01', 'judul' => 'Apakah cuti bisa dibatalkan?', 'isi' => 'Cuti yang belum disetujui dapat dibatalkan lewat menu Layanan.'],
                'duplicate' => 'Berapa hak cuti tahunan saya?',
                'existing'  => 'A02',
                'parent'    => ['id_sub_topic', 'S01'],
            ],
        ];
    }

    /**
     * Kode alternatif yang pasti berbeda dari kode fixture (untuk uji nama duplikat).
     */
    public static function altCode(MasterDefinition $def, string $code): string
    {
        return 'Z' . substr($code, 1);
    }

    /**
     * Nama baru yang valid untuk uji update — menghormati panjang maksimum kolom nama. Untuk master yang
     * "nama"-nya berformat tetap (mis. NIP pemetaan lokasi presensi), digit terakhirnya yang diubah.
     */
    public static function renamedValue(MasterDefinition $def, string $name): string
    {
        $suffix = ' (ubah)';

        if (strlen($name . $suffix) <= $def->nameMaxLength) {
            return $name . $suffix;
        }

        return substr($name, 0, -1) . (string) (((int) substr($name, -1) + 1) % 10);
    }

    protected function resetMasterState(): void
    {
        foreach (['masterRegistry', 'masterService', 'hariLiburService', 'webConfigService', 'cacheService'] as $name) {
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
