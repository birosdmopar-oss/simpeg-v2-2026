<?php

declare(strict_types=1);

namespace App\Libraries\Integrasi\Stat;

/**
 * MAKE-061 (H-08, F6-09) — deteksi rekomendasi kenaikan pangkat, port PERSIS [K] `libraries/hr/L_employee.php`
 * `get_all_kp()` :2416-2720 (dipakai `services/s_stat/rekomendasi_kp` format SIMPEG dan `rekomendasi_kp_uns` format
 * MuDig). Murni: "hari ini" dan tabel master pangkat diinjeksi; tanpa DB/jam.
 *
 * Aturan [K] (Lampiran A.2 PRD Fase 6):
 * - Reguler (`id_jenis_kp` "3"): tahun TMT pangkat terakhir (jenis KP 2 → dikurangi 1) berjarak kelipatan 4 tahun dari
 *   tahun ini dan < tahun ini; pangkat < plafon pendidikan (`PLAFON_REGULER`), atau pejabat E.I/E.II/E.III di bawah plafon
 *   eselonnya. Periode: bulan TMT ≤ 4 → 1 April tahun ini; 5–10 → 1 Oktober; > 10 → 1 April tahun depan (:2599-2608).
 * - Percepatan struktural (`id_jenis_kp` "4" "Pilihan"): pejabat E.IV < III/c, E.III < IV/a, E.II/E.I < IV/c; TMT jabatan
 *   struktural (eselon itu, status 1) paling awal + 1 tahun, dibulatkan ke periode 1 April / 1 Oktober berikutnya; dipakai
 *   bila lebih awal dari tanggal reguler, atau bila reguler tidak ada (:2614-2658).
 * - Anomali legacy yang DIPERTAHANKAN (keputusan reviewer 05-11-2026, prinsip "ikut legacy"): `[K] perilaku legacy
 *   get_all_kp` — `gol_ruang` kosong/tidak dikenal memberi urutan `null`, dan `null < n` bernilai true untuk n > 0,
 *   sehingga pegawai tanpa golongan ikut terdeteksi reguler bila plafon pendidikannya ada, dan ikut percepatan bila
 *   bereselon; TMT pangkat kosong dibaca sebagai 1970 ({@see KalenderStat::tahunLegacy()}). Tes mengunci keduanya.
 * - Kolom turunan MuDig (`tmt_kp_new`, `mker_*_new`, `pangkat_new` dari `pangkat.order + 1`) :2663-2713: masa kerja baru =
 *   masa kerja lama + selisih TMT lama → TMT baru; bila masa kerja lama 0, dihitung dari tahun-bulan CPNS yang dibaca dari
 *   digit NIP ke-9..14 (:2688-2695).
 *
 * Perbedaan dengan `Kepegawaian\Kalkulasi\KenaikanPangkat` (proyeksi per pegawai untuk input riwayat: periode 1 April /
 * 1 Oktober dari TMT terakhir + 4 tahun, tanpa plafon pendidikan dan tanpa siklus `% 4` terhadap tahun berjalan) dicatat di
 * README; keduanya sengaja tidak disamakan (PRD §7.6).
 */
final class RekomendasiKp
{
    public const JENIS_REGULER = '3';

    public const JENIS_PILIHAN = '4';

    /** Urutan pangkat legacy `$arrPangkat` (:2446-2450). */
    public const URUT_PANGKAT = [
        'CPNS I/a'   => 0, 'CPNS I/b' => 1, 'CPNS I/c' => 2, 'CPNS II/a' => 3, 'CPNS II/b' => 4, 'CPNS II/c' => 5,
        'CPNS III/a' => 6, 'CPNS III/b' => 7,
        'I/a'        => 8, 'I/b' => 9, 'I/c' => 10, 'I/d' => 11,
        'II/a'       => 12, 'II/b' => 13, 'II/c' => 14, 'II/d' => 15,
        'III/a'      => 16, 'III/b' => 17, 'III/c' => 18, 'III/d' => 19,
        'IV/a'       => 20, 'IV/b' => 21, 'IV/c' => 22, 'IV/d' => 23, 'IV/e' => 24,
    ];

    /** Plafon pangkat reguler per jenjang pendidikan singkat (:2452-2455). */
    public const PLAFON_REGULER = [
        'SD'   => 'II/a', 'SLTP' => 'II/c', 'SLTA' => 'III/b', 'D.I' => 'III/b', 'D.II' => 'III/b', 'D.III' => 'III/c',
        'D.IV' => 'III/d', 'S.1' => 'III/d', 'S.2' => 'IV/a', 'Dokter' => 'IV/a', 'Apoteker' => 'IV/a', 'S.3' => 'IV/b',
    ];

    public const PLAFON_ESELON = [
        'E.III' => ['D.IV' => 'IV/b', 'S.1' => 'IV/b', 'S.2' => 'IV/b', 'Dokter' => 'IV/b', 'Apoteker' => 'IV/b', 'S.3' => 'IV/b'],
        'E.II'  => ['D.IV' => 'IV/d', 'S.1' => 'IV/d', 'S.2' => 'IV/d', 'Dokter' => 'IV/d', 'Apoteker' => 'IV/d', 'S.3' => 'IV/d'],
        'E.I'   => ['D.IV' => 'IV/e', 'S.1' => 'IV/e', 'S.2' => 'IV/e', 'Dokter' => 'IV/e', 'Apoteker' => 'IV/e', 'S.3' => 'IV/e'],
    ];

    /** Pangkat minimal per eselon untuk percepatan (:2466-2469). */
    public const MIN_ESELON = ['E.IV' => 'III/c', 'E.III' => 'IV/a', 'E.II' => 'IV/c', 'E.I' => 'IV/c'];

    public const SUB_GROUP_ESELON = ['E.I' => 1, 'E.II' => 2, 'E.III' => 3, 'E.IV' => 4];

    /**
     * @param array<int, array{id_pangkat: int|string, pangkat: string, gol_ruang: string}> $pangkatPerOrder master
     *                                                                                                  `pangkat` status 1 dikunci `order`
     */
    public function __construct(private readonly string $hariIni, private readonly array $pangkatPerOrder = [])
    {
        KalenderStat::parse($hariIni);
    }

    /**
     * Deteksi untuk satu pegawai (:2518-2660).
     *
     * @param array<string, mixed> $p           `gol_ruang`, `tmtsk_pangkat`, `id_jenis_kp`, `strata`, `eselon` ('' | E.I..E.IV)
     * @param string|null          $tmtStruktural TMT jabatan struktural (eselon pegawai, status 1) paling awal, '' / null bila tidak ada
     *
     * @return array{tgl_kp: string, id_jenis_kp: string|null, jenis_kp: string|null} `tgl_kp` '' = tidak direkomendasikan
     */
    public function deteksi(array $p, ?string $tmtStruktural): array
    {
        $golRuang = (string) ($p['gol_ruang'] ?? '');
        $tmtsk    = (string) ($p['tmtsk_pangkat'] ?? '');
        $strata   = (string) ($p['strata'] ?? '');
        $eselon   = (string) ($p['eselon'] ?? '');
        $urut     = self::URUT_PANGKAT[$golRuang] ?? null;
        $tahunNow = (int) substr($this->hariIni, 0, 4);

        $tglKp     = '';
        $idJenisKp = null;
        $jenisKp   = null;

        // --- Reguler ---
        $tahunPangkat = KalenderStat::tahunLegacy($tmtsk);

        if ((string) ($p['id_jenis_kp'] ?? '') === '2') {
            $tahunPangkat--;
        }

        $tahunNaik = ($tahunNow - $tahunPangkat) % 4 === 0;

        if ($tahunNaik && $tahunPangkat < $tahunNow) {
            $maksReguler = self::PLAFON_REGULER[$strata] ?? '';
            $nilaiMaks   = self::URUT_PANGKAT[$maksReguler] ?? 0;
            $naik        = $urut < $nilaiMaks; // [K] perilaku legacy get_all_kp: null < n (gol_ruang tak dikenal)

            if (! $naik && isset(self::PLAFON_ESELON[$eselon]) && array_key_exists($strata, self::PLAFON_ESELON[$eselon])) {
                $naik = $urut < self::URUT_PANGKAT[self::PLAFON_ESELON[$eselon][$strata]];
            }

            if ($naik) {
                $idJenisKp = self::JENIS_REGULER;
                $jenisKp   = 'Reguler';
                $bulan     = KalenderStat::bulanLegacy($tmtsk);

                if ($bulan <= 4) {
                    $tglKp = sprintf('%04d-04-01', $tahunNow);
                } elseif ($bulan <= 10) {
                    $tglKp = sprintf('%04d-10-01', $tahunNow);
                } else {
                    $tglKp = sprintf('%04d-04-01', $tahunNow + 1);
                }
            }
        }

        // --- Percepatan struktural ---
        $percepatan = $golRuang !== ''
            && isset(self::MIN_ESELON[$eselon])
            && $urut < self::URUT_PANGKAT[self::MIN_ESELON[$eselon]]; // [K] perilaku legacy get_all_kp

        if ($percepatan && is_string($tmtStruktural) && KalenderStat::valid($tmtStruktural)) {
            $dtKp  = KalenderStat::parse($tmtStruktural)->modify('+1 year');
            $tahun = (int) $dtKp->format('Y');
            $per1  = KalenderStat::parse(sprintf('%04d-04-01', $tahun));
            $per2  = KalenderStat::parse(sprintf('%04d-10-01', $tahun));

            if ($dtKp <= $per1) {
                $tglPerc = $per1->format('Y-m-d');
            } elseif ($dtKp <= $per2) {
                $tglPerc = $per2->format('Y-m-d');
            } else {
                $tglPerc = $per1->modify('+1 year')->format('Y-m-d');
            }

            if ($tglKp === '' || $tglPerc < $tglKp) {
                $idJenisKp = self::JENIS_PILIHAN;
                $jenisKp   = 'Pilihan';
                $tglKp     = $tglPerc;
            }
        }

        return ['tgl_kp' => $tglKp, 'id_jenis_kp' => $idJenisKp, 'jenis_kp' => $jenisKp];
    }

    /**
     * Kolom turunan format MuDig (:2663-2713) untuk pegawai yang terdeteksi (`$tglKp` tidak kosong).
     *
     * @param array<string, mixed> $m `nip`, `tmt_kp_old`, `mker_th_old`, `mker_bl_old`, `order_pangkat_old`
     *
     * @return array{mker_th_new: int, mker_bl_new: int, mker_new: string, id_pangkat_new: int|string, pangkat_new: string, gol_ruang_new: string, gaji_pokok_new: string}
     */
    public function turunanMudig(array $m, string $tglKp): array
    {
        $dtBaru = KalenderStat::parse($tglKp);
        $thLama = (int) ($m['mker_th_old'] ?? 0);
        $blLama = (int) ($m['mker_bl_old'] ?? 0);
        $hasil  = ['mker_th_new' => 0, 'mker_bl_new' => 0, 'mker_new' => '', 'id_pangkat_new' => '', 'pangkat_new' => '', 'gol_ruang_new' => '', 'gaji_pokok_new' => ''];

        if ($thLama > 0 || $blLama > 0) {
            $dtLama = KalenderStat::tanggalAtauHariIni((string) ($m['tmt_kp_old'] ?? ''), $this->hariIni);
            $total  = ($thLama * 12 + $blLama) + KalenderStat::selisihBulan($dtLama, $dtBaru);
            $th     = intdiv($total, 12);
            $bl     = $total - $th * 12;
        } else {
            $dtCpns = self::tanggalCpnsDariNip((string) ($m['nip'] ?? ''), $this->hariIni);
            $th     = $dtCpns->diff($dtBaru)->y;
            $bl     = $dtCpns->diff($dtBaru)->m;
        }

        $hasil['mker_th_new'] = $th;
        $hasil['mker_bl_new'] = $bl;
        $hasil['mker_new']    = "{$th} Tahun {$bl} Bulan";

        $orderBaru = (int) ($m['order_pangkat_old'] ?? 0) + 1;

        if (isset($this->pangkatPerOrder[$orderBaru])) {
            $hasil['id_pangkat_new'] = $this->pangkatPerOrder[$orderBaru]['id_pangkat'];
            $hasil['pangkat_new']    = $this->pangkatPerOrder[$orderBaru]['pangkat'];
            $hasil['gol_ruang_new']  = $this->pangkatPerOrder[$orderBaru]['gol_ruang'];
            $hasil['gaji_pokok_new'] = '';
        }

        return $hasil;
    }

    /**
     * Tahun-bulan CPNS dari digit NIP ke-9..12 (tahun) dan 13..14 (bulan), tanggal 1 [K :2688-2691]. NIP yang tidak
     * membentuk tanggal sah → hari ini (legacy melempar fatal `DateTime`; [V2] dijaga agar satu baris rusak tidak
     * menggagalkan seluruh respons).
     */
    public static function tanggalCpnsDariNip(string $nip, string $hariIni): \DateTimeImmutable
    {
        $kandidat = substr($nip, 8, 4) . '-' . substr($nip, 12, 2) . '-01';

        return KalenderStat::tanggalAtauHariIni($kandidat, $hariIni);
    }
}
