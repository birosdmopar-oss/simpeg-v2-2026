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
        // --- /DBV-003 ---

        // --- DBV-004 ---
        // --- /DBV-004 ---

        // --- DBV-005 ---
        $this->seedG06();
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
    // --- /DBV-003 ---

    // --- DBV-004 (method) ---
    // --- /DBV-004 ---

    // --- DBV-005 (method) ---
    /**
     * G-06 (DBV-005/CR-012): diklat, tingkat & jenis hukdis, jenis konket, tanda jasa. Nama contoh [I] (data produksi
     * belum ada). ID yang di-hard-code kode legacy dipakai apa adanya (G-06 Bagian 2.6, tidak dikunci): diklat 8 (tidak
     * dikirim ke SIASN), konket id 2/4/5/6 dan old_id 8 (Dinas), 10 (sakit), 13 (lain-lain), tanda jasa 26/27/28 (SLKS)
     * dan 44 `LAIN-LAIN`. Pasangan id ↔ old_id konket di sini FIKTIF: di legacy keduanya kode terpisah dan pasangannya
     * baru diketahui dari data produksi.
     *
     * Kolom audit: updated_at tetap 2024-01-01 (kolom NOT NULL ON UPDATE, jadi test bisa membuktikan saudara yang hanya
     * bergeser urutannya tidak ikut di-stamp), updated_by NULL.
     */
    private function seedG06(): void
    {
        $audit = ['status' => 1, 'updated_at' => '2024-01-01 00:00:00', 'updated_by' => null];

        $this->db->table('diklat')->insertBatch(array_map(static fn (array $r): array => [
            'id_diklat' => $r[0], 'jenis_diklat' => $r[1], 'nama_diklat' => $r[2], 'order' => $r[3],
        ] + ($r[4] ?? []) + $audit, [
            [1, 1, 'Diklatpim Tingkat IV', 1],
            [2, 1, 'Diklatpim Tingkat III', 2],
            [3, 2, 'Pelatihan Teknis Pengadaan Barang/Jasa', 1],
            [4, 3, 'Pelatihan Fungsional Analis Kepegawaian', 1],
            [5, 5, 'Sertifikasi Ahli Pengadaan', 1],
            // Status 10: contoh baris hasil jalur legacy dm_diklat (soft delete, rwy/L_diklat.php:966-971).
            [6, 2, 'Pelatihan Teknis Lama', 2, ['status' => 10]],
            [8, 4, 'Pelatihan Dasar CPNS', 1],
        ]));

        $this->db->table('tingkat_hukdis')->insertBatch([
            ['id_tingkat_hukdis' => 1, 'tingkat_hukdis' => 'Ringan', 'bobot_ipasn' => 5, 'order' => 1] + $audit,
            ['id_tingkat_hukdis' => 2, 'tingkat_hukdis' => 'Sedang', 'bobot_ipasn' => 3, 'order' => 2] + $audit,
            ['id_tingkat_hukdis' => 3, 'tingkat_hukdis' => 'Berat', 'bobot_ipasn' => 1, 'order' => 3] + $audit,
        ]);

        $this->db->table('jenis_hukdis')->insertBatch(array_map(static fn (array $r): array => [
            'id_jenis_hukdis' => $r[0], 'id_tingkat_hukdis' => $r[1], 'jenis_hukdis' => $r[2], 'masa_sanksi_bulan' => $r[3], 'order' => $r[4],
        ] + $audit, [
            [1, 1, 'Teguran Lisan', null, 1],
            [2, 1, 'Teguran Tertulis', null, 2],
            [3, 1, 'Pernyataan Tidak Puas Secara Tertulis', null, 3],
            [4, 2, 'Pemotongan Tunjangan Kinerja 25% Selama 6 Bulan', 6, 1],
            [5, 3, 'Penurunan Jabatan Setingkat Lebih Rendah Selama 12 Bulan', 12, 1],
        ]));

        $this->db->table('jenis_konket')->insertBatch(array_map(static fn (array $r): array => [
            'id_jenis_konket' => $r[0], 'old_id' => $r[1], 'jenis_konket' => $r[2], 'affect_tukin' => $r[3], 'order' => $r[4],
        ] + $audit, [
            [1, 1, 'Izin Terlambat', 1, 1],
            [2, 8, 'Dinas', 2, 2],
            [4, 10, 'Sakit', 1, 3],
            [5, 13, 'Lain-lain', 1, 4],
            [6, 5, 'Cuti', 2, 5],
        ]));

        $this->db->table('tanda_jasa')->insertBatch([
            ['id_tanda_jasa' => 26, 'tanda_jasa' => 'Satyalancana Karya Satya XXX Tahun', 'order' => 1] + $audit,
            ['id_tanda_jasa' => 27, 'tanda_jasa' => 'Satyalancana Karya Satya XX Tahun', 'order' => 2] + $audit,
            ['id_tanda_jasa' => 28, 'tanda_jasa' => 'Satyalancana Karya Satya X Tahun', 'order' => 3] + $audit,
            ['id_tanda_jasa' => 44, 'tanda_jasa' => 'LAIN-LAIN', 'order' => 4] + $audit,
        ]);
    }
    // --- /DBV-005 ---
}
