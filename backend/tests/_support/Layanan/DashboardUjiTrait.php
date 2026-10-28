<?php

declare(strict_types=1);

namespace Tests\Support\Layanan;

/**
 * Fixture Dashboard Status Layanan & PIC (MAKE-025 M6): baris pengajuan cuti/TB/IB/PMK langsung ke tabel (tanpa service
 * modul — alur modul sudah diuji di M3/M5), Dipakai bersama LayananStudiUjiTrait dan
 * SisipMinimalTrait di turunan DatabaseTestCase (`$seed = MasterDataSeeder`).
 */
trait DashboardUjiTrait
{
    /**
     * @param array<string, mixed> $lebih
     */
    protected function sisipCuti(string $nip, array $lebih = []): int
    {
        return $this->sisipMinimal('riwayat_cuti', $lebih + [
            'nip'        => $nip, 'nama' => 'Pemohon Cuti', 'id_jenis_cuti' => 1, 'nama_jenis_cuti' => 'Cuti Tahunan',
            'tgl_mulai'  => '2026-11-02', 'tgl_akhir' => '2026-11-05', 'lama_cuti' => 4, 'status' => 0, 'status_al' => 0,
            'status_pyb' => 0, 'created_at' => '2026-10-20 01:00:00',
        ]);
    }

    /**
     * @param array<string, mixed> $lebih
     */
    protected function sisipStudi(string $jenis, string $nip, array $lebih = []): int
    {
        return $this->sisipMinimal("riwayat_{$jenis}", $lebih + [
            'nip'                  => $nip, 'nama' => 'Pemohon Studi', 'id_jenjang_pendidikan' => 8, 'jenjang_pendidikan_singkat' => 'S.1',
            'institusi_pendidikan' => 'Universitas Uji', 'jurusan_pendidikan' => 'Manajemen', 'tgl_mulai' => '2027-01-01',
            'tgl_akhir'            => '2028-12-31', 'status' => 0, 'created_at' => '2026-10-21 01:00:00',
        ]);
    }

    /**
     * @param array<string, mixed> $lebih
     */
    protected function sisipPmk(string $nip, array $lebih = []): int
    {
        return $this->sisipMinimal('riwayat_pmk_req', $lebih + [
            'nip'     => $nip, 'jenis_pmk' => 'Swasta', 'instansi' => 'PT Uji', 'tgl_awal' => '2010-01-01', 'tgl_akhir' => '2012-12-31',
            'mker_th' => 2, 'mker_bl' => 11, 'status' => 0, 'created_at' => '2026-10-22 01:00:00',
        ]);
    }
}
