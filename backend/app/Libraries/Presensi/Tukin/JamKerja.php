<?php

declare(strict_types=1);

namespace App\Libraries\Presensi\Tukin;

use App\Libraries\Kepegawaian\Kalkulasi\TanggalBisnis;
use InvalidArgumentException;

/**
 * Jam kerja standar dan selisih menit (rekap legacy `laporan_tukin_us_skp` :12722-12725, :12866-12884).
 */
final class JamKerja
{
    /**
     * Jam masuk/pulang standar `HH:MM:SS`. Rentang puasa inklusif di kedua ujung (legacy :12722).
     *
     * @return array{masuk: string, pulang: string}
     */
    public static function untuk(string $tanggal, KonfigurasiTukin $config): array
    {
        $puasa = self::puasa($tanggal, $config);
        $jumat = HariKerja::nomorHari($tanggal) === 5;

        return [
            'masuk'  => $puasa ? $config->jamMasukPuasa : $config->jamMasukNormal,
            'pulang' => $puasa
                ? ($jumat ? $config->jamPulangJumatPuasa : $config->jamPulangPuasa)
                : ($jumat ? $config->jamPulangJumatNormal : $config->jamPulangNormal),
        ];
    }

    public static function puasa(string $tanggal, KonfigurasiTukin $config): bool
    {
        TanggalBisnis::wajibValid($tanggal, 'tanggal kerja');

        return $config->puasaMulai !== null && $config->puasaSelesai !== null
            && $tanggal >= $config->puasaMulai && $tanggal <= $config->puasaSelesai;
    }

    public static function periksaJam(string $jam): void
    {
        if (preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/', $jam) !== 1) {
            throw new InvalidArgumentException("Jam tidak valid: {$jam}");
        }
    }

    /** Terima `HH:MM` atau `HH:MM:SS`, kembalikan `HH:MM:SS`. */
    public static function normalisasiJam(string $jam): string
    {
        self::periksaJam($jam);

        return strlen($jam) === 5 ? $jam . ':00' : $jam;
    }

    /**
     * Selisih `aktual - standar` dalam menit penuh. Sisa detik dibuang ke arah nol, sama dengan legacy yang memakai
     * `DateInterval::h/i` lalu memberi tanda negatif bila aktual lebih awal (:12871-12875, :12879-12883).
     */
    public static function selisihMenit(string $standar, string $aktual): int
    {
        return intdiv(self::detik($aktual) - self::detik($standar), 60);
    }

    private static function detik(string $jam): int
    {
        [$j, $m, $d] = array_map('intval', explode(':', self::normalisasiJam($jam)));

        return $j * 3600 + $m * 60 + $d;
    }
}
