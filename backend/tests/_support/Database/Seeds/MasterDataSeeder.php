<?php

declare(strict_types=1);

namespace Tests\Support\Database\Seeds;

use CodeIgniter\Database\Seeder;

/**
 * Data master uji Modul G (+ akun AuthSeeder untuk token per role).
 *
 * Setiap master diisi minimal 3 entri (dan minimal 2 induk untuk master berjenjang) supaya test G-TC bisa menguji
 * keunikan per induk, pergeseran urutan, dan dropdown berjenjang. Tabel ber-PK AUTO_INCREMENT diisi berurutan
 * sehingga id-nya deterministik (1, 2, 3, …) dan bisa dipakai di fixture test.
 */
class MasterDataSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(AuthSeeder::class);

        // ---------------- G-07: umum & wilayah ----------------
        $this->db->table('agama')->insertBatch([
            ['id_agama' => '1', 'nama_agama' => 'Islam', 'order' => 1, 'status' => '1'],
            ['id_agama' => '2', 'nama_agama' => 'Kristen Protestan', 'order' => 2, 'status' => '1'],
            ['id_agama' => '3', 'nama_agama' => 'Katolik', 'order' => 3, 'status' => '1'],
            ['id_agama' => '4', 'nama_agama' => 'Hindu', 'order' => 4, 'status' => '1'],
            ['id_agama' => '5', 'nama_agama' => 'Buddha', 'order' => 5, 'status' => '1'],
            ['id_agama' => '6', 'nama_agama' => 'Konghucu', 'order' => 6, 'status' => '1'],
        ]);

        $this->db->table('jenis_pegawai')->insertBatch([
            ['id_jenis_pegawai' => 'PNS', 'nama_jenis_pegawai' => 'Pegawai Negeri Sipil', 'order' => 1, 'status' => '1'],
            ['id_jenis_pegawai' => 'PPPK', 'nama_jenis_pegawai' => 'Pegawai Pemerintah dengan Perjanjian Kerja', 'order' => 2, 'status' => '1'],
            ['id_jenis_pegawai' => 'PTT', 'nama_jenis_pegawai' => 'Pegawai Tidak Tetap', 'order' => 3, 'status' => '1'],
        ]);

        $this->db->table('jenis_status')->insertBatch([
            ['id_jenis_status' => 'AKT', 'nama_jenis_status' => 'Aktif', 'order' => 1, 'status' => '1'],
            ['id_jenis_status' => 'NAK', 'nama_jenis_status' => 'Nonaktif', 'order' => 2, 'status' => '1'],
            ['id_jenis_status' => 'PSN', 'nama_jenis_status' => 'Pensiun', 'order' => 3, 'status' => '1'],
        ]);

        $this->db->table('provinsi')->insertBatch([
            ['id_provinsi' => '31', 'provinsi' => 'DKI Jakarta', 'order' => 1, 'status' => '1'],
            ['id_provinsi' => '32', 'provinsi' => 'Jawa Barat', 'order' => 2, 'status' => '1'],
        ]);

        $this->db->table('kabupaten_kota')->insertBatch([
            ['id_kabupaten_kota' => '3171', 'id_provinsi' => '31', 'kabupaten_kota' => 'Jakarta Pusat', 'kd_area' => '021', 'order' => 1, 'status' => '1'],
            ['id_kabupaten_kota' => '3172', 'id_provinsi' => '31', 'kabupaten_kota' => 'Jakarta Utara', 'kd_area' => '021', 'order' => 2, 'status' => '1'],
            ['id_kabupaten_kota' => '3273', 'id_provinsi' => '32', 'kabupaten_kota' => 'Kota Bandung', 'kd_area' => '022', 'order' => 1, 'status' => '1'],
        ]);

        $this->db->table('kecamatan')->insertBatch([
            ['id_kecamatan' => '3171010', 'id_kabupaten_kota' => '3171', 'kecamatan' => 'Gambir', 'order' => 1, 'status' => '1'],
            ['id_kecamatan' => '3171020', 'id_kabupaten_kota' => '3171', 'kecamatan' => 'Sawah Besar', 'order' => 2, 'status' => '1'],
            ['id_kecamatan' => '3273010', 'id_kabupaten_kota' => '3273', 'kecamatan' => 'Sukasari', 'order' => 1, 'status' => '1'],
        ]);

        $this->db->table('kelurahan')->insertBatch([
            ['id_kelurahan' => '3171010001', 'id_kecamatan' => '3171010', 'kelurahan' => 'Gambir', 'kd_pos' => '10110', 'order' => 1, 'status' => '1'],
            ['id_kelurahan' => '3171010002', 'id_kecamatan' => '3171010', 'kelurahan' => 'Cideng', 'kd_pos' => '10150', 'order' => 2, 'status' => '1'],
            ['id_kelurahan' => '3273010001', 'id_kecamatan' => '3273010', 'kelurahan' => 'Sukarasa', 'kd_pos' => '40152', 'order' => 1, 'status' => '1'],
        ]);

        $this->db->table('kantor')->insertBatch([
            ['id_kantor' => 'K01', 'nama_kantor' => 'Kantor Pusat Kemenpar', 'alamat' => 'Jl. Medan Merdeka Barat No. 17', 'id_provinsi' => '31', 'id_kabupaten_kota' => '3171', 'id_kecamatan' => '3171010', 'id_kelurahan' => '3171010001', 'order' => 1, 'status' => '1'],
            ['id_kantor' => 'K02', 'nama_kantor' => 'Kantor Balai Bandung', 'alamat' => 'Jl. Setiabudi', 'id_provinsi' => '32', 'id_kabupaten_kota' => '3273', 'id_kecamatan' => '3273010', 'id_kelurahan' => '3273010001', 'order' => 2, 'status' => '1'],
            ['id_kantor' => 'K03', 'nama_kantor' => 'Kantor Perwakilan Jakarta Utara', 'alamat' => 'Jl. Yos Sudarso', 'id_provinsi' => '31', 'id_kabupaten_kota' => '3172', 'id_kecamatan' => null, 'id_kelurahan' => null, 'order' => 3, 'status' => '1'],
        ]);

        // ---------------- G-02: jabatan, unit, satker ----------------
        $this->db->table('group_jabatan')->insertBatch([
            ['id_group_jabatan' => 1, 'kode_group_jabatan' => 'STR', 'group_jabatan' => 'Struktural', 'group_jabatan_singkat' => 'STR', 'order' => 1, 'status' => '1'],
            ['id_group_jabatan' => 2, 'kode_group_jabatan' => 'JFT', 'group_jabatan' => 'Jabatan Fungsional Tertentu', 'group_jabatan_singkat' => 'JFT', 'order' => 2, 'status' => '1'],
            ['id_group_jabatan' => 3, 'kode_group_jabatan' => 'JFU', 'group_jabatan' => 'Jabatan Fungsional Umum', 'group_jabatan_singkat' => 'JFU', 'order' => 3, 'status' => '1'],
        ]);

        $this->db->table('sub_group_jabatan')->insertBatch([
            ['id_sub_group_jabatan' => 1, 'id_group_jabatan' => 1, 'kode_sub_group_jabatan' => 'ES1', 'sub_group_jabatan' => 'Eselon I', 'sub_group_jabatan_singkat' => 'ES I', 'order' => 1, 'status' => '1'],
            ['id_sub_group_jabatan' => 2, 'id_group_jabatan' => 1, 'kode_sub_group_jabatan' => 'ES2', 'sub_group_jabatan' => 'Eselon II', 'sub_group_jabatan_singkat' => 'ES II', 'order' => 2, 'status' => '1'],
            ['id_sub_group_jabatan' => 3, 'id_group_jabatan' => 2, 'kode_sub_group_jabatan' => 'ANL', 'sub_group_jabatan' => 'Analis', 'sub_group_jabatan_singkat' => 'ANL', 'order' => 1, 'status' => '1'],
        ]);

        $this->db->table('unit')->insertBatch([
            ['id_unit' => 1, 'unit' => 'Sekretariat Kementerian', 'is_upt' => 0, 'order' => 1, 'status' => '1'],
            ['id_unit' => 2, 'unit' => 'Deputi Bidang Pengembangan Destinasi', 'is_upt' => 0, 'order' => 2, 'status' => '1'],
        ]);

        $this->db->table('satker')->insertBatch([
            ['id_satker' => 1, 'id_unit' => 1, 'satker' => 'Biro Sumber Daya Manusia', 'is_upt' => 0, 'zonasi' => 0, 'order' => 1, 'status' => '1'],
            ['id_satker' => 2, 'id_unit' => 1, 'satker' => 'Biro Keuangan', 'is_upt' => 0, 'zonasi' => 0, 'order' => 2, 'status' => '1'],
            ['id_satker' => 3, 'id_unit' => 2, 'satker' => 'Balai Pengembangan Destinasi Wilayah Timur', 'is_upt' => 1, 'zonasi' => 120, 'order' => 1, 'status' => '1'],
        ]);

        $this->db->table('jabatan')->insertBatch([
            ['id_jabatan' => 1, 'id_group_jabatan' => 1, 'id_sub_group_jabatan' => 1, 'id_satker' => 1, 'kelas_jabatan' => 17, 'jabatan' => 'Sekretaris Kementerian', 'umur_pensiun' => 60, 'order' => 1, 'status' => '1'],
            ['id_jabatan' => 2, 'id_group_jabatan' => 1, 'id_sub_group_jabatan' => 1, 'id_satker' => 1, 'kelas_jabatan' => 15, 'jabatan' => 'Kepala Biro Sumber Daya Manusia', 'umur_pensiun' => 60, 'order' => 2, 'status' => '1'],
            ['id_jabatan' => 3, 'id_group_jabatan' => 1, 'id_sub_group_jabatan' => 2, 'id_satker' => 2, 'kelas_jabatan' => 12, 'jabatan' => 'Kepala Bagian Keuangan', 'umur_pensiun' => 58, 'order' => 1, 'status' => '1'],
        ]);

        // ---------------- G-03: lokasi presensi ----------------
        $this->db->table('lokasi_presensi')->insertBatch([
            ['id_lokasi_presensi' => 'L01', 'nama_lokasi' => 'Kantor Pusat', 'latitude' => -6.1753900, 'longitude' => 106.8271500, 'radius_meter' => 100, 'order' => 1, 'status' => '1'],
            ['id_lokasi_presensi' => 'L02', 'nama_lokasi' => 'Balai Bandung', 'latitude' => -6.8915000, 'longitude' => 107.6107000, 'radius_meter' => 50, 'order' => 2, 'status' => '1'],
            ['id_lokasi_presensi' => 'L03', 'nama_lokasi' => 'Kantor Perwakilan Utara', 'latitude' => -6.1214000, 'longitude' => 106.8740000, 'radius_meter' => 25, 'order' => 3, 'status' => '1'],
        ]);

        $this->db->table('user_lokasi_presensi')->insertBatch([
            ['id_user_lokasi_presensi' => 1, 'nip' => '198501012010011001', 'id_lokasi_presensi' => 'L01', 'status' => '1'],
            ['id_user_lokasi_presensi' => 2, 'nip' => '199002152015022002', 'id_lokasi_presensi' => 'L01', 'status' => '1'],
            ['id_user_lokasi_presensi' => 3, 'nip' => '199505052018052006', 'id_lokasi_presensi' => 'L02', 'status' => '1'],
        ]);

        // ---------------- G-04: kenaikan pangkat ----------------
        $this->db->table('pangkat')->insertBatch([
            ['id_pangkat' => 1, 'gol_ruang' => 'III/a', 'gol' => 'III', 'ruang' => 'a', 'cpns' => 1, 'pangkat' => 'Penata Muda', 'order' => 1, 'status' => '1'],
            ['id_pangkat' => 2, 'gol_ruang' => 'III/b', 'gol' => 'III', 'ruang' => 'b', 'cpns' => 2, 'pangkat' => 'Penata Muda Tingkat I', 'order' => 2, 'status' => '1'],
            ['id_pangkat' => 3, 'gol_ruang' => 'III/c', 'gol' => 'III', 'ruang' => 'c', 'cpns' => 2, 'pangkat' => 'Penata', 'order' => 3, 'status' => '1'],
        ]);

        $this->db->table('jenis_kp')->insertBatch([
            ['id_jenis_kp' => 1, 'jenis_kp' => 'Reguler', 'order' => 1, 'status' => '1'],
            ['id_jenis_kp' => 2, 'jenis_kp' => 'Pilihan', 'order' => 2, 'status' => '1'],
            ['id_jenis_kp' => 3, 'jenis_kp' => 'Penyesuaian Ijazah', 'order' => 3, 'status' => '1'],
        ]);

        $this->db->table('gol_pppk')->insertBatch([
            ['id_gol_pppk' => 'IX', 'nama_gol_pppk' => 'Golongan IX', 'order' => 1, 'status' => '1'],
            ['id_gol_pppk' => 'X', 'nama_gol_pppk' => 'Golongan X', 'order' => 2, 'status' => '1'],
            ['id_gol_pppk' => 'XI', 'nama_gol_pppk' => 'Golongan XI', 'order' => 3, 'status' => '1'],
        ]);

        // ---------------- G-05: pendidikan ----------------
        $this->db->table('jenjang_pendidikan')->insertBatch([
            ['id_jenjang_pendidikan' => 1, 'jenjang_pendidikan' => 'Strata 1', 'jenjang_pendidikan_singkat' => 'S-1', 'row_jurusan' => 'S_1', 'order' => 1, 'status' => '1'],
            ['id_jenjang_pendidikan' => 2, 'jenjang_pendidikan' => 'Strata 2', 'jenjang_pendidikan_singkat' => 'S-2', 'row_jurusan' => 'S_2', 'order' => 2, 'status' => '1'],
            ['id_jenjang_pendidikan' => 3, 'jenjang_pendidikan' => 'Diploma III', 'jenjang_pendidikan_singkat' => 'D-III', 'row_jurusan' => 'D_III', 'order' => 3, 'status' => '1'],
        ]);

        $this->db->table('bidang_pendidikan')->insertBatch([
            ['id_bidang_pendidikan' => 1, 'bidang_pendidikan' => 'Ekonomi', 'bidang_pendidikan_english' => 'Economics', 'order' => 1, 'status' => '1'],
            ['id_bidang_pendidikan' => 2, 'bidang_pendidikan' => 'Teknik', 'bidang_pendidikan_english' => 'Engineering', 'order' => 2, 'status' => '1'],
            ['id_bidang_pendidikan' => 3, 'bidang_pendidikan' => 'Sosial', 'bidang_pendidikan_english' => 'Social Science', 'order' => 3, 'status' => '1'],
        ]);

        $this->db->table('jurusan_pendidikan')->insertBatch([
            ['id_jurusan_pendidikan' => 1, 'id_bidang_pendidikan' => 1, 'jurusan_pendidikan' => 'Akuntansi', 'gelar' => 'S.E.', 'order' => 1, 'status' => '1'],
            ['id_jurusan_pendidikan' => 2, 'id_bidang_pendidikan' => 1, 'jurusan_pendidikan' => 'Manajemen', 'gelar' => 'S.E.', 'order' => 2, 'status' => '1'],
            ['id_jurusan_pendidikan' => 3, 'id_bidang_pendidikan' => 2, 'jurusan_pendidikan' => 'Teknik Informatika', 'gelar' => 'S.Kom.', 'order' => 1, 'status' => '1'],
        ]);

        // ---------------- G-06: diklat, hukdis, konket, tanda jasa ----------------
        $this->db->table('diklat')->insertBatch([
            ['id_diklat' => 1, 'jenis_diklat' => 1, 'nama_diklat' => 'Diklat Kepemimpinan Tingkat III', 'order' => 1, 'status' => '1'],
            ['id_diklat' => 2, 'jenis_diklat' => 1, 'nama_diklat' => 'Diklat Kepemimpinan Tingkat IV', 'order' => 2, 'status' => '1'],
            ['id_diklat' => 3, 'jenis_diklat' => 2, 'nama_diklat' => 'Diklat Pengadaan Barang dan Jasa', 'order' => 3, 'status' => '1'],
        ]);

        $this->db->table('tingkat_hukdis')->insertBatch([
            ['id_tingkat_hukdis' => 1, 'tingkat_hukdis' => 'Ringan', 'order' => 1, 'status' => '1'],
            ['id_tingkat_hukdis' => 2, 'tingkat_hukdis' => 'Sedang', 'order' => 2, 'status' => '1'],
            ['id_tingkat_hukdis' => 3, 'tingkat_hukdis' => 'Berat', 'order' => 3, 'status' => '1'],
        ]);

        $this->db->table('jenis_hukdis')->insertBatch([
            ['id_jenis_hukdis' => 1, 'id_tingkat_hukdis' => 1, 'jenis_hukdis' => 'Teguran Lisan', 'order' => 1, 'status' => '1'],
            ['id_jenis_hukdis' => 2, 'id_tingkat_hukdis' => 1, 'jenis_hukdis' => 'Teguran Tertulis', 'order' => 2, 'status' => '1'],
            ['id_jenis_hukdis' => 3, 'id_tingkat_hukdis' => 2, 'jenis_hukdis' => 'Penundaan Kenaikan Gaji Berkala', 'order' => 1, 'status' => '1'],
        ]);

        $this->db->table('jenis_konket')->insertBatch([
            ['id_jenis_konket' => 'WFO', 'nama_jenis_konket' => 'Work From Office', 'affect_tukin' => 0, 'order' => 1, 'status' => '1'],
            ['id_jenis_konket' => 'WFH', 'nama_jenis_konket' => 'Work From Home', 'affect_tukin' => 1, 'order' => 2, 'status' => '1'],
            ['id_jenis_konket' => 'DL', 'nama_jenis_konket' => 'Dinas Luar', 'affect_tukin' => 0, 'order' => 3, 'status' => '1'],
        ]);

        $this->db->table('tanda_jasa')->insertBatch([
            ['id_tanda_jasa' => 1, 'tanda_jasa' => 'Satyalancana Karya Satya X Tahun', 'order' => 1, 'status' => '1'],
            ['id_tanda_jasa' => 2, 'tanda_jasa' => 'Satyalancana Karya Satya XX Tahun', 'order' => 2, 'status' => '1'],
            ['id_tanda_jasa' => 3, 'tanda_jasa' => 'Satyalancana Karya Satya XXX Tahun', 'order' => 3, 'status' => '1'],
        ]);

        // ---------------- G-08: hari libur ----------------
        $this->db->table('jenis_libur')->insertBatch([
            ['id_jenis_libur' => 'NAS', 'nama_jenis_libur' => 'Libur Nasional', 'order' => 1, 'status' => '1'],
            ['id_jenis_libur' => 'CB', 'nama_jenis_libur' => 'Cuti Bersama', 'order' => 2, 'status' => '1'],
            ['id_jenis_libur' => 'KHS', 'nama_jenis_libur' => 'Libur Khusus', 'order' => 3, 'status' => '1'],
        ]);

        $this->db->table('hari_libur')->insertBatch([
            ['id_hari_libur' => 1, 'id_jenis_libur' => 'NAS', 'nama' => 'Tahun Baru Masehi', 'tgl_mulai' => '2026-01-01', 'tgl_akhir' => '2026-01-01', 'keterangan' => null, 'status' => '1'],
            ['id_hari_libur' => 2, 'id_jenis_libur' => 'CB', 'nama' => 'Cuti Bersama Idul Fitri', 'tgl_mulai' => '2026-03-19', 'tgl_akhir' => '2026-03-23', 'keterangan' => 'Sesuai SKB 3 Menteri', 'status' => '1'],
            ['id_hari_libur' => 3, 'id_jenis_libur' => 'NAS', 'nama' => 'Hari Kemerdekaan', 'tgl_mulai' => '2026-08-17', 'tgl_akhir' => '2026-08-17', 'keterangan' => null, 'status' => '1'],
        ]);

        // ---------------- G-09: web config ----------------
        $this->db->table('web_config')->insertBatch([
            ['id_web_config' => 1, 'config_name' => 'tarif_uang_makan_pns', 'config_value' => '41000', 'tipe_data' => 'integer', 'keterangan' => 'Tarif uang makan PNS per hari'],
            ['id_web_config' => 2, 'config_name' => 'jam_masuk_normal', 'config_value' => '07:30', 'tipe_data' => 'time', 'keterangan' => 'Jam masuk hari kerja normal'],
            ['id_web_config' => 3, 'config_name' => 'potongan_tl1', 'config_value' => '0.5', 'tipe_data' => 'decimal', 'keterangan' => 'Persentase potongan TL1'],
            ['id_web_config' => 4, 'config_name' => 'header_pdf', 'config_value' => 'KEMENTERIAN PARIWISATA', 'tipe_data' => 'string', 'keterangan' => 'Header dokumen PDF'],
            ['id_web_config' => 5, 'config_name' => 'presensi_wajib_foto', 'config_value' => '1', 'tipe_data' => 'boolean', 'keterangan' => 'Presensi wajib melampirkan foto'],
        ]);

        // ---------------- G-10: FAQ ----------------
        $this->db->table('faq_topic')->insertBatch([
            ['id_topic' => 'T01', 'nama_topic' => 'Kepegawaian', 'order' => 1, 'status' => '1'],
            ['id_topic' => 'T02', 'nama_topic' => 'Presensi & Tukin', 'order' => 2, 'status' => '1'],
            ['id_topic' => 'T03', 'nama_topic' => 'Aplikasi SIMPEG', 'order' => 3, 'status' => '1'],
        ]);

        $this->db->table('faq_sub_topic')->insertBatch([
            ['id_sub_topic' => 'S01', 'id_topic' => 'T01', 'nama_sub_topic' => 'Cuti', 'order' => 1, 'status' => '1'],
            ['id_sub_topic' => 'S02', 'id_topic' => 'T01', 'nama_sub_topic' => 'Kenaikan Pangkat', 'order' => 2, 'status' => '1'],
            ['id_sub_topic' => 'S03', 'id_topic' => 'T02', 'nama_sub_topic' => 'Presensi Online', 'order' => 1, 'status' => '1'],
        ]);

        $this->db->table('faq_article')->insertBatch([
            ['id_article' => 'A01', 'id_sub_topic' => 'S01', 'judul' => 'Bagaimana cara mengajukan cuti tahunan?', 'isi' => 'Ajukan lewat menu Layanan > Cuti, lalu tunggu persetujuan atasan langsung.', 'order' => 1, 'status' => '1'],
            ['id_article' => 'A02', 'id_sub_topic' => 'S01', 'judul' => 'Berapa hak cuti tahunan saya?', 'isi' => 'Hak cuti tahunan adalah 12 hari kerja per tahun.', 'order' => 2, 'status' => '1'],
            ['id_article' => 'A03', 'id_sub_topic' => 'S03', 'judul' => 'Kenapa presensi saya ditolak di luar radius?', 'isi' => 'Presensi hanya diterima di dalam radius lokasi yang terdaftar.', 'order' => 1, 'status' => '1'],
        ]);
    }
}
