<?php

declare(strict_types=1);

namespace App\Libraries\Presensi\Tukin;

use App\Libraries\Kepegawaian\Kalkulasi\TanggalBisnis;
use InvalidArgumentException;

/**
 * Agregasi Tukin satu pegawai × satu periode untuk rekap **Instansi Lain** (IL), mengikuti loop pegawai legacy
 * `laporan_tukin_us_skp_il` (`L_presensi.php:13494-15452`, loop harian `:14565`) — keputusan Horii 30-10 (CR-085). Beda
 * dari {@see KalkulasiTukin} (rekap DH `laporan_tukin_us_skp`), diturunkan dari diff kedua method:
 *
 * 1. **Tanpa blok pegawai baru** (`isPegBaru` hanya di DH :12702-12743): `tmtMasuk`/`lewatiLoopHarian` tidak dipakai.
 * 2. **Potongan cuti per tanggal** (`$listPotonganCuti[nip][tanggal] × 0,2`, :14587-14592) dicek di awal setiap tanggal
 *    loop, sebelum libur/akhir pekan/TB: cuti besar 25 (:14233-14364), cuti melahirkan 40/70/20 bila anak ≥ 3
 *    (:14366-14422), cuti alasan penting `lama_cuti` > 14 hari 50 (:14180-14228, **menimpa** nilai tanggal itu). Masuk
 *    kolom CUTI dan total presensi. Lihat {@see self::jadwalPotonganCuti()}.
 * 3. **Tanpa** pengganti periode DH (cuti besar 5/5/5 menggantikan presensi, cuti melahirkan 40/70/80 menggantikan total,
 *    cuti sepanjang periode → SKP 0); tidak ada `cutiFullPeriode`.
 * 4. **Akhir loop** (:15008-15014): `total_skp = endDateIsTB ? 0 : potonganSKP`, menimpa `total_skp = 0` dari hari TB /
 *    cuti besar / cuti melahirkan; total presensi **tidak** dinolkan saat TB di hari kerja terakhir.
 * 5. **Urutan cuti sakit** (`$list_LC`/`$count_CSakit`): diputus oleh hari cuti jenis lain, konket, dan presensi/TK
 *    (:14670, :14772, :14803); TB, libur, akhir pekan tidak memutus. Bawaan periode sebelumnya hanya dari cuti sakit
 *    yang `MONTH(tgl_mulai)` atau `MONTH(tgl_akhir)` ∈ {bulan(hari kerja terakhir periode lalu − 1 bulan), bulan(hari kerja
 *    pertama periode)} (:13872-13890, `$listCS`), dan hanya bila hari kerja terakhir periode lalu & hari kerja pertama
 *    periode ini cuti sakit (:14527-14553).
 * 6. Sama dengan DH: dipotong ke hari ini (`de_tukin`), libur/akhir pekan dilewati, precedence TB → cuti → konket →
 *    presensi, aturan TL/PSW/TPM/TPP/TK (`StatusHarian`), cuti sakit hari ke-15+ 0,2, SKP bawaan 32 (:14491).
 *
 * [V2] tetap: `tmtKeluar` (hari ≥ TMT keluar di luar masa kerja; potongan cuti pada hari itu tidak dikenakan).
 */
final class KalkulasiTukinIl
{
    private const KATEGORI = ['TL1', 'TL2', 'TL3', 'PSW1', 'PSW2', 'PSW3', 'TPM', 'TPP', 'TK', 'CS'];
    private const KOLOM    = ['TL', 'PSW', 'TK', 'TA', 'CUTI'];

    /**
     * @param list<string>                        $hariLibur libur yang mencakup periode sebelumnya s.d. akhir periode
     *                                                       **dan** rentang penuh cuti alasan penting (jadwal potong)
     * @param array<string, array<string, mixed>> $harian    input per tanggal (lihat `StatusHarian`); cuti wajib membawa
     *                                                       `mulai`/`akhir`/`lama`
     * @param string|null                         $hariIni   tanggal proses; hari setelahnya tidak dihitung (`de_tukin`)
     * @param list<array{id?: int, jenis: string, mulai: string, akhir: string, lama: int}>|null $riwayatCuti riwayat cuti
     *                                                       utuh (`$listCuti`); null → direkonstruksi dari input harian
     *                                                       (riwayat yang semua tanggalnya tertimpa tidak terlihat)
     */
    public static function hitung(
        PeriodeTukin $periode,
        array $hariLibur,
        array $harian,
        KonfigurasiTukin $config,
        ?string $hariIni = null,
        ?DataPegawaiTukin $pegawai = null,
        ?array $riwayatCuti = null,
    ): HasilTukin {
        foreach (array_keys($harian) as $tanggal) {
            TanggalBisnis::wajibValid((string) $tanggal, 'tanggal input harian');
        }
        $pegawai ??= new DataPegawaiTukin();

        $akhir = $periode->akhir;
        if ($hariIni !== null && TanggalBisnis::wajibValid($hariIni, 'hari ini') < $akhir) {
            $akhir = $hariIni;
        }

        $libur                         = HariKerja::himpunanLibur($hariLibur);
        $potonganCuti                  = self::jadwalPotonganCuti($periode->awal, $akhir, $harian, $libur, $pegawai->jumlahAnak, $config, $riwayatCuti);
        [$sakitAktif, $sakitBerurutan] = self::sakitSebelumPeriode($periode, $akhir, $harian, $libur);
        $jumlah                        = array_fill_keys(self::KATEGORI, 0);
        $rincian                       = array_fill_keys(self::KOLOM, 0);
        $hasilHarian                   = [];
        $akhirMasihTb                  = false;
        $hariKerja                     = 0;
        $presensi                      = 0;

        foreach (HariKerja::kalender($periode->awal, $akhir) as $tanggal) {
            $input = $harian[$tanggal] ?? [];
            $tb    = StatusHarian::tugasBelajar($input) !== null;
            $kerja = HariKerja::hariKerja($tanggal, $libur);

            // `$endDateIsTB` (:14574-14583), sama dengan DH.
            if ($tb) {
                $akhirMasihTb = true;
            } elseif ($kerja) {
                $akhirMasihTb = false;
            }

            // Potongan cuti per tanggal (:14587-14592) — sebelum cek libur/akhir pekan/TB.
            if (isset($potonganCuti[$tanggal]) && $pegawai->dalamMasaKerja($tanggal)) {
                $presensi += $potonganCuti[$tanggal];
                $rincian['CUTI'] += $potonganCuti[$tanggal];
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
                $hasilHarian[$tanggal] = new HasilHarian('tugas_belajar');

                continue;
            }

            // Urutan cuti sakit IL (:14655-14670, :14772, :14803): naik pada cuti sakit, putus pada cuti jenis lain, konket, presensi.
            $jenisCuti = StatusHarian::jenisCuti($input);
            if ($jenisCuti === 'sakit') {
                $sakitBerurutan = $sakitAktif ? $sakitBerurutan + 1 : 1;
                $sakitAktif     = true;
            } else {
                $sakitAktif = false;
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

        // Akhir loop (:15008-15014): hanya SKP yang dinolkan bila hari kerja terakhir masih TB.
        $totalSkp = $akhirMasihTb ? 0 : $pegawai->potonganSkp($config);

        return new HasilTukin($periode, $akhir, $hariKerja, $jumlah, $rincian, $presensi, $presensi, $totalSkp, $presensi + $totalSkp, null, $hasilHarian);
    }

    /**
     * Jadwal potongan cuti IL per tanggal dalam basis poin (sudah × 0,2), `$listPotonganCuti` (:14176-14423). Riwayat yang
     * dimuat legacy = cuti status 1 yang beririsan dengan [awal periode, `de_tukin`] (:13903-13908), urut `tgl_mulai`.
     * Riwayat hard-code 2023 (id 5969/6029/5752/5830, :14234-14295) tidak diport: periode < 2024-04 tidak didukung v2.
     *
     * - Cuti besar: jumlah hari kalender `mulai`..`akhir` > 0 → tanggal potong ke-1 = tanggal 1 bulan `mulai` bila hari
     *   `mulai` ≤ ⌊hari dalam bulan / 2⌋, selain itu tanggal 1 bulan berikutnya; ke-2 bila > 35 hari, ke-3 bila > 65 hari
     *   (bulan berikutnya); masing-masing +25.
     * - Cuti melahirkan: anak ≥ 3 dan `lama_cuti` > 0 → tanggal ke-1 sama; +40, ke-2 bila `lama_cuti` ≥ 32 +70, ke-3 bila
     *   ≥ 62 +20.
     * - Cuti alasan penting `lama_cuti` > 14: hari kerja cuti dikelompokkan per bulan; bulan dengan hari kerja terbanyak
     *   (seri → bulan paling awal) → hari kerja pertamanya; nilai tanggal itu **diganti** 50.
     *
     * Sumber riwayat: `$riwayatCuti` (daftar utuh dari adapter, urut `tgl_mulai` lalu id, satu entri per id) atau, bila
     * null, rekonstruksi dari input harian (riwayat bertanggal & jenis identik tergabung, yang tertimpa penuh hilang).
     *
     * @param array<string, array<string, mixed>>                                                 $harian
     * @param array<string, true>                                                                 $libur
     * @param list<array{id?: int, jenis: string, mulai: string, akhir: string, lama: int}>|null $riwayatCuti
     *
     * @return array<string, int>
     */
    public static function jadwalPotonganCuti(string $awal, string $akhir, array $harian, array $libur, int $jumlahAnak, KonfigurasiTukin $config, ?array $riwayatCuti = null): array
    {
        $hasil   = [];
        $riwayat = $riwayatCuti === null ? self::riwayatCuti($harian, $awal, $akhir) : self::saringRiwayat($riwayatCuti, $awal, $akhir);

        foreach ($riwayat as $cuti) {
            if ($cuti['jenis'] === 'alasan_penting') {
                if ($cuti['lama'] > 14) {
                    $tanggal = self::tanggalPotongAlasanPenting($cuti['mulai'], $cuti['akhir'], $libur);

                    if ($tanggal !== null) {
                        $hasil[$tanggal] = $config->potonganHarian('CUTI_ALASAN_PENTING_IL');
                    }
                }

                continue;
            }

            if ($cuti['jenis'] === 'besar') {
                $hari = count(HariKerja::kalender($cuti['mulai'], $cuti['akhir']));

                foreach (self::tanggalPotongBulanan($cuti['mulai'], [true, $hari > 35, $hari > 65]) as $tanggal) {
                    $hasil[$tanggal] = ($hasil[$tanggal] ?? 0) + $config->potonganHarian('CUTI_BESAR_IL');
                }

                continue;
            }

            if ($cuti['jenis'] === 'melahirkan' && $jumlahAnak >= 3 && $cuti['lama'] > 0) {
                foreach (self::tanggalPotongBulanan($cuti['mulai'], [true, $cuti['lama'] >= 32, $cuti['lama'] >= 62]) as $ke => $tanggal) {
                    $hasil[$tanggal] = ($hasil[$tanggal] ?? 0) + $config->potonganHarian('CUTI_MELAHIRKAN_IL_' . ($ke + 1));
                }
            }
        }

        return $hasil;
    }

    /**
     * Tanggal potong bulanan cuti besar/melahirkan IL: ke-1 = tanggal 1 bulan `mulai` (hari ≤ ⌊t/2⌋) atau bulan
     * berikutnya; ke-n berikutnya = tanggal 1 bulan sesudahnya (`date('Y-m-01', strtotime("X +32 days"))`), selama syarat
     * ke-n terpenuhi dan ke-(n−1) ada.
     *
     * @param list<bool> $syarat syarat ke-1, ke-2, ke-3
     *
     * @return list<string>
     */
    private static function tanggalPotongBulanan(string $mulai, array $syarat): array
    {
        [$tahun, $bulan, $hari] = TanggalBisnis::urai($mulai);
        $pertama                = TanggalBisnis::susun($tahun, $bulan, 1);

        if ($hari > intdiv(TanggalBisnis::jumlahHari($tahun, $bulan), 2)) {
            $pertama = TanggalBisnis::tambahBulan($pertama, 1);
        }

        $hasil = [];

        foreach ($syarat as $ke => $boleh) {
            if (! $boleh) {
                break;
            }

            $hasil[] = TanggalBisnis::tambahBulan($pertama, $ke);
        }

        return $hasil;
    }

    /**
     * @param array<string, true> $libur
     */
    private static function tanggalPotongAlasanPenting(string $mulai, string $akhir, array $libur): ?string
    {
        $perBulan = [];

        foreach (HariKerja::kalender($mulai, $akhir) as $tanggal) {
            if (! HariKerja::hariKerja($tanggal, $libur)) {
                continue;
            }

            $kunci = substr($tanggal, 0, 7);
            $perBulan[$kunci] ??= ['tanggal' => $tanggal, 'jumlah' => 0];
            $perBulan[$kunci]['jumlah']++;
        }

        $terpilih  = null;
        $terbanyak = 0;

        foreach ($perBulan as $b) {
            if ($b['jumlah'] > $terbanyak) {
                $terpilih  = $b['tanggal'];
                $terbanyak = $b['jumlah'];
            }
        }

        return $terpilih;
    }

    /**
     * Riwayat utuh dari adapter: beririsan [awal, akhir], urut `mulai` lalu id (stabil), satu entri per id.
     *
     * @param list<array{id?: int, jenis: string, mulai: string, akhir: string, lama: int}> $riwayat
     *
     * @return list<array{jenis: string, mulai: string, akhir: string, lama: int}>
     */
    private static function saringRiwayat(array $riwayat, string $awal, string $akhir): array
    {
        $hasil = [];

        foreach ($riwayat as $i => $r) {
            if (! isset(StatusHarian::JENIS_CUTI[$r['jenis']])) {
                throw new InvalidArgumentException('Jenis cuti tidak dikenal pada riwayat cuti.');
            }

            $mulai  = TanggalBisnis::wajibValid($r['mulai'], 'awal cuti');
            $sampai = TanggalBisnis::wajibValid($r['akhir'], 'akhir cuti');

            if ($mulai <= $akhir && $sampai >= $awal) {
                $kunci         = isset($r['id']) ? 'id' . $r['id'] : 'i' . $i;
                $hasil[$kunci] = ['jenis' => $r['jenis'], 'mulai' => $mulai, 'akhir' => $sampai, 'lama' => $r['lama'], 'urut' => sprintf('%s|%012d|%06d', $mulai, $r['id'] ?? 0, $i)];
            }
        }

        uasort($hasil, static fn (array $a, array $b): int => $a['urut'] <=> $b['urut']);

        return array_values(array_map(static fn (array $r): array => ['jenis' => $r['jenis'], 'mulai' => $r['mulai'], 'akhir' => $r['akhir'], 'lama' => $r['lama']], $hasil));
    }

    /**
     * Riwayat cuti unik dari input harian yang beririsan dengan [awal, akhir], urut `mulai` (legacy `tgl_mulai ASC`).
     *
     * @param array<string, array<string, mixed>> $harian
     *
     * @return list<array{jenis: string, mulai: string, akhir: string, lama: int}>
     */
    private static function riwayatCuti(array $harian, string $awal, string $akhir): array
    {
        $hasil = [];

        foreach ($harian as $input) {
            $jenis = StatusHarian::jenisCuti($input);

            if (! in_array($jenis, ['besar', 'melahirkan', 'alasan_penting'], true)) {
                continue;
            }

            $cuti   = $input['cuti'];
            $mulai  = TanggalBisnis::wajibValid((string) ($cuti['mulai'] ?? ''), "awal cuti {$jenis}");
            $sampai = TanggalBisnis::wajibValid((string) ($cuti['akhir'] ?? ''), "akhir cuti {$jenis}");
            $lama   = $cuti['lama'] ?? null;

            if (! is_int($lama) || $lama < 0) {
                throw new InvalidArgumentException("Cuti {$jenis} wajib memiliki lama berupa bilangan bulat non-negatif.");
            }

            if ($mulai <= $akhir && $sampai >= $awal) {
                $hasil["{$mulai}|{$sampai}|{$jenis}"] = ['jenis' => $jenis, 'mulai' => $mulai, 'akhir' => $sampai, 'lama' => $lama];
            }
        }

        ksort($hasil);

        return array_values($hasil);
    }

    /**
     * Bawaan urutan cuti sakit dari periode sebelumnya IL (:14527-14553): hanya bila hari kerja terakhir periode lalu
     * **dan** hari kerja pertama periode ini cuti sakit menurut `$listCS` (cuti sakit yang bulan mulai/akhirnya ∈
     * {bulan(hari kerja terakhir lalu − 1 bulan), bulan(hari kerja pertama ini)}, :13872-13890); hasil = panjang deret cuti
     * sakit di ujung periode lalu.
     *
     * @param array<string, array<string, mixed>> $harian
     * @param array<string, true>                 $libur
     *
     * @return array{0: bool, 1: int} [aktif, panjang deret]
     */
    private static function sakitSebelumPeriode(PeriodeTukin $periode, string $akhir, array $harian, array $libur): array
    {
        [$tahun, $bulan]   = TanggalBisnis::urai($periode->akhir);
        [$tahun1, $bulan1] = $bulan === 1 ? [$tahun - 1, 12] : [$tahun, $bulan - 1];
        [$tahun2, $bulan2] = $bulan1 === 1 ? [$tahun1 - 1, 12] : [$tahun1, $bulan1 - 1];

        $hariKerjaLalu = array_values(array_filter(
            HariKerja::kalender(TanggalBisnis::susun($tahun2, $bulan2, 16), TanggalBisnis::susun($tahun1, $bulan1, 15)),
            static fn (string $t): bool => HariKerja::hariKerja($t, $libur),
        ));
        $pertama = null;

        foreach (HariKerja::kalender($periode->awal, $akhir) as $t) {
            if (HariKerja::hariKerja($t, $libur)) {
                $pertama = $t;
                break;
            }
        }

        if ($hariKerjaLalu === [] || $pertama === null) {
            return [false, 0];
        }

        $terakhir = $hariKerjaLalu[count($hariKerjaLalu) - 1];
        // `$lastDayHK_PM` − 1 bulan: tanggalnya ≤ 15 sehingga `modify('-1 month')` legacy tidak meluap, cukup bulan − 1.
        $bulanLalu = (int) substr($terakhir, 5, 2);
        $bulanCs   = [$bulanLalu === 1 ? 12 : $bulanLalu - 1, (int) substr($pertama, 5, 2)];
        $sakit     = static function (string $t) use ($harian, $bulanCs): bool {
            $input = $harian[$t] ?? [];

            if (StatusHarian::jenisCuti($input) !== 'sakit') {
                return false;
            }

            $mulai = (int) substr((string) ($input['cuti']['mulai'] ?? ''), 5, 2);
            $akhir = (int) substr((string) ($input['cuti']['akhir'] ?? ''), 5, 2);

            return in_array($mulai, $bulanCs, true) || in_array($akhir, $bulanCs, true);
        };

        if (! $sakit($terakhir) || ! $sakit($pertama)) {
            return [false, 0];
        }

        $urutan = 0;

        foreach ($hariKerjaLalu as $t) {
            $urutan = $sakit($t) ? $urutan + 1 : 0;
        }

        return [true, $urutan];
    }
}
