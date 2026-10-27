<?php

declare(strict_types=1);

namespace App\Libraries\Presensi\TukinData;

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
    public function siapkan(string $nip, int $tahun, int $bulan, ?string $hariIni = null): ArgumenTukin
    {
        $bkn     = $this->periode->barisBkn($tahun, $bulan);
        $periode = SumberPeriodeTukin::periode($tahun, $bulan, $bkn);

        [$tahunLalu, $bulanLalu] = $bulan === 1 ? [$tahun - 1, 12] : [$tahun, $bulan - 1];
        $sebelumnya              = SumberPeriodeTukin::periode($tahunLalu, $bulanLalu, $this->periode->barisBkn($tahunLalu, $bulanLalu));

        $awal  = min($sebelumnya->awal, $periode->awal);
        $libur = $this->hariLibur->untuk($awal, $periode->akhir);

        return new ArgumenTukin(
            $periode,
            $libur,
            $this->harian->untuk($nip, $awal, $periode->akhir),
            $this->konfigurasi->untuk($periode),
            $this->dataPegawai->untuk($nip, $periode, $bkn['id'] ?? null, $libur),
        );
    }

    /**
     * Periode Tukin bulan (tahun, bulan) termasuk baris BKN (dipakai pemanggil 5B untuk judul laporan).
     */
    public function periode(int $tahun, int $bulan): PeriodeTukin
    {
        return SumberPeriodeTukin::periode($tahun, $bulan, $this->periode->barisBkn($tahun, $bulan));
    }
}
