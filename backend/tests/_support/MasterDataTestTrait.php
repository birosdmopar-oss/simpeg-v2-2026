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
     *   update    : (opsional, CR-026) payload PUT pengganti "ubah nama" di test generik — untuk master yang namanya tidak
     *               bisa diubah (codeAsName, mis. kelas jabatan: nama = kode). Bawaan: nama + " (ubah)"
     *
     * @return array<string, array{new: array<string, string>, duplicate: string, existing: string, parent: array{0: string, 1: string}|null, update?: array<string, string>}>
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
            // G-10 FAQ (DBV-002). Entri `new` digantung di rantai yang TIDAK disentuh fixture `existing` level atasnya
            // (topik 1 / sub topik 1), karena test RBAC menghapus `existing` secara berurutan per master dan induk
            // wajib aktif di seluruh rantai (E6).
            'faq-topic' => [
                'new'       => ['faq_topic' => 'Cuti dan Izin'],
                'duplicate' => 'KEPEGAWAIAN',
                'existing'  => '3',
                'parent'    => null,
            ],
            'faq-sub-topic' => [
                'new'       => ['id_faq_topic' => '1', 'faq_sub_topic' => 'Akun Terkunci'],
                'duplicate' => 'profil akun',
                'existing'  => '2',
                'parent'    => ['id_faq_topic', '1'],
            ],
            'faq-article' => [
                'new' => [
                    'id_faq_sub_topic' => '1',
                    'title'            => 'Akun terkunci setelah salah kata sandi',
                    'content'          => '<p>Tunggu lima belas menit lalu coba masuk kembali.</p>',
                ],
                'duplicate' => 'SYARAT KATA SANDI BARU',
                'existing'  => '2',
                'parent'    => ['id_faq_sub_topic', '1'],
            ],

            // Blok per grup DBV (CR-009): urutan & isi mengikuti blok yang sama di Config\MasterData (urutan key fixture
            // = urutan master di meta, lihat RbacMasterEndpointsTest). Tambah fixture hanya di dalam blok grup sendiri.

            // --- DBV-003 ---
            // Kantor baru di rantai wilayah yang TIDAK disentuh fixture `existing` wilayah (32/3172/3171020/3171010002),
            // karena test RBAC menghapus `existing` berurutan dan kode wilayah kantor wajib aktif.
            'kantor' => [
                'new' => [
                    'nama_kantor'  => 'Kantor Wilayah Gambir',
                    'alamat'       => 'Jl. Medan Merdeka Barat No. 17',
                    'id_provinsi'  => '31',
                    'id_kabupaten' => '3171',
                    'id_kecamatan' => '3171010',
                    'id_kelurahan' => '3171010001',
                    'kode_pos'     => '10110',
                ],
                'duplicate' => 'KANTOR PUSAT',
                'existing'  => '1',
                'parent'    => null,
            ],
            'bidang-kursem' => [
                'new'       => ['bidang_kursem' => 'Kearsipan'],
                'duplicate' => 'manajemen',
                'existing'  => '2',
                'parent'    => null,
            ],
            'instansi-kursem' => [
                'new'       => ['instansi_kursem' => 'Arsip Nasional Republik Indonesia'],
                'duplicate' => 'LEMBAGA ADMINISTRASI NEGARA',
                'existing'  => '2',
                'parent'    => null,
            ],
            'jenis-libur' => [
                'new'       => ['jenis_libur' => 'Libur Daerah'],
                'duplicate' => 'libur nasional',
                'existing'  => '2',
                'parent'    => null,
            ],
            // --- /DBV-003 ---

            // --- DBV-004 ---
            // G-04/G-05 (DBV-004/CR-011), seed MasterDataSeeder::seedKenaikanPangkat()/seedPendidikan(). Nama `new`
            // pangkat & golongan PPPK pendek (kolom VARCHAR(10); test audit menambah " (ubah)"). `existing` bidang (2)
            // bukan induk `new`/`existing` jurusan (1), karena test RBAC menghapus `existing` berurutan per master dan
            // induk wajib aktif (E6). `new` jurusan wajib punya minimal satu flag (JurusanPendidikanHooks).
            'pangkat' => [
                'new'       => ['cpns' => '2', 'pangkat' => 'Juru Muda', 'gol' => 'I', 'ruang' => 'a', 'gol_ruang' => 'I/a'],
                'duplicate' => 'iii/a',
                'existing'  => '14',
                'parent'    => null,
            ],
            'jenis-kp' => [
                'new'       => ['jenis_kp' => 'Pilihan'],
                'duplicate' => 'REGULER',
                'existing'  => '5',
                'parent'    => null,
            ],
            'gol-pppk' => [
                'new'       => ['gol_pppk' => 'V', 'uang_makan' => '35000'],
                'duplicate' => 'ix',
                'existing'  => '9',
                'parent'    => null,
            ],
            'jenjang-pendidikan' => [
                'new'       => ['jenjang_pendidikan' => 'Strata 3', 'jenjang_pendidikan_singkat' => 'S.3', 'row_jurusan' => 'S_3'],
                'duplicate' => 'strata 1',
                'existing'  => '9',
                'parent'    => null,
            ],
            'bidang-pendidikan' => [
                'new'       => ['bidang_pendidikan' => 'Kesehatan'],
                'duplicate' => 'TEKNIK',
                'existing'  => '2',
                'parent'    => null,
            ],
            'jurusan-pendidikan' => [
                'new'       => ['id_bidang_pendidikan' => '1', 'jurusan_pendidikan' => 'Teknik Mesin', 'S_1' => '1'],
                'duplicate' => 'teknik sipil',
                'existing'  => '2',
                'parent'    => ['id_bidang_pendidikan', '1'],
            ],
            // --- /DBV-004 ---

            // --- DBV-005 ---
            // G-06 (seed MasterDataSeeder::seedG06). Nama `duplicate` diklat berada di lingkup jenis 1 (uniqueScope).
            'diklat' => [
                'new'       => ['jenis_diklat' => '1', 'nama_diklat' => 'Diklatpim Tingkat II'],
                'duplicate' => 'DIKLATPIM TINGKAT IV',
                'existing'  => '2',
                'parent'    => null,
            ],
            'tingkat-hukdis' => [
                'new'       => ['tingkat_hukdis' => 'Tingkat Uji'],
                'duplicate' => 'SEDANG',
                'existing'  => '3',
                'parent'    => null,
            ],
            // `new`/`existing` di tingkat 1: test RBAC menghapus `existing` tingkat (3) sebelum jenis diproses, dan induk
            // wajib aktif (E6).
            'jenis-hukdis' => [
                'new'       => ['id_tingkat_hukdis' => '1', 'jenis_hukdis' => 'Teguran Uji', 'masa_sanksi_bulan' => '3'],
                'duplicate' => 'teguran lisan',
                'existing'  => '2',
                'parent'    => ['id_tingkat_hukdis', '1'],
            ],
            // Cek nama jalan sebelum cek old_id (MasterService::create), jadi `duplicate` + old_id `new` tetap 422 pada nama.
            'jenis-konket' => [
                'new'       => ['old_id' => '20', 'jenis_konket' => 'Tugas Belajar Uji', 'affect_tukin' => '2'],
                'duplicate' => 'dinas',
                'existing'  => '6',
                'parent'    => null,
            ],
            'tanda-jasa' => [
                'new'       => ['tanda_jasa' => 'Satyalancana Wira Karya'],
                'duplicate' => 'lain-lain',
                'existing'  => '27',
                'parent'    => null,
            ],
            // --- /DBV-005 ---

            // --- DBV-008 ---
            // G-02 (seed MasterDataSeeder::seedG02). Test RBAC memproses master berurutan dan menghapus `existing` tiap
            // master sebelum master berikutnya, dan induk/rujukan wajib aktif (E6): `new` satker/sub group/jabatan memakai
            // unit 1, group 1, sub group 1, satker 1, kelas 13 — bukan `existing` (unit 2, satker 2, group 4, sub group 2,
            // kelas 9). Nama `duplicate` berada di lingkup yang sama dengan `new` (unit 1 / group 1 / sub group 1 + satker 1).
            'unit' => [
                'new'       => ['unit' => 'Deputi Bidang Uji', 'is_upt' => '0'],
                'duplicate' => 'sekretariat kementerian',
                'existing'  => '2',
                'parent'    => null,
            ],
            'satker' => [
                'new'       => ['id_unit' => '1', 'satker' => 'Biro Uji', 'zonasi' => '0'],
                'duplicate' => 'BIRO UMUM',
                'existing'  => '2',
                'parent'    => ['id_unit', '1'],
            ],
            'group-jabatan' => [
                'new'       => ['group_jabatan' => 'Jabatan Uji'],
                'duplicate' => 'struktural',
                'existing'  => '4',
                'parent'    => null,
            ],
            'sub-group-jabatan' => [
                'new'       => ['id_group_jabatan' => '1', 'sub_group_jabatan' => 'Pengawas', 'need_satker' => '1'],
                'duplicate' => 'ADMINISTRATOR',
                'existing'  => '2',
                'parent'    => ['id_group_jabatan', '1'],
            ],
            // codeAsName: kode = nama, jadi `duplicate` = nomor kelas yang sudah ada dan ubah memakai `update` (tukin).
            'kelas-jabatan' => [
                'new'       => ['kelas_jabatan' => '15', 'tukin' => '17064000'],
                'duplicate' => '7',
                'existing'  => '9',
                'parent'    => null,
                'update'    => ['tukin' => '17100000'],
            ],
            'jabatan' => [
                'new' => [
                    'id_group_jabatan' => '1', 'id_sub_group_jabatan' => '1', 'id_satker' => '1', 'kelas_jabatan' => '13',
                    'jabatan'          => 'Kepala Biro Uji',
                ],
                'duplicate' => 'KEPALA BIRO SUMBER DAYA MANUSIA',
                'existing'  => '2',
                'parent'    => null,
            ],
            // --- /DBV-008 ---

            // --- DBV-018 ---
            // G-02 sisa (seed MasterDataSeeder::seedG02b). Test RBAC menghapus `existing` tiap master sebelum master
            // berikutnya: `new` sub rumpun memakai rumpun 1, bukan `existing` rumpun 2. Periode = tahun (hook), jadi
            // ubah memakai fixture `update`.
            'rumpun-jabatan' => [
                'new'       => ['rumpun_jabatan' => 'Kesehatan'],
                'duplicate' => 'MANAJEMEN',
                'existing'  => '2',
                'parent'    => null,
            ],
            'subrumpun-jabatan' => [
                'new'       => ['id_rumpun_jabatan' => '1', 'subrumpun_jabatan' => 'Manajemen Aset'],
                'duplicate' => 'manajemen sumber daya manusia',
                'existing'  => '2',
                'parent'    => ['id_rumpun_jabatan', '1'],
            ],
            'jabatan-akademik' => [
                'new'       => ['jabatan_akademik' => 'Lektor Kepala', 'is_atasan' => '2'],
                'duplicate' => 'LEKTOR',
                'existing'  => '2',
                'parent'    => null,
            ],
            'periode-struktur-jabatan' => [
                'new'       => ['periode_struktur_jabatan' => '2027'],
                'duplicate' => '2021',
                'existing'  => '2',
                'parent'    => null,
                'update'    => ['periode_struktur_jabatan' => '2030'],
            ],
            // --- /DBV-018 ---
        ];
    }

    protected function resetMasterState(): void
    {
        foreach (['masterRegistry', 'masterService', 'cacheService', 'faqService', 'htmlSanitizer'] as $name) {
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
     * Daftar id dari endpoint options (dropdown), opsional dengan filter kolom allowlist (CR-009).
     *
     * @param array<string, mixed> $filters
     *
     * @return list<string>
     */
    protected function optionIds(string $entity, ?string $parent = null, array $filters = []): array
    {
        $result = $this->get("api/v1/master/{$entity}/options", ($parent !== null ? ['parent' => $parent] : []) + $filters);
        $result->assertStatus(200);

        return array_map(static fn (array $o): string => (string) $o['id'], $this->json($result)['data']);
    }
}
