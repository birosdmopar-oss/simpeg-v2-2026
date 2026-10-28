<?php

declare(strict_types=1);

namespace Tests\Support\Presensi;

use App\Libraries\Presensi\Tukin\HasilTukin;
use App\Libraries\Presensi\Tukin\KalkulasiTukin;
use App\Libraries\Presensi\Tukin\StatusHarian;
use App\Libraries\Presensi\TukinData\KonfigurasiTukinDariWebConfig;
use App\Libraries\Presensi\TukinData\SumberCutiTbFase4;
use App\Libraries\Presensi\TukinData\SumberDataPegawaiTukin;
use App\Libraries\Presensi\TukinData\SumberHarianTukin;
use App\Libraries\Presensi\TukinData\SumberHariLibur;
use App\Libraries\Presensi\TukinData\SumberPeriodeTukin;
use App\Libraries\Presensi\TukinData\TukinDataService;

/**
 * Fixture data Fase 4 untuk test 5B D-06 (MAKE-039): baris `riwayat_cuti`, `riwayat_tb`, `riwayat_tb_perpan`, keluarga &
 * anak, lalu kalkulasi Tukin lewat sumber nyata {@see SumberCutiTbFase4}. Data sintetis; dipakai bersama
 * {@see TukinDataUjiTrait} (tarif `web_config`) dan `PegawaiFixtureTrait`.
 */
trait CutiTbUjiTrait
{
    /**
     * Baris `riwayat_cuti` (`$jenis` kode `StatusHarian::JENIS_CUTI` atau id angka).
     */
    protected function cuti(string $nip, string|int $jenis, string $mulai, string $akhir, int $lama = 1, int $status = 1): int
    {
        $id = is_int($jenis) ? $jenis : StatusHarian::JENIS_CUTI[$jenis];

        if ($this->db->table('jenis_cuti')->where('id_jenis_cuti', $id)->countAllResults() === 0) {
            $this->db->table('jenis_cuti')->insert(['id_jenis_cuti' => $id, 'nama_jenis_cuti' => "Jenis uji {$id}", 'status' => 1]);
        }

        $this->db->table('riwayat_cuti')->insert([
            'nip'       => $nip, 'id_jenis_cuti' => $id, 'nama_jenis_cuti' => "Cuti uji {$id}",
            'tgl_mulai' => $mulai, 'tgl_akhir' => $akhir, 'lama_cuti' => $lama, 'status' => $status,
        ]);

        return (int) $this->db->insertID();
    }

    protected function tb(string $nip, string $mulai, string $akhir, int $status = 1): int
    {
        $this->db->table('riwayat_tb')->insert([
            'nip'                        => $nip, 'tgl_mulai' => $mulai, 'tgl_akhir' => $akhir, 'status' => $status,
            'jenjang_pendidikan_singkat' => 'S2', 'institusi_pendidikan' => 'Universitas Uji', 'jurusan_pendidikan' => 'Jurusan Uji',
            'dalam_negeri'               => 1, 'lokasi_pendidikan' => 'Kota Uji', 'lembaga_yang_mendanai' => 'Lembaga Uji',
        ]);

        return (int) $this->db->insertID();
    }

    protected function perpanjanganTb(int $idTb, string $mulai, string $akhir): void
    {
        $this->db->table('riwayat_tb_perpan')->insert([
            'id_riwayat_tb' => $idTb, 'tgl_mulai_perpan' => $mulai, 'tgl_akhir_perpan' => $akhir, 'file_sperpan' => 'uji.pdf',
        ]);
    }

    /**
     * `riwayat_keluarga` status 1 dengan `$jumlahAnak` baris `detail_anak` lahir sebelum periode uji.
     */
    protected function keluarga(string $nip, int $jumlahAnak): void
    {
        $this->db->table('riwayat_keluarga')->insert([
            'nip' => $nip, 'tgl_perkawinan' => '2010-01-01', 'nama_pasangan' => 'Pasangan Uji', 'status' => 1,
        ]);
        $idKeluarga = (int) $this->db->insertID();

        for ($i = 0; $i < $jumlahAnak; $i++) {
            $this->db->table('detail_anak')->insert(['id_riwayat_keluarga' => $idKeluarga, 'tgl_lahir' => '2012-01-0' . ($i + 1), 'nama' => 'Anak Uji']);
        }
    }

    protected function presensiHadir(string $nip, string $tgl): void
    {
        $this->db->table('dh_online')->insert(['nip' => $nip, 'tgl' => $tgl, 'jam_masuk' => '07:30:00', 'jam_pulang' => '16:30:00', 'sumber' => 1]);
    }

    protected function konketBebas(string $nip, string $mulai, string $akhir, int $kategori = 5): void
    {
        $this->db->table('absen_ijin')->insert([
            'nip' => $nip, 'date_start' => $mulai, 'date_end' => $akhir, 'kategori' => $kategori, 'alasan' => 'Uji', 'status' => 'V', 'affect_tukin' => 1,
        ]);
    }

    protected function libur(string $tgl): void
    {
        $this->db->table('hari_libur')->insert(['tgl_mulai' => $tgl, 'tgl_akhir' => $tgl, 'nama_libur' => 'Libur uji', 'status' => 1]);
    }

    /**
     * Rakitan `TukinDataService` dengan sumber cuti/TB nyata (data Fase 4).
     */
    protected function serviceTukinFase4(): TukinDataService
    {
        return new TukinDataService(
            new KonfigurasiTukinDariWebConfig(service('webConfigService')),
            new SumberPeriodeTukin($this->db),
            new SumberHariLibur(service('hariLiburService')),
            new SumberDataPegawaiTukin($this->db),
            new SumberHarianTukin($this->db, new SumberCutiTbFase4($this->db)),
        );
    }

    /**
     * Kalkulasi rekap (K-1) satu pegawai × bulan Tukin, dipotong `$hariIni` (bawaan: akhir periode).
     */
    protected function hitungTukin(string $nip, int $tahun, int $bulan, ?string $hariIni = null): HasilTukin
    {
        $a = $this->serviceTukinFase4()->siapkan($nip, $tahun, $bulan);

        return KalkulasiTukin::hitung($a->periode, $a->hariLibur, $a->harian, $a->konfigurasi, $hariIni ?? $a->periode->akhir, $a->pegawai);
    }
}
