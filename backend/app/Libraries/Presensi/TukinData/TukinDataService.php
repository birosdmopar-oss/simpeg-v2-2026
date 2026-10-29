<?php

declare(strict_types=1);

namespace App\Libraries\Presensi\TukinData;

use App\Libraries\Presensi\Tukin\DataPegawaiTukin;
use App\Libraries\Presensi\Tukin\PeriodeTukin;
use CodeIgniter\Database\BaseConnection;
use Config\Presensi;
use LogicException;

/**
 * Adapter data v2 → argumen `KalkulasiTukin::hitung()` (PRD 5A §7.7, sisi integrasi D-04/D-05/D-07). Library murni
 * `Presensi/Tukin` tidak diubah; 5A tidak membuat endpoint Tukin (D-08 = 5B).
 *
 * Rentang data = periode sebelumnya (lookback cuti sakit, README Tukin "Kontrak data") s.d. akhir periode berjalan,
 * untuk hari libur maupun harian. Seluruh galat sumber dilempar sebagai {@see GalatDataTukin}.
 *
 * {@see self::siapkanBanyak()} (MAKE-039, PRD 5B §7.1.4): jalur massal untuk laporan/rekap — periode, libur, dan
 * konfigurasi dihitung sekali; harian & data pegawai dimuat per batch `Config\Presensi::$tukinBatchNip` NIP dengan jumlah
 * kueri tetap; galat per pegawai tidak menggagalkan seluruh laporan. Urutan galat per NIP sama dengan `siapkan()`
 * (harian → konfigurasi → data pegawai).
 *
 * [K T-01, CR-083] Pegawai yang `created_at`-nya tidak sebelum akhir periode BKN (hari terakhir periode, jam apa pun)
 * tidak dimuat data hariannya (`harian = []`, semua hari kerja TK), seperti legacy; lihat
 * {@see SumberDataPegawaiTukin::tanpaDataHarian()}.
 */
final class TukinDataService
{
    public function __construct(
        private readonly KonfigurasiTukinDariWebConfig $konfigurasi,
        private readonly SumberPeriodeTukin $periode,
        private readonly SumberHariLibur $hariLibur,
        private readonly SumberDataPegawaiTukin $dataPegawai,
        private readonly SumberHarianTukin $harian,
    ) {
    }

    /**
     * Rakitan bawaan dari service aplikasi; sumber cuti/TB dipilih lewat `Config\Presensi::$sumberCutiTb`.
     */
    public static function bawaan(?BaseConnection $db = null): self
    {
        $db ??= db_connect();

        return new self(
            new KonfigurasiTukinDariWebConfig(service('webConfigService')),
            new SumberPeriodeTukin($db),
            new SumberHariLibur(service('hariLiburService')),
            new SumberDataPegawaiTukin($db),
            new SumberHarianTukin($db, self::sumberCutiTb(config(Presensi::class))),
        );
    }

    public static function sumberCutiTb(Presensi $config): SumberCutiTbInterface
    {
        $kelas = $config->sumberCutiTb;

        if (! class_exists($kelas) || ! is_subclass_of($kelas, SumberCutiTbInterface::class)) {
            throw new LogicException("Config\\Presensi::\$sumberCutiTb harus kelas yang mengimplementasikan SumberCutiTbInterface: {$kelas}.");
        }

        return new $kelas();
    }

    /**
     * Argumen siap pakai untuk satu pegawai × satu bulan Tukin.
     *
     * @throws GalatDataTukin
     */
    public function siapkan(string $nip, int $tahun, int $bulan, ?string $hariIni = null, bool $tanpaPegawaiBaru = false): ArgumenTukin
    {
        $bkn     = $this->periode->barisBkn($tahun, $bulan);
        $periode = SumberPeriodeTukin::periode($tahun, $bulan, $bkn);

        [$tahunLalu, $bulanLalu] = $bulan === 1 ? [$tahun - 1, 12] : [$tahun, $bulan - 1];
        $sebelumnya              = SumberPeriodeTukin::periode($tahunLalu, $bulanLalu, $this->periode->barisBkn($tahunLalu, $bulanLalu));

        $awal  = min($sebelumnya->awal, $periode->awal);
        $libur = $this->hariLibur->untuk($awal, $periode->akhir);

        $tanpa = $this->dataPegawai->tanpaDataHarian([$nip], $periode) !== [];

        try {
            $pegawai = $this->dataPegawai->untuk($nip, $periode, $bkn['id'] ?? null, $libur, $tanpaPegawaiBaru);
        } catch (GalatDataTukin $e) {
            $pegawai = $e; // dilempar setelah harian & konfigurasi: urutan galat tetap harian → konfigurasi → pegawai
        }

        $harian      = $tanpa ? [] : $this->harian->untuk($nip, $awal, $periode->akhir, $pegawai instanceof DataPegawaiTukin && $pegawai->lewatiLoopHarian);
        $konfigurasi = $this->konfigurasi->untuk($periode);

        if ($pegawai instanceof GalatDataTukin) {
            throw $pegawai;
        }

        return new ArgumenTukin($periode, $libur, $harian, $konfigurasi, $pegawai, $tanpa);
    }

    /**
     * Argumen banyak pegawai × satu bulan Tukin; hasil per NIP = `siapkan()` NIP itu, atau galatnya.
     *
     * @param list<string> $nip
     *
     * @return array<array-key, ArgumenTukin|GalatDataTukin> setiap NIP masukan menjadi kunci
     */
    public function siapkanBanyak(array $nip, int $tahun, int $bulan, ?string $hariIni = null, ?int $ukuranBatch = null, bool $tanpaPegawaiBaru = false): array
    {
        $unik = array_values(array_unique(array_map('strval', $nip)));

        if ($unik === []) {
            return [];
        }

        $bkn     = $this->periode->barisBkn($tahun, $bulan);
        $periode = SumberPeriodeTukin::periode($tahun, $bulan, $bkn);

        [$tahunLalu, $bulanLalu] = $bulan === 1 ? [$tahun - 1, 12] : [$tahun, $bulan - 1];
        $sebelumnya              = SumberPeriodeTukin::periode($tahunLalu, $bulanLalu, $this->periode->barisBkn($tahunLalu, $bulanLalu));

        $awal  = min($sebelumnya->awal, $periode->awal);
        $libur = $this->hariLibur->untuk($awal, $periode->akhir);

        try {
            $konfigurasi = $this->konfigurasi->untuk($periode);
        } catch (GalatDataTukin $e) {
            $konfigurasi = $e;
        }

        $batas = max(1, $ukuranBatch ?? config(Presensi::class)->tukinBatchNip);
        $tanpa = $this->dataPegawai->tanpaDataHarian($unik, $periode);
        $hasil = [];

        foreach (array_chunk($unik, $batas) as $batch) {
            $pegawai = $this->dataPegawai->untukBanyak($batch, $periode, $bkn['id'] ?? null, $libur, $tanpaPegawaiBaru);
            $lewati  = array_values(array_filter($batch, static fn (string $n): bool => ($pegawai[$n] ?? null) instanceof DataPegawaiTukin && $pegawai[$n]->lewatiLoopHarian));
            $harian  = $this->harian->untukBanyak(array_values(array_diff($batch, $tanpa)), $awal, $periode->akhir, $lewati);

            foreach ($batch as $n) {
                $h = $harian[$n] ?? [];
                $p = $pegawai[$n] ?? new DataTukinTidakSah("Pegawai {$n} tidak ditemukan.");

                $hasil[$n] = match (true) {
                    $h instanceof GalatDataTukin           => $h,
                    $konfigurasi instanceof GalatDataTukin => $konfigurasi,
                    $p instanceof GalatDataTukin           => $p,
                    default                                => new ArgumenTukin($periode, $libur, $h, $konfigurasi, $p, in_array($n, $tanpa, true)),
                };
            }
        }

        return $hasil;
    }

    /**
     * Periode Tukin bulan (tahun, bulan) termasuk baris BKN (dipakai pemanggil 5B untuk judul laporan).
     */
    public function periode(int $tahun, int $bulan): PeriodeTukin
    {
        return SumberPeriodeTukin::periode($tahun, $bulan, $this->periode->barisBkn($tahun, $bulan));
    }
}
