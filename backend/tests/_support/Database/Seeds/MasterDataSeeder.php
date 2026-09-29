<?php

declare(strict_types=1);

namespace Tests\Support\Database\Seeds;

use CodeIgniter\Database\Seeder;

/**
 * Data master uji Modul G (+ akun AuthSeeder untuk token per role).
 * Format mengikuti skema legacy (DBV-001): kode wilayah tepat 2/4/7/10 digit tanpa titik, PK agama/jenis_* angka
 * AUTO_INCREMENT (di sini diisi eksplisit agar test bisa merujuk id), status 1 Aktif / 2 Tidak Aktif / 10 Dihapus.
 * Ditambah 1 cabang wilayah kedua untuk uji keunikan per induk dan dropdown berjenjang.
 */
class MasterDataSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(AuthSeeder::class);

        $this->db->table('agama')->insertBatch([
            ['id_agama' => 1, 'agama' => 'Islam', 'order' => 1, 'status' => 1],
            ['id_agama' => 2, 'agama' => 'Kristen Protestan', 'order' => 2, 'status' => 1],
            ['id_agama' => 3, 'agama' => 'Katolik', 'order' => 3, 'status' => 1],
            ['id_agama' => 4, 'agama' => 'Hindu', 'order' => 4, 'status' => 1],
            ['id_agama' => 5, 'agama' => 'Buddha', 'order' => 5, 'status' => 1],
            ['id_agama' => 6, 'agama' => 'Konghucu', 'order' => 6, 'status' => 1],
        ]);

        $this->db->table('jenis_pegawai')->insertBatch([
            ['id_jenis_pegawai' => 1, 'jenis_pegawai' => 'Pegawai Negeri Sipil', 'order' => 1, 'status' => 1],
            ['id_jenis_pegawai' => 2, 'jenis_pegawai' => 'Pegawai Pemerintah dengan Perjanjian Kerja', 'order' => 2, 'status' => 1],
            ['id_jenis_pegawai' => 3, 'jenis_pegawai' => 'Pegawai Tidak Tetap', 'order' => 3, 'status' => 1],
        ]);

        $this->db->table('jenis_status')->insertBatch([
            ['id_jenis_status' => 1, 'jenis_status' => 'Aktif', 'status_pegawai' => 1, 'order' => 1, 'status' => 1],
            ['id_jenis_status' => 2, 'jenis_status' => 'Tugas Belajar', 'status_pegawai' => 1, 'order' => 2, 'status' => 1],
            ['id_jenis_status' => 3, 'jenis_status' => 'Pensiun', 'status_pegawai' => 2, 'order' => 3, 'status' => 1],
        ]);

        $this->db->table('provinsi')->insertBatch([
            ['id_provinsi' => '31', 'provinsi' => 'DKI Jakarta', 'order' => 1, 'status' => 1],
            ['id_provinsi' => '32', 'provinsi' => 'Jawa Barat', 'order' => 2, 'status' => 1],
        ]);

        $this->db->table('kabupaten_kota')->insertBatch([
            ['id_kabupaten_kota' => '3171', 'id_provinsi' => '31', 'kabupaten_kota' => 'Jakarta Pusat', 'kd_area' => '021', 'order' => 1, 'status' => 1],
            ['id_kabupaten_kota' => '3172', 'id_provinsi' => '31', 'kabupaten_kota' => 'Jakarta Utara', 'kd_area' => '021', 'order' => 2, 'status' => 1],
            ['id_kabupaten_kota' => '3273', 'id_provinsi' => '32', 'kabupaten_kota' => 'Kota Bandung', 'kd_area' => '022', 'order' => 1, 'status' => 1],
        ]);

        $this->db->table('kecamatan')->insertBatch([
            ['id_kecamatan' => '3171010', 'id_kabupaten_kota' => '3171', 'kecamatan' => 'Gambir', 'order' => 1, 'status' => 1],
            ['id_kecamatan' => '3171020', 'id_kabupaten_kota' => '3171', 'kecamatan' => 'Sawah Besar', 'order' => 2, 'status' => 1],
            ['id_kecamatan' => '3273010', 'id_kabupaten_kota' => '3273', 'kecamatan' => 'Sukasari', 'order' => 1, 'status' => 1],
        ]);

        $this->db->table('kelurahan')->insertBatch([
            ['id_kelurahan' => '3171010001', 'id_kecamatan' => '3171010', 'kelurahan' => 'Gambir', 'kd_pos' => '10110', 'order' => 1, 'status' => 1],
            ['id_kelurahan' => '3171010002', 'id_kecamatan' => '3171010', 'kelurahan' => 'Cideng', 'kd_pos' => '10150', 'order' => 2, 'status' => 1],
            ['id_kelurahan' => '3273010001', 'id_kecamatan' => '3273010', 'kelurahan' => 'Sukarasa', 'kd_pos' => '40152', 'order' => 1, 'status' => 1],
        ]);

        $this->seedFaq();

        // Blok per grup DBV (CR-009): panggil method seed grup hanya di dalam blok grup sendiri (method-nya di blok grup
        // yang sama di akhir kelas), agar cabang DBV-003/004/005 yang paralel tidak saling konflik.

        // --- DBV-003 ---
        $this->seedDbv003();
        // --- /DBV-003 ---

        // --- DBV-004 ---
        $this->seedKenaikanPangkat();
        $this->seedPendidikan();
        // --- /DBV-004 ---

        // --- DBV-005 ---
        // --- /DBV-005 ---
    }

    /**
     * G-10 FAQ (DBV-002): 3 topik, 5 sub topik (sub topik 5 tanpa artikel), 5 artikel HTML sederhana.
     * content_stripped diisi dengan rumus legacy (strip_tags + trim) seperti hasil FaqArticleHooks.
     */
    private function seedFaq(): void
    {
        $this->db->table('faq_topic')->insertBatch([
            ['id_faq_topic' => 1, 'faq_topic' => 'Akun dan Login', 'order' => 1, 'status' => 1],
            ['id_faq_topic' => 2, 'faq_topic' => 'Kepegawaian', 'order' => 2, 'status' => 1],
            ['id_faq_topic' => 3, 'faq_topic' => 'Presensi', 'order' => 3, 'status' => 1],
        ]);

        $this->db->table('faq_sub_topic')->insertBatch([
            ['id_faq_sub_topic' => 1, 'id_faq_topic' => 1, 'faq_sub_topic' => 'Kata Sandi', 'order' => 1, 'status' => 1],
            ['id_faq_sub_topic' => 2, 'id_faq_topic' => 1, 'faq_sub_topic' => 'Profil Akun', 'order' => 2, 'status' => 1],
            ['id_faq_sub_topic' => 3, 'id_faq_topic' => 2, 'faq_sub_topic' => 'Data Pribadi', 'order' => 1, 'status' => 1],
            ['id_faq_sub_topic' => 4, 'id_faq_topic' => 3, 'faq_sub_topic' => 'Absensi Harian', 'order' => 1, 'status' => 1],
            ['id_faq_sub_topic' => 5, 'id_faq_topic' => 2, 'faq_sub_topic' => 'Riwayat Jabatan', 'order' => 2, 'status' => 1],
        ]);

        $articles = [
            [1, 1, 'Cara mengatur ulang kata sandi', '<p>Buka halaman masuk lalu pilih <strong>Lupa kata sandi</strong>. Tautan pengaturan ulang dikirim ke email dinas.</p>', 1],
            [2, 1, 'Syarat kata sandi baru', '<p>Kata sandi minimal delapan karakter dan memuat angka.</p>', 2],
            [3, 2, 'Mengganti foto profil', '<p>Unggah foto berlatar merah melalui menu profil.</p>', 1],
            [4, 3, 'Memperbarui alamat rumah', '<p>Ajukan perubahan alamat melalui menu data pribadi.</p>', 1],
            [5, 4, 'Lupa absen pulang', '<p>Ajukan koreksi kehadiran kepada atasan langsung.</p>', 1],
        ];

        $this->db->table('faq_article')->insertBatch(array_map(static fn (array $a): array => [
            'id_faq_article'   => $a[0],
            'id_faq_sub_topic' => $a[1],
            'title'            => $a[2],
            'content'          => $a[3],
            'content_stripped' => trim((string) preg_replace('/\t+/', '', strip_tags($a[3]))),
            'order'            => $a[4],
            'status'           => 1,
        ], $articles));
    }

    // Method seed per grup DBV (CR-009) — tambahkan hanya di dalam blok grup sendiri.
    // --- DBV-003 (method) ---

    /**
     * G-07/G-08 (DBV-003): jenis libur, kursem, 2 kantor (rantai riil dan LAIN-LAIN), 5 hari libur (status 1/2/10,
     * satu tanpa jenis seperti hasil impor legacy). Sentinel LAIN-LAIN wilayah sudah di-seed migration 100200.
     */
    private function seedDbv003(): void
    {
        $this->db->table('jenis_libur')->insertBatch([
            ['id_jenis_libur' => 1, 'jenis_libur' => 'Libur Nasional', 'order' => 1, 'status' => 1],
            ['id_jenis_libur' => 2, 'jenis_libur' => 'Cuti Bersama', 'order' => 2, 'status' => 1],
        ]);

        $this->db->table('bidang_kursem')->insertBatch([
            ['id_bidang_kursem' => 1, 'bidang_kursem' => 'Teknologi Informasi', 'order' => 1, 'status' => 1],
            ['id_bidang_kursem' => 2, 'bidang_kursem' => 'Manajemen', 'order' => 2, 'status' => 1],
            ['id_bidang_kursem' => 3, 'bidang_kursem' => 'Pariwisata', 'order' => 3, 'status' => 1],
        ]);

        $this->db->table('instansi_kursem')->insertBatch([
            ['id_instansi_kursem' => 1, 'instansi_kursem' => 'Lembaga Administrasi Negara', 'order' => 1, 'status' => 1],
            ['id_instansi_kursem' => 2, 'instansi_kursem' => 'BPSDM Kemenparekraf', 'order' => 2, 'status' => 1],
        ]);

        $this->db->table('kantor')->insertBatch([
            [
                'id_kantor'     => 1, 'order' => 1, 'nama_kantor' => 'Kantor Pusat', 'alamat' => 'Jl. Medan Merdeka Barat No. 17',
                'id_provinsi'   => '31', 'id_kabupaten' => '3171', 'id_kecamatan' => '3171010', 'id_kelurahan' => '3171010001',
                'kode_pos'      => '10110', 'telp' => '021-3838899', 'status' => 1,
                'provinsi_lain' => null, 'kabupaten_lain' => null, 'kecamatan_lain' => null, 'kelurahan_lain' => null,
            ],
            [
                'id_kantor'     => 2, 'order' => 2, 'nama_kantor' => 'Kantor Perwakilan Luar Negeri', 'alamat' => '1-3-1 Higashi-Gotanda',
                'id_provinsi'   => '99', 'id_kabupaten' => '9999', 'id_kecamatan' => '9999999', 'id_kelurahan' => '9999999999',
                'kode_pos'      => null, 'telp' => null, 'status' => 1,
                'provinsi_lain' => 'Jepang', 'kabupaten_lain' => 'Tokyo', 'kecamatan_lain' => 'Shinagawa', 'kelurahan_lain' => 'Higashi-Gotanda',
            ],
        ]);

        $this->db->table('hari_libur')->insertBatch([
            ['id_libur' => 1, 'id_jenis_libur' => 1, 'tgl_mulai' => '2026-01-01', 'tgl_akhir' => '2026-01-01', 'nama_libur' => 'Tahun Baru 2026 Masehi', 'keterangan' => null, 'status' => 1],
            ['id_libur' => 2, 'id_jenis_libur' => 2, 'tgl_mulai' => '2026-03-19', 'tgl_akhir' => '2026-03-20', 'nama_libur' => 'Cuti Bersama Idul Fitri', 'keterangan' => 'SKB 3 Menteri', 'status' => 1],
            ['id_libur' => 3, 'id_jenis_libur' => 1, 'tgl_mulai' => '2026-05-01', 'tgl_akhir' => '2026-05-01', 'nama_libur' => 'Hari Buruh Internasional', 'keterangan' => null, 'status' => 2],
            ['id_libur' => 4, 'id_jenis_libur' => 1, 'tgl_mulai' => '2026-06-01', 'tgl_akhir' => '2026-06-01', 'nama_libur' => 'Hari Lahir Pancasila', 'keterangan' => null, 'status' => 10],
            ['id_libur' => 5, 'id_jenis_libur' => null, 'tgl_mulai' => '2025-12-25', 'tgl_akhir' => '2025-12-25', 'nama_libur' => 'Hari Raya Natal', 'keterangan' => 'Impor legacy tanpa jenis', 'status' => 1],
        ]);
    }

    // --- /DBV-003 ---

    // --- DBV-004 (method) ---
    /**
     * G-04 (DBV-004): ID yang di-hard-code kode legacy dipakai apa adanya (G-doc 6.4) — jenis_kp 1/2/3/5, gol_pppk
     * 7/9–12. `pangkat.order` = level (III/a = 9 dst.); CPNS III/a dan III/a sengaja ber-order sama (UNIQUE(cpns, order)
     * ditunda, P3). `uang_makan` = nilai uji.
     */
    private function seedKenaikanPangkat(): void
    {
        $this->db->table('pangkat')->insertBatch([
            ['id_pangkat' => 7, 'pangkat' => 'Penata Muda', 'gol' => 'III', 'ruang' => 'a', 'gol_ruang' => 'CPNS III/a', 'cpns' => 1, 'order' => 9, 'status' => 1],
            ['id_pangkat' => 13, 'pangkat' => 'Penata Muda', 'gol' => 'III', 'ruang' => 'a', 'gol_ruang' => 'III/a', 'cpns' => 2, 'order' => 9, 'status' => 1],
            ['id_pangkat' => 14, 'pangkat' => 'Penata Muda Tingkat I', 'gol' => 'III', 'ruang' => 'b', 'gol_ruang' => 'III/b', 'cpns' => 2, 'order' => 10, 'status' => 1],
            ['id_pangkat' => 15, 'pangkat' => 'Penata', 'gol' => 'III', 'ruang' => 'c', 'gol_ruang' => 'III/c', 'cpns' => 2, 'order' => 11, 'status' => 1],
        ]);

        // ID 4 dan 6 tidak di-seed: namanya belum diketahui ([I], G-doc 6.4).
        $this->db->table('jenis_kp')->insertBatch([
            ['id_jenis_kp' => 1, 'jenis_kp' => 'Pengangkatan CPNS', 'order' => 1, 'status' => 1],
            ['id_jenis_kp' => 2, 'jenis_kp' => 'Pengangkatan PNS', 'order' => 2, 'status' => 1],
            ['id_jenis_kp' => 3, 'jenis_kp' => 'Reguler', 'order' => 3, 'status' => 1],
            ['id_jenis_kp' => 5, 'jenis_kp' => 'Penyesuaian Ijazah', 'order' => 4, 'status' => 1],
        ]);

        $this->db->table('gol_pppk')->insertBatch([
            ['id_gol_pppk' => 7, 'gol_pppk' => 'VII', 'uang_makan' => 37000, 'order' => 1, 'status' => 1],
            ['id_gol_pppk' => 9, 'gol_pppk' => 'IX', 'uang_makan' => 37000, 'order' => 2, 'status' => 1],
            ['id_gol_pppk' => 10, 'gol_pppk' => 'X', 'uang_makan' => 37000, 'order' => 3, 'status' => 1],
            ['id_gol_pppk' => 11, 'gol_pppk' => 'XI', 'uang_makan' => 41000, 'order' => 4, 'status' => 1],
            ['id_gol_pppk' => 12, 'gol_pppk' => 'XII', 'uang_makan' => 41000, 'order' => 5, 'status' => 1],
        ]);
    }

    /**
     * G-05 (DBV-004): jenjang 1–3 (SD/SLTP/SLTA, tanpa jurusan) dan > 3, bidang 98 "Lainnya", jurusan 1185 "Lainnya"
     * dipakai apa adanya (G-doc 6.4). `order` bidang/jurusan = urut nama seperti aturan impor (P8). `bobot_ipasn`
     * jenjang 8 = nilai uji (jenjang lain memakai default DB).
     */
    private function seedPendidikan(): void
    {
        $this->db->table('jenjang_pendidikan')->insertBatch([
            ['id_jenjang_pendidikan' => 1, 'jenjang_pendidikan_singkat' => 'SD', 'jenjang_pendidikan' => 'Sekolah Dasar', 'row_jurusan' => null, 'order' => 1, 'status' => 1],
            ['id_jenjang_pendidikan' => 2, 'jenjang_pendidikan_singkat' => 'SLTP', 'jenjang_pendidikan' => 'Sekolah Lanjutan Tingkat Pertama', 'row_jurusan' => null, 'order' => 2, 'status' => 1],
            ['id_jenjang_pendidikan' => 3, 'jenjang_pendidikan_singkat' => 'SLTA', 'jenjang_pendidikan' => 'Sekolah Lanjutan Tingkat Atas', 'row_jurusan' => null, 'order' => 3, 'status' => 1],
            ['id_jenjang_pendidikan' => 9, 'jenjang_pendidikan_singkat' => 'S.2', 'jenjang_pendidikan' => 'Strata 2', 'row_jurusan' => 'S_2', 'order' => 5, 'status' => 1],
        ]);
        $this->db->table('jenjang_pendidikan')->insert(
            ['id_jenjang_pendidikan' => 8, 'jenjang_pendidikan_singkat' => 'S.1', 'jenjang_pendidikan' => 'Strata 1', 'row_jurusan' => 'S_1', 'bobot_ipasn' => 20, 'order' => 4, 'status' => 1],
        );

        $this->db->table('bidang_pendidikan')->insertBatch([
            ['id_bidang_pendidikan' => 1, 'bidang_pendidikan' => 'Teknik', 'order' => 3, 'status' => 1],
            ['id_bidang_pendidikan' => 2, 'bidang_pendidikan' => 'Ekonomi', 'order' => 1, 'status' => 1],
            ['id_bidang_pendidikan' => 98, 'bidang_pendidikan' => 'Lainnya', 'order' => 2, 'status' => 1],
        ]);

        $flags = static fn (string ...$on): array => array_combine(
            ['D_I', 'D_II', 'D_III', 'D_IV', 'S_1', 'S_2', 'S_3'],
            array_map(static fn (string $flag): int => (int) in_array($flag, $on, true), ['D_I', 'D_II', 'D_III', 'D_IV', 'S_1', 'S_2', 'S_3']),
        );

        $this->db->table('jurusan_pendidikan')->insertBatch([
            ['id_jurusan_pendidikan' => 1, 'id_bidang_pendidikan' => 1, 'jurusan_pendidikan' => 'Teknik Sipil', 'order' => 2, 'status' => 1] + $flags('D_III', 'S_1'),
            ['id_jurusan_pendidikan' => 2, 'id_bidang_pendidikan' => 1, 'jurusan_pendidikan' => 'Teknik Elektro', 'order' => 1, 'status' => 1] + $flags('S_1', 'S_2'),
            ['id_jurusan_pendidikan' => 3, 'id_bidang_pendidikan' => 2, 'jurusan_pendidikan' => 'Akuntansi', 'order' => 1, 'status' => 1] + $flags('D_III', 'S_1'),
            ['id_jurusan_pendidikan' => 1185, 'id_bidang_pendidikan' => 98, 'jurusan_pendidikan' => 'Lainnya', 'order' => 1, 'status' => 1] + $flags('D_I', 'D_II', 'D_III', 'D_IV', 'S_1', 'S_2', 'S_3'),
        ]);
    }
    // --- /DBV-004 ---

    // --- DBV-005 (method) ---
    // --- /DBV-005 ---
}
