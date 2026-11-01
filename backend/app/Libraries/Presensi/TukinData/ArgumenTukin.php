<?php

declare(strict_types=1);

namespace App\Libraries\Presensi\TukinData;

use App\Libraries\Presensi\Tukin\DataPegawaiTukin;
use App\Libraries\Presensi\Tukin\KonfigurasiTukin;
use App\Libraries\Presensi\Tukin\PeriodeTukin;

/**
 * Argumen siap pakai `KalkulasiTukin::hitung($periode, $hariLibur, $harian, $konfigurasi, $hariIni, $pegawai)` untuk satu
 * pegawai × satu bulan Tukin (PRD 5A §7.7.1).
 */
final readonly class ArgumenTukin
{
    /**
     * @param list<string>                        $hariLibur periode sebelumnya s.d. akhir periode (lookback cuti sakit)
     * @param array<string, array<string, mixed>> $harian          per tanggal YYYY-MM-DD, rentang sama dengan $hariLibur
     * @param bool                                $tanpaDataHarian T-01 (CR-083): data harian sengaja tidak dimuat
     *                                                             ({@see SumberDataPegawaiTukin::tanpaDataHarian()})
     * @param list<array{id: int, jenis: string, mulai: string, akhir: string, lama: int}>|null $riwayatCuti rekap IL: riwayat
     *                                                             cuti utuh [awal, de_tukin] untuk jadwal potongan (CR-085);
     *                                                             null di DH
     */
    public function __construct(
        public PeriodeTukin $periode,
        public array $hariLibur,
        public array $harian,
        public KonfigurasiTukin $konfigurasi,
        public ?DataPegawaiTukin $pegawai,
        public bool $tanpaDataHarian = false,
        public ?array $riwayatCuti = null,
    ) {
    }
}
