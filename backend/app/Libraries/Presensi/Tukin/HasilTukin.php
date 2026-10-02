<?php

declare(strict_types=1);

namespace App\Libraries\Presensi\Tukin;

/**
 * Agregasi satu pegawai × satu periode. Semua potongan dalam basis poin (1% = 100).
 *
 * Kolom mengikuti laporan legacy: `totalPresensi` = potongan presensi harian (TL/PSW/TA/TK/cuti sakit),
 * `totalPresensiLkh` = presensi + LKH, `totalPotongan` = presensi + LKH + TB bulanan + cuti bulanan (view
 * `laporan_tukin.php:295`).
 */
final readonly class HasilTukin
{
    public int $totalPresensi;
    public int $totalPresensiLkh;
    public int $totalPotongan;

    /**
     * @param array<string, int>         $jumlah Jumlah kejadian per kategori (TL1..TL3, PSW1..PSW3, TPM, TPP, TK, LKH, CS).
     * @param array<string, HasilHarian> $harian Hasil per hari kerja yang dihitung.
     */
    public function __construct(
        public PeriodeTukin $periode,
        public string $akhirDihitung,
        public int $hariKerja,
        public array $jumlah,
        public int $potonganPresensi,
        public int $potonganLkh,
        public int $potonganTb,
        public int $potonganCuti,
        public array $harian = [],
    ) {
        $this->totalPresensi    = $potonganPresensi;
        $this->totalPresensiLkh = $potonganPresensi + $potonganLkh;
        $this->totalPotongan    = $potonganPresensi + $potonganLkh + $potonganTb + $potonganCuti;
    }
}
