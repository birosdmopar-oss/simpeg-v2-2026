<?php

declare(strict_types=1);

namespace App\Libraries\Presensi\Tukin;

use App\Libraries\Kepegawaian\Kalkulasi\TanggalBisnis;

/**
 * Agregasi Tukin satu pegawai × satu periode, mengikuti loop pegawai rekap legacy `laporan_tukin_us_skp`
 * (:12604-13057).
 *
 * Per tanggal kalender dalam `[awal, min(akhir, hariIni)]`: (1) catat apakah hari kerja terakhir masih TB;
 * (2) hari di luar masa kerja dilewati; (3) libur/akhir pekan dilewati; (4) TB → tanpa potongan; (5) cuti → hanya
 * cuti sakit hari ke-15+; (6) konket `affect_tukin=1` → tanpa potongan; (7) presensi. Setelah loop, aturan pengganti
 * periode diterapkan berurutan: pemutihan TB → cuti besar → cuti melahirkan → cuti sepanjang periode → presensi + SKP.
 */
final class KalkulasiTukin
{
    private const KATEGORI = ['TL1', 'TL2', 'TL3', 'PSW1', 'PSW2', 'PSW3', 'TPM', 'TPP', 'TK', 'CS'];
    private const KOLOM    = ['TL', 'PSW', 'TK', 'TA', 'CUTI'];

    /**
     * @param list<string>                        $hariLibur Tanggal libur, termasuk periode sebelumnya (untuk urutan
     *                                                       cuti sakit).
     * @param array<string, array<string, mixed>> $harian    Input per tanggal (lihat `StatusHarian`). Sertakan periode
     *                                                       sebelumnya (16–15 bulan lalu) untuk urutan cuti sakit, dan
     *                                                       seluruh hari periode untuk cek cuti sepanjang periode.
     * @param string|null                         $hariIni   Tanggal proses; hari setelahnya tidak dihitung (legacy
     *                                                       memotong akhir loop ke hari ini, :11881-11885).
     */
    public static function hitung(
        PeriodeTukin $periode,
        array $hariLibur,
        array $harian,
        KonfigurasiTukin $config,
        ?string $hariIni = null,
        ?DataPegawaiTukin $pegawai = null,
    ): HasilTukin {
        foreach (array_keys($harian) as $tanggal) {
            TanggalBisnis::wajibValid((string) $tanggal, 'tanggal input harian');
        }
        $pegawai ??= new DataPegawaiTukin();

        $akhir = $periode->akhir;
        if ($hariIni !== null && TanggalBisnis::wajibValid($hariIni, 'hari ini') < $akhir) {
            $akhir = $hariIni;
        }

        $libur          = HariKerja::himpunanLibur($hariLibur);
        $sakitBerurutan = self::sakitSebelumPeriode($periode, $akhir, $harian, $libur);
        $jumlah         = array_fill_keys(self::KATEGORI, 0);
        $rincian        = array_fill_keys(self::KOLOM, 0);
        $hasilHarian    = [];
        $akhirMasihTb   = false;
        $hariKerja      = 0;
        $presensi       = 0;

        foreach (HariKerja::kalender($periode->awal, $akhir) as $tanggal) {
            $input = $harian[$tanggal] ?? [];
            $tb    = StatusHarian::tugasBelajar($input) !== null;
            $kerja = HariKerja::hariKerja($tanggal, $libur);

            // `$endDateIsTB` (:12726-12735): TB pada tanggal apa pun → true; hari kerja tanpa TB → false.
            if ($tb) {
                $akhirMasihTb = true;
            } elseif ($kerja) {
                $akhirMasihTb = false;
            }
            if (! $kerja) {
                continue;
            }
            $hariKerja++;

            if (! $pegawai->dalamMasaKerja($tanggal)) {
                $hasilHarian[$tanggal] = new HasilHarian('di_luar_masa_kerja');

                continue;
            }
            if ($tb) {
                // TB tidak memutus urutan cuti sakit (:12761-12773).
                $hasilHarian[$tanggal] = new HasilHarian('tugas_belajar');

                continue;
            }

            // Urutan cuti sakit per hari kerja: naik pada cuti sakit (:12798), reset hanya pada baris presensi
            // (:12849). Cuti jenis lain dan konket tidak memutus urutan.
            $jenisCuti = StatusHarian::jenisCuti($input);
            if ($jenisCuti === 'sakit') {
                $sakitBerurutan++;
            } elseif ($jenisCuti === null && ! StatusHarian::konketBebas($input)) {
                $sakitBerurutan = 0;
            }

            $hasil                 = StatusHarian::evaluasi($tanggal, $input, $config, $sakitBerurutan);
            $hasilHarian[$tanggal] = $hasil;
            $presensi += $hasil->potongan;

            foreach ($hasil->kategori as $kategori) {
                $jumlah[$kategori]++;
            }

            foreach ($hasil->rincian as $kolom => $nilai) {
                $rincian[$kolom] += $nilai;
            }
        }

        $cuti          = PotonganCutiPeriode::untuk($periode, $harian, $pegawai->jumlahAnak, $config);
        $skp           = $pegawai->potonganSkp($config);
        $alasan        = null;
        $totalSkp      = 0;
        $totalAkhir    = $presensi;
        $totalPresensi = $presensi;

        if ($akhirMasihTb) {
            [$alasan, $totalPresensi, $totalAkhir] = ['pemutihan_tb', 0, 0];
        } elseif ($cuti['besar'] !== null) {
            [$alasan, $totalPresensi, $totalAkhir] = ['cuti_besar', $cuti['besar'], $cuti['besar']];
        } elseif ($cuti['melahirkan'] !== null) {
            [$alasan, $totalPresensi, $totalAkhir] = ['cuti_melahirkan', 0, $cuti['melahirkan']];
        } elseif (self::cutiSepanjangPeriode($periode, $harian, $libur, $pegawai)) {
            $alasan = 'cuti_sepanjang_periode';
        } else {
            $totalSkp   = $skp;
            $totalAkhir = $presensi + $skp;
        }

        return new HasilTukin($periode, $akhir, $hariKerja, $jumlah, $rincian, $presensi, $totalPresensi, $totalSkp, $totalAkhir, $alasan, $hasilHarian);
    }

    /**
     * Urutan cuti sakit dari periode sebelumnya (`TOTAL CUTI SAKIT BERURUT PREVIOUS MONTH`, :12686-12697). Periode
     * sebelumnya selalu 16 dua bulan lalu s.d. 15 bulan lalu terhadap bulan akhir periode (:11890-11891). Urutan hanya
     * dibawa bila hari kerja terakhir periode sebelumnya **dan** hari kerja pertama periode ini cuti sakit; di dalam
     * periode sebelumnya hari kerja apa pun selain cuti sakit memutus urutan.
     *
     * @param array<string, array<string, mixed>> $harian
     * @param array<string, true>                 $libur
     */
    private static function sakitSebelumPeriode(PeriodeTukin $periode, string $akhir, array $harian, array $libur): int
    {
        [$tahun, $bulan]   = TanggalBisnis::urai($periode->akhir);
        [$tahun1, $bulan1] = self::bulanSebelumnya($tahun, $bulan);
        [$tahun2, $bulan2] = self::bulanSebelumnya($tahun1, $bulan1);

        $hariKerjaLalu = array_values(array_filter(
            HariKerja::kalender(TanggalBisnis::susun($tahun2, $bulan2, 16), TanggalBisnis::susun($tahun1, $bulan1, 15)),
            static fn (string $tanggal): bool => HariKerja::hariKerja($tanggal, $libur),
        ));
        $pertama = null;

        foreach (HariKerja::kalender($periode->awal, $akhir) as $tanggal) {
            if (HariKerja::hariKerja($tanggal, $libur)) {
                $pertama = $tanggal;
                break;
            }
        }
        $sakit = static fn (?string $tanggal): bool => $tanggal !== null && StatusHarian::jenisCuti($harian[$tanggal] ?? []) === 'sakit';
        if ($hariKerjaLalu === [] || ! $sakit($hariKerjaLalu[count($hariKerjaLalu) - 1]) || ! $sakit($pertama)) {
            return 0;
        }
        $urutan = 0;

        foreach ($hariKerjaLalu as $tanggal) {
            $urutan = $sakit($tanggal) ? $urutan + 1 : 0;
        }

        return $urutan;
    }

    /**
     * Semua hari kerja periode (penuh, tidak dipotong `hariIni`) dalam masa kerja adalah cuti jenis apa pun
     * (:12664-12680). Tanpa hari kerja dalam masa kerja juga dianggap sepanjang periode (`0 == 0` di legacy).
     *
     * @param array<string, array<string, mixed>> $harian
     * @param array<string, true>                 $libur
     */
    private static function cutiSepanjangPeriode(PeriodeTukin $periode, array $harian, array $libur, DataPegawaiTukin $pegawai): bool
    {
        foreach (HariKerja::kalender($periode->awal, $periode->akhir) as $tanggal) {
            if (HariKerja::hariKerja($tanggal, $libur) && $pegawai->dalamMasaKerja($tanggal)
                && StatusHarian::jenisCuti($harian[$tanggal] ?? []) === null) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array{0: int, 1: int}
     */
    private static function bulanSebelumnya(int $tahun, int $bulan): array
    {
        return $bulan === 1 ? [$tahun - 1, 12] : [$tahun, $bulan - 1];
    }
}
