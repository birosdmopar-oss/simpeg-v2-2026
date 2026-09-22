<?php

declare(strict_types=1);

namespace Tests\Support\Database\Seeds;

use CodeIgniter\Database\Seeder;

/**
 * Data master uji Modul G (+ akun AuthSeeder untuk token per role).
 * Nilai awal mengikuti simpeg_v2_local_seed.sql, ditambah 1 cabang wilayah kedua untuk uji keunikan per induk
 * dan dropdown berjenjang.
 */
class MasterDataSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(AuthSeeder::class);

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
            ['id_provinsi' => '31', 'nama_provinsi' => 'DKI Jakarta', 'order' => 1, 'status' => '1'],
            ['id_provinsi' => '32', 'nama_provinsi' => 'Jawa Barat', 'order' => 2, 'status' => '1'],
        ]);

        $this->db->table('kabupaten_kota')->insertBatch([
            ['id_kabupaten_kota' => '3171', 'id_provinsi' => '31', 'nama_kabupaten_kota' => 'Jakarta Pusat', 'order' => 1, 'status' => '1'],
            ['id_kabupaten_kota' => '3172', 'id_provinsi' => '31', 'nama_kabupaten_kota' => 'Jakarta Utara', 'order' => 2, 'status' => '1'],
            ['id_kabupaten_kota' => '3273', 'id_provinsi' => '32', 'nama_kabupaten_kota' => 'Kota Bandung', 'order' => 1, 'status' => '1'],
        ]);

        $this->db->table('kecamatan')->insertBatch([
            ['id_kecamatan' => '317101', 'id_kabupaten_kota' => '3171', 'nama_kecamatan' => 'Gambir', 'order' => 1, 'status' => '1'],
            ['id_kecamatan' => '317102', 'id_kabupaten_kota' => '3171', 'nama_kecamatan' => 'Sawah Besar', 'order' => 2, 'status' => '1'],
            ['id_kecamatan' => '327301', 'id_kabupaten_kota' => '3273', 'nama_kecamatan' => 'Sukasari', 'order' => 1, 'status' => '1'],
        ]);

        $this->db->table('kelurahan')->insertBatch([
            ['id_kelurahan' => '3171011001', 'id_kecamatan' => '317101', 'nama_kelurahan' => 'Gambir', 'order' => 1, 'status' => '1'],
            ['id_kelurahan' => '3171011002', 'id_kecamatan' => '317101', 'nama_kelurahan' => 'Cideng', 'order' => 2, 'status' => '1'],
            ['id_kelurahan' => '3273011001', 'id_kecamatan' => '327301', 'nama_kelurahan' => 'Sukarasa', 'order' => 1, 'status' => '1'],
        ]);
    }
}
