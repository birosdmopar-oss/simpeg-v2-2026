<?php

declare(strict_types=1);

namespace App\Libraries\Kepegawaian\Kalkulasi;

/**
 * CR-025 — ID master `jenis_kp` yang di-hard-code kode legacy [K]. Impor wajib menyalin ID legacy apa adanya (G-04 P12,
 * dokumen `docs/db-review/G-04-G-05-pangkat-pendidikan-schema.md` 6.4).
 *
 * - 1 Pengangkatan CPNS, 2 Pengangkatan PNS: `libraries/hr/rwy/L_kp.php:474-475`, `libraries/hr/L_employee.php:10021-10031`.
 * - 3 Reguler: `controllers/hr/services/Siasn.php:2457-2458`.
 * - 4 Pilihan: `libraries/hr/L_employee.php:2647` (label KP pilihan di `get_all_kp`; belum tercatat di G-04 6.4).
 * - 5 Penyesuaian Ijazah: `libraries/hr/L_employee.php:10033`.
 * - 6 namanya belum diketahui (`siasn_sync/Rw_gol.php:98`, G-04 6.4); tidak diberi konstanta.
 */
final class JenisKp
{
    public const PENGANGKATAN_CPNS  = 1;
    public const PENGANGKATAN_PNS   = 2;
    public const REGULER            = 3;
    public const PILIHAN            = 4;
    public const PENYESUAIAN_IJAZAH = 5;

    /**
     * Jenis KP yang TMT-nya tidak terikat periode 1 April/1 Oktober: pengangkatan CPNS/PNS mengikuti tanggal SK
     * pengangkatan (bukti TMT CPNS 1 Maret: legacy `controllers/Tester.php:4283`). Usulan CR-025 (pertanyaan terbuka #2).
     */
    private const TANPA_KEWAJIBAN_PERIODE = [self::PENGANGKATAN_CPNS, self::PENGANGKATAN_PNS];

    private function __construct()
    {
    }

    /**
     * Apakah TMT KP jenis ini wajib 1 April/1 Oktober (SRS-FR-025). Legacy tidak memvalidasi sama sekali
     * (`L_kp.php:422-536`), jadi validasi ini aturan [V2] yang hanya berlaku untuk input pengguna (K-CR025-10).
     */
    public static function wajibTmtPeriode(int $idJenisKp): bool
    {
        return ! in_array($idJenisKp, self::TANPA_KEWAJIBAN_PERIODE, true);
    }

    /**
     * Masa (tahun) sampai KP reguler berikutnya menurut jenis KP terakhir: 3 tahun setelah Pengangkatan PNS, selain itu 4
     * tahun (legacy `next_kp`, `L_employee.php:1987`).
     */
    public static function masaRegulerBerikutnya(?int $idJenisKpTerakhir): int
    {
        return $idJenisKpTerakhir === self::PENGANGKATAN_PNS
            ? KenaikanPangkat::MASA_SETELAH_PENGANGKATAN_PNS_TAHUN
            : KenaikanPangkat::MASA_REGULER_TAHUN;
    }
}
