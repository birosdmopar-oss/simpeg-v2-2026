<?php

declare(strict_types=1);

namespace App\Libraries\Presensi\Tukin;

use App\Libraries\Kepegawaian\Kalkulasi\TanggalBisnis;

/**
 * Agregasi Tukin satu pegawai × satu periode, mengikuti loop harian `laporan_tukin` legacy (:4594-5012).
 *
 * Urutan per tanggal kalender: (1) potongan cuti bulanan yang jatuh pada tanggal itu bila belum `isPegTB`;
 * (2) libur/akhir pekan dilewati; (3) tugas belajar → potongan TB sekali per bulan kalender, lalu `isPegTB`;
 * (4) setelah `isPegTB` semua hari berikutnya dikecualikan; (5) cuti → konket → presensi via `StatusHarian`.
 */
final class KalkulasiTukin
{
    private const KATEGORI = ['TL1', 'TL2', 'TL3', 'PSW1', 'PSW2', 'PSW3', 'TPM', 'TPP', 'TK', 'LKH', 'CS'];

    /**
     * @param list<string>                        $hariLibur Tanggal libur; untuk cuti alasan penting/sakit yang dimulai
     *                                                       sebelum periode, sertakan juga libur pada rentang itu.
     * @param array<string, array<string, mixed>> $harian    Input per tanggal (lihat `StatusHarian`). Tanggal sebelum
     *                                                       periode boleh disertakan untuk urutan cuti sakit.
     * @param string|null                         $hariIni   Tanggal proses; hari setelahnya tidak dihitung (legacy :4051).
     */
    public static function hitung(PeriodeTukin $periode, array $hariLibur, array $harian, KonfigurasiTukin $config, ?string $hariIni = null): HasilTukin
    {
        foreach (array_keys($harian) as $tanggal) {
            TanggalBisnis::wajibValid((string) $tanggal, 'tanggal input harian');
        }

        $akhir = $periode->akhir;
        if ($hariIni !== null && TanggalBisnis::wajibValid($hariIni, 'hari ini') < $akhir) {
            $akhir = $hariIni;
        }

        $libur          = HariKerja::himpunanLibur($hariLibur);
        $jadwalCuti     = PotonganCutiBulanan::jadwal($harian, $libur, $config);
        $sakitBerurutan = self::sakitSebelumPeriode($periode->awal, $harian, $libur);
        $jumlah         = array_fill_keys(self::KATEGORI, 0);
        $hasilHarian    = [];
        $tbTerpotong    = [];
        $isPegTb        = false;
        $hariKerja      = 0;
        $presensi       = 0;
        $lkh            = 0;
        $tb             = 0;
        $cuti           = 0;

        foreach (HariKerja::kalender($periode->awal, $akhir) as $tanggal) {
            if (isset($jadwalCuti[$tanggal]) && ! $isPegTb) {
                $cuti += $jadwalCuti[$tanggal];
            }
            if (! HariKerja::hariKerja($tanggal, $libur)) {
                continue;
            }
            $hariKerja++;
            $input = $harian[$tanggal] ?? [];

            $dataTb = StatusHarian::tugasBelajar($input);
            if ($dataTb !== null) {
                $potonganTb = self::potonganTb($tanggal, $dataTb, $tbTerpotong, $config);
                if ($potonganTb > 0) {
                    $tb += $potonganTb;
                    $isPegTb = true;
                }
                $hasilHarian[$tanggal] = new HasilHarian('tugas_belajar');

                continue;
            }
            if ($isPegTb) {
                $hasilHarian[$tanggal] = new HasilHarian('dikecualikan_tb');

                continue;
            }

            // Urutan cuti sakit per hari kerja; reset oleh cuti lain, konket (apa pun affect_tukin), dan presensi.
            $sakitBerurutan = StatusHarian::jenisCuti($input) === 'sakit' ? $sakitBerurutan + 1 : 0;

            $hasil                 = StatusHarian::evaluasi($tanggal, $input, $config, $sakitBerurutan);
            $hasilHarian[$tanggal] = $hasil;
            $presensi += $hasil->potongan;
            $lkh += $hasil->potonganLkh;

            foreach ($hasil->kategori as $kategori) {
                $jumlah[$kategori]++;
            }
        }

        return new HasilTukin($periode, $akhir, $hariKerja, $jumlah, $presensi, $lkh, $tb, $cuti, $hasilHarian);
    }

    /**
     * TB dipotong sekali per riwayat TB per bulan kalender, pada hari kerja TB pertama bulan itu. Bulan mulai TB
     * bebas potongan bila TB dimulai setelah tanggal 1, kecuali masa perpanjangan (legacy :4136-4178, :4645-4652).
     *
     * @param array<string, mixed> $dataTb
     * @param array<string, true>  $tbTerpotong
     */
    private static function potonganTb(string $tanggal, array $dataTb, array &$tbTerpotong, KonfigurasiTukin $config): int
    {
        $mulai = isset($dataTb['mulai']) ? TanggalBisnis::wajibValid((string) $dataTb['mulai'], 'awal tugas belajar') : null;
        $bulan = substr($tanggal, 0, 7);
        $kunci = (string) ($dataTb['id'] ?? $mulai ?? 'tb') . '|' . $bulan;
        if (isset($tbTerpotong[$kunci])) {
            return 0;
        }
        $tbTerpotong[$kunci] = true;

        $perpanjangan = ($dataTb['perpanjangan'] ?? false) === true;
        if (! $perpanjangan && $mulai !== null && substr($mulai, 0, 7) === $bulan && (int) substr($mulai, 8, 2) > 1) {
            return 0;
        }

        return $config->tarif('TB');
    }

    /**
     * Urutan cuti sakit yang sudah berjalan sebelum periode, dari input harian sebelum `awal` (legacy menghitung ulang
     * hari kerja bulan sebelumnya, :4573-4590). Hari TB tidak memutus urutan, sama seperti loop utama.
     *
     * @param array<string, array<string, mixed>> $harian
     * @param array<string, true>                 $libur
     */
    private static function sakitSebelumPeriode(string $awal, array $harian, array $libur): int
    {
        $sebelum = array_filter(array_keys($harian), static fn (int|string $tanggal): bool => (string) $tanggal < $awal);
        if ($sebelum === []) {
            return 0;
        }
        $urutan = 0;

        foreach (HariKerja::kalender((string) min($sebelum), HariKerja::kemarin($awal)) as $tanggal) {
            if (! HariKerja::hariKerja($tanggal, $libur)) {
                continue;
            }
            $input = $harian[$tanggal] ?? [];
            if (StatusHarian::tugasBelajar($input) !== null) {
                continue;
            }
            $urutan = StatusHarian::jenisCuti($input) === 'sakit' ? $urutan + 1 : 0;
        }

        return $urutan;
    }
}
