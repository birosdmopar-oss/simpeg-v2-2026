<?php

declare(strict_types=1);

namespace App\Libraries\Presensi\SlipGaji;

use App\Libraries\Kepegawaian\Kalkulasi\TanggalBisnis;
use App\Libraries\MasterData\HariLiburRules;
use InvalidArgumentException;

/**
 * CR-027 (K-CR027-3) — periode slip gaji (bulan + tahun) sebagai value object.
 *
 * - Kunci API kanonik `YYYY-MM` (G-7, PERBAIKI). Legacy memakai `B-TTTT` dengan `$` yang juga menerima newline di akhir
 *   (`libraries/hr/Lsl_gaji.php:88-106`).
 * - Label "Oktober 2026" [K] (`libraries/hr/Lsl_gaji.php:133`, `views/hr/employee/sl_gaji/_slip_content.php:6-7`),
 *   memakai ulang {@see TanggalBisnis::NAMA_BULAN} (CR-025) yang isinya identik dengan `LIST_MONTHS_LOCAL` legacy
 *   (`config/constants.php:267`).
 */
final readonly class PeriodeSlip
{
    /**
     * Batas data sistem (`buat`); input pengguna dibatasi 1900–2100 (`dariTeks`).
     */
    public const TAHUN_SISTEM_MIN = 1900;

    public const TAHUN_SISTEM_MAX = 9999;

    private function __construct(public int $tahun, public int $bulan)
    {
    }

    /**
     * Periode dari data sistem (DB, hasil aritmetika). Bulan di luar 1–12 atau tahun di luar 1900–9999 →
     * `InvalidArgumentException` (fail loud, K-CR027-5).
     */
    public static function buat(int $tahun, int $bulan): self
    {
        if ($bulan < 1 || $bulan > 12 || $tahun < self::TAHUN_SISTEM_MIN || $tahun > self::TAHUN_SISTEM_MAX) {
            throw new InvalidArgumentException(sprintf('Periode slip tidak valid: tahun %d bulan %d', $tahun, $bulan));
        }

        return new self($tahun, $bulan);
    }

    /**
     * Periode dari masukan pengguna (parameter API). Tepat `YYYY-MM` tanpa trim, bulan `01`–`12`, tahun 1900–2100
     * (`HariLiburRules::YEAR_MIN/MAX`). Kosong/spasi → `periode_wajib` (pola `TanggalBisnis::periksa`); selain itu
     * `periode_tidak_valid`. `nilai` = {@see PeriodeSlip}.
     */
    public static function dariTeks(string $teks): HasilSlip
    {
        if (trim($teks) === '') {
            return HasilSlip::gagal('periode_wajib', 'Periode slip gaji wajib diisi.');
        }

        if (preg_match('/^(\d{4})-(0[1-9]|1[0-2])\z/', $teks, $m) !== 1
            || (int) $m[1] < HariLiburRules::YEAR_MIN || (int) $m[1] > HariLiburRules::YEAR_MAX) {
            return HasilSlip::gagal(
                'periode_tidak_valid',
                sprintf(
                    'Periode slip gaji tidak valid. Gunakan format YYYY-MM dengan tahun %d-%d.',
                    HariLiburRules::YEAR_MIN,
                    HariLiburRules::YEAR_MAX,
                ),
            );
        }

        return HasilSlip::berhasil(new self((int) $m[1], (int) $m[2]));
    }

    /**
     * Kunci kanonik `YYYY-MM` (`2026-10`).
     */
    public function kunci(): string
    {
        return sprintf('%04d-%02d', $this->tahun, $this->bulan);
    }

    /**
     * Label "Oktober 2026" (P-2).
     */
    public function label(): string
    {
        return TanggalBisnis::NAMA_BULAN[$this->bulan] . ' ' . $this->tahun;
    }

    /**
     * Bulan sebelumnya (`2027-01` → `2026-12`); dipakai untuk label uang makan bulan−1 (P-8, pemakaian TUNGGU-BK).
     */
    public function sebelumnya(): self
    {
        return self::dariIndeks($this->indeks() - 1);
    }

    /**
     * Bulan berikutnya (`2026-12` → `2027-01`).
     */
    public function berikutnya(): self
    {
        return self::dariIndeks($this->indeks() + 1);
    }

    /**
     * Indeks bulan absolut `tahun × 12 + (bulan − 1)` untuk perbandingan dan urutan.
     */
    public function indeks(): int
    {
        return $this->tahun * 12 + ($this->bulan - 1);
    }

    public function sama(self $lain): bool
    {
        return $this->indeks() === $lain->indeks();
    }

    private static function dariIndeks(int $indeks): self
    {
        return self::buat(intdiv($indeks, 12), $indeks % 12 + 1);
    }
}
