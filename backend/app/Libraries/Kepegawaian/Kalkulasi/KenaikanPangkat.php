<?php

declare(strict_types=1);

namespace App\Libraries\Kepegawaian\Kalkulasi;

use InvalidArgumentException;

/**
 * CR-025 (B-21 "kalkulasi periode KP") — periode kenaikan pangkat 1 April/1 Oktober dan proyeksi KP berikutnya.
 *
 * - Validasi TMT periode = aturan [V2] SRS-FR-025 (tanggal lain ditolak). Legacy tidak memvalidasinya
 *   (`libraries/hr/rwy/L_kp.php:422-536`), dan TMT pengangkatan CPNS/PNS sah di luar periode
 *   (`controllers/Tester.php:4283`), sehingga kewajiban per jenis lewat {@see JenisKp::wajibTmtPeriode()} dan hanya untuk
 *   input pengguna (K-CR025-10).
 * - Proyeksi [K] setia legacy `next_kp` (`libraries/hr/L_employee.php:1882-2054`): reguler :1987-1998, percepatan
 *   :2005-2041 (K-CR025-6).
 */
final class KenaikanPangkat
{
    /**
     * Bulan periode KP, urut naik, semuanya tanggal 1 (SRS-FR-025; legacy `L_employee.php:1990-1998`).
     */
    public const BULAN_PERIODE = [4, 10];

    /**
     * Masa KP reguler berikutnya (legacy `L_employee.php:1987`).
     */
    public const MASA_REGULER_TAHUN                  = 4;
    public const MASA_SETELAH_PENGANGKATAN_PNS_TAHUN = 3;

    /**
     * KP pilihan/percepatan: TMT jabatan struktural pertama + 1 tahun (legacy `L_employee.php:2012`).
     */
    public const MASA_PERCEPATAN_TAHUN = 1;

    public const LABEL_TMT          = 'TMT KP';
    public const LABEL_TMT_TERAKHIR = 'TMT KP terakhir';
    public const LABEL_TMT_JABATAN  = 'TMT jabatan struktural';

    private function __construct()
    {
    }

    /**
     * Tanggal 1 pada salah satu {@see self::BULAN_PERIODE}. Tanggal tidak valid → false.
     */
    public static function isTanggalPeriode(string $tanggal): bool
    {
        if (! TanggalBisnis::valid($tanggal)) {
            return false;
        }

        [, $bulan, $hari] = TanggalBisnis::urai($tanggal);

        return $hari === 1 && in_array($bulan, self::BULAN_PERIODE, true);
    }

    /**
     * Tanggal periode terkecil yang ≥ `$tanggal` (tanggal periode dikembalikan apa adanya).
     */
    public static function periodeBerikutnya(string $tanggal): string
    {
        [$tahun] = TanggalBisnis::urai($tanggal);

        foreach (self::BULAN_PERIODE as $bulan) {
            $periode = TanggalBisnis::susun($tahun, $bulan, 1);

            if ($periode >= $tanggal) {
                return $periode;
            }
        }

        return TanggalBisnis::susun($tahun + 1, self::BULAN_PERIODE[0], 1);
    }

    /**
     * Tanggal periode terbesar yang ≤ `$tanggal`.
     */
    public static function periodeSebelumnya(string $tanggal): string
    {
        [$tahun] = TanggalBisnis::urai($tanggal);

        foreach (array_reverse(self::BULAN_PERIODE) as $bulan) {
            $periode = TanggalBisnis::susun($tahun, $bulan, 1);

            if ($periode <= $tanggal) {
                return $periode;
            }
        }

        return TanggalBisnis::susun($tahun - 1, self::BULAN_PERIODE[count(self::BULAN_PERIODE) - 1], 1);
    }

    /**
     * Validasi TMT KP masukan pengguna (SRS-FR-025). Gagal `tmt_kp_bukan_periode` membawa periode terdekat di `detail`
     * (`periode_sebelumnya`, `periode_berikutnya`) dan di pesan. `$wajibPeriode = false` (pengangkatan CPNS/PNS) hanya
     * memeriksa format tanggal.
     */
    public static function validasiTmt(string $tmt, bool $wajibPeriode = true): HasilKalkulasi
    {
        $gagal = TanggalBisnis::periksa($tmt, self::LABEL_TMT);

        if ($gagal !== null) {
            return $gagal;
        }

        if (! $wajibPeriode || self::isTanggalPeriode($tmt)) {
            return HasilKalkulasi::berhasil($tmt);
        }

        $sebelumnya  = self::periodeSebelumnya($tmt);
        $berikutnya  = self::periodeBerikutnya($tmt);
        $daftarBulan = array_map(
            static fn (int $bulan): string => '1 ' . TanggalBisnis::NAMA_BULAN[$bulan],
            self::BULAN_PERIODE,
        );

        return HasilKalkulasi::gagal(
            'tmt_kp_bukan_periode',
            sprintf(
                'TMT kenaikan pangkat harus tanggal %s. Periode terdekat: %s atau %s.',
                self::gabungAtau($daftarBulan),
                TanggalBisnis::formatIndonesia($sebelumnya),
                TanggalBisnis::formatIndonesia($berikutnya),
            ),
            ['periode_sebelumnya' => $sebelumnya, 'periode_berikutnya' => $berikutnya],
        );
    }

    /**
     * Proyeksi KP reguler berikutnya, setara legacy `L_employee.php:1987-1998`: hanya bulan TMT yang dipakai (bulan ≤ 4 →
     * 1 April, 5–10 → 1 Oktober, 11–12 → 1 April tahun berikutnya, setelah ditambah `$masaTahun`). Akibatnya TMT
     * 15-04-2022 diproyeksikan ke 01-04-2026. `$masaTahun` dari {@see JenisKp::masaRegulerBerikutnya()}.
     */
    public static function proyeksiReguler(string $tmtKpTerakhir, int $masaTahun = self::MASA_REGULER_TAHUN): HasilKalkulasi
    {
        if ($masaTahun < 1) {
            throw new InvalidArgumentException("Masa KP reguler harus ≥ 1 tahun: {$masaTahun}");
        }

        $gagal = TanggalBisnis::periksa($tmtKpTerakhir, self::LABEL_TMT_TERAKHIR);

        if ($gagal !== null) {
            return $gagal;
        }

        $tanggal = self::periodeBerikutnya(TanggalBisnis::tambahTahun(TanggalBisnis::awalBulan($tmtKpTerakhir), $masaTahun));

        return HasilKalkulasi::berhasil($tanggal, ['masa_tahun' => $masaTahun]);
    }

    /**
     * Proyeksi KP pilihan/percepatan (legacy `L_employee.php:2005-2041`): TMT jabatan struktural pertama + 1 tahun, lalu
     * periode terdekat berdasarkan **tanggal** (≤ 1 April → 1 April, ≤ 1 Oktober → 1 Oktober, selain itu 1 April tahun
     * berikutnya). Syarat kelayakan (golongan di bawah minimum eselon) dan perbandingan dengan jalur reguler diputuskan
     * di H-08/B-19.
     */
    public static function proyeksiPercepatan(string $tmtJabatanStruktural): HasilKalkulasi
    {
        $gagal = TanggalBisnis::periksa($tmtJabatanStruktural, self::LABEL_TMT_JABATAN);

        if ($gagal !== null) {
            return $gagal;
        }

        $tanggal = self::periodeBerikutnya(TanggalBisnis::tambahTahun($tmtJabatanStruktural, self::MASA_PERCEPATAN_TAHUN));

        return HasilKalkulasi::berhasil($tanggal, ['masa_tahun' => self::MASA_PERCEPATAN_TAHUN]);
    }

    /**
     * ["a"] → "a"; ["a", "b"] → "a atau b"; ["a", "b", "c"] → "a, b atau c".
     *
     * @param list<string> $bagian
     */
    private static function gabungAtau(array $bagian): string
    {
        $terakhir = array_pop($bagian);

        return $bagian === [] ? (string) $terakhir : implode(', ', $bagian) . ' atau ' . $terakhir;
    }
}
