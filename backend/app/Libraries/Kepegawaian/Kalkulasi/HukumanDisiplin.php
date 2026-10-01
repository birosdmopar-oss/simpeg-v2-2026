<?php

declare(strict_types=1);

namespace App\Libraries\Kepegawaian\Kalkulasi;

use InvalidArgumentException;

/**
 * CR-025 (B-21 "kalkulasi tanggal berakhir hukdis") — tanggal berakhir hukuman disiplin dari masa sanksi.
 *
 * - Rumus [V2] SRS-FR-027: akhir = TMT + masa sanksi (bulan), dijepit akhir bulan (contoh MTC-021: TMT 1 Januari 2026,
 *   masa 12 bulan → 1 Januari 2027).
 * - Masa efektif (K5b, G-06 `docs/db-review/G-06-diklat-hukdis-konket-tanda-jasa-schema.md`): masa per SK diutamakan,
 *   lalu masa bawaan `jenis_hukdis.masa_sanksi_bulan`; keduanya NULL → tidak dihitung, akhir diisi manual (K-CR025-9).
 * - Legacy tidak menghitung: `masa_hukuman` (teks) dan `akhir_hukdis` wajib diisi manual
 *   (`libraries/hr/rwy/L_hukdis.php:400-407`, form `views/hr/employee/rwy/hukdis/form.php:122-131`); SIASN mengisi akhir
 *   dari SK (`controllers/hr/services/Siasn.php:2940-2946`, :3070). Tidak ada masa bawaan per tingkat.
 */
final class HukumanDisiplin
{
    /**
     * Rentang masa sanksi = CHECK `chk_jenis_hukdis_masa_sanksi_bulan` (≥ 1) dan TINYINT UNSIGNED (≤ 255), G-06 D3/D4.
     */
    public const MASA_MIN_BULAN  = 1;
    public const MASA_MAKS_BULAN = 255;

    public const SUMBER_SK     = 'sk';
    public const SUMBER_MASTER = 'master';

    public const LABEL_TMT   = 'TMT hukuman disiplin';
    public const LABEL_AKHIR = 'Tanggal berakhir hukuman disiplin';

    private function __construct()
    {
    }

    /**
     * Masa efektif (bulan): masa SK ?? masa bawaan jenis. `null` = tanpa masa (akhir manual).
     */
    public static function masaBerlaku(?int $masaBulanSk, ?int $masaBulanBawaan): ?int
    {
        return $masaBulanSk ?? $masaBulanBawaan;
    }

    /**
     * Tanggal berakhir otomatis. Tanpa masa → valid dengan `tanggal = null` dan kode `tanpa_masa` (akhir diisi manual
     * bila SK menyebutkannya). `detail`: `masa_bulan`, `sumber_masa` (`sk` / `master`).
     */
    public static function hitungTanggalBerakhir(string $tmt, ?int $masaBulanSk, ?int $masaBulanBawaan): HasilKalkulasi
    {
        $gagal = TanggalBisnis::periksa($tmt, self::LABEL_TMT);

        if ($gagal !== null) {
            return $gagal;
        }

        $masa   = self::masaBerlaku($masaBulanSk, $masaBulanBawaan);
        $detail = [
            'masa_bulan'  => $masa,
            'sumber_masa' => $masa === null ? null : ($masaBulanSk !== null ? self::SUMBER_SK : self::SUMBER_MASTER),
        ];

        if ($masa === null) {
            return HasilKalkulasi::berhasil(
                null,
                $detail,
                'tanpa_masa',
                'Jenis hukuman disiplin ini tidak memiliki masa sanksi. Isi tanggal berakhir secara manual bila SK menyebutkannya.',
            );
        }

        if ($masa < self::MASA_MIN_BULAN || $masa > self::MASA_MAKS_BULAN) {
            return HasilKalkulasi::gagal(
                'masa_sanksi_tidak_valid',
                sprintf('Masa sanksi harus bilangan bulat %d–%d bulan.', self::MASA_MIN_BULAN, self::MASA_MAKS_BULAN),
                $detail,
            );
        }

        return HasilKalkulasi::berhasil(TanggalBisnis::tambahBulan($tmt, $masa), $detail);
    }

    /**
     * Tanggal berakhir yang diisi manual harus setelah TMT (TMT sama → ditolak).
     */
    public static function validasiTanggalBerakhir(string $tmt, string $akhir): HasilKalkulasi
    {
        $gagal = TanggalBisnis::periksa($tmt, self::LABEL_TMT) ?? TanggalBisnis::periksa($akhir, self::LABEL_AKHIR);

        if ($gagal !== null) {
            return $gagal;
        }

        if ($akhir <= $tmt) {
            return HasilKalkulasi::gagal(
                'akhir_tidak_setelah_tmt',
                sprintf('%s harus setelah TMT (%s).', self::LABEL_AKHIR, TanggalBisnis::formatIndonesia($tmt)),
                ['tmt' => $tmt],
            );
        }

        return HasilKalkulasi::berhasil($akhir);
    }

    /**
     * Teks masa untuk kolom teks `masa_hukuman` (pola SIASN `Siasn.php:2940-2946`): 6 → "6 Bulan", 12 → "1 Tahun",
     * 18 → "1 Tahun 6 Bulan".
     */
    public static function masaTeks(int $bulan): string
    {
        if ($bulan < self::MASA_MIN_BULAN) {
            throw new InvalidArgumentException("Masa sanksi harus ≥ 1 bulan: {$bulan}");
        }

        $bagian = [];

        if (intdiv($bulan, 12) > 0) {
            $bagian[] = intdiv($bulan, 12) . ' Tahun';
        }

        if ($bulan % 12 > 0) {
            $bagian[] = $bulan % 12 . ' Bulan';
        }

        return implode(' ', $bagian);
    }
}
