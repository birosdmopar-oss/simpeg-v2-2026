<?php

declare(strict_types=1);

namespace App\Libraries\Presensi\Tukin;

/**
 * Agregasi satu pegawai × satu periode. Semua potongan dalam basis poin (1% = 100).
 *
 * Kolom mengikuti rekap legacy `laporan_tukin_us_skp` (:13380-13390): `rincian` = kolom TL/PSW/TK/TA/CUTI,
 * `totalPresensi` = kolom `total_presensi`, `totalSkp` = `total_skp`, `totalPotongan` = `total_potongan`.
 * `potonganPresensi` adalah jumlah potongan harian sebelum aturan pengganti periode (`alasan`) diterapkan.
 *
 * `alasan`: `null` (presensi + SKP), `pemutihan_tb`, `cuti_besar`, `cuti_melahirkan`, atau `cuti_sepanjang_periode`
 * (:13026-13057).
 */
final readonly class HasilTukin
{
    /**
     * @param array<string, int>         $jumlah  Jumlah kejadian per kategori (TL1..TL3, PSW1..PSW3, TPM, TPP, TK, CS).
     * @param array<string, int>         $rincian Potongan harian per kolom rekap (TL, PSW, TA, TK, CUTI).
     * @param array<string, HasilHarian> $harian  Hasil per hari kerja yang dihitung.
     */
    public function __construct(
        public PeriodeTukin $periode,
        public string $akhirDihitung,
        public int $hariKerja,
        public array $jumlah,
        public array $rincian,
        public int $potonganPresensi,
        public int $totalPresensi,
        public int $totalSkp,
        public int $totalPotongan,
        public ?string $alasan = null,
        public array $harian = [],
    ) {
    }
}
