<?php

declare(strict_types=1);

namespace App\Libraries\Presensi\Tukin;

use App\Libraries\Kepegawaian\Kalkulasi\TanggalBisnis;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Deret tanggal kalender dan hari kerja. Hari libur dan Sabtu/Minggu disingkirkan paling awal, seperti legacy
 * (`laporan_tukin` :4611-4641); libur yang jatuh di akhir pekan tidak dihitung dua kali.
 */
final class HariKerja
{
    /**
     * @param list<string> $hariLibur
     *
     * @return list<string>
     */
    public static function daftar(PeriodeTukin $periode, array $hariLibur): array
    {
        $libur = self::himpunanLibur($hariLibur);

        return array_values(array_filter(
            self::kalender($periode->awal, $periode->akhir),
            static fn (string $tanggal): bool => self::hariKerja($tanggal, $libur),
        ));
    }

    /**
     * @param array<string, true> $libur
     */
    public static function hariKerja(string $tanggal, array $libur): bool
    {
        return self::nomorHari($tanggal) < 6 && ! isset($libur[$tanggal]);
    }

    /**
     * @param list<string> $hariLibur
     *
     * @return array<string, true>
     */
    public static function himpunanLibur(array $hariLibur): array
    {
        $hasil = [];

        foreach ($hariLibur as $tanggal) {
            $hasil[TanggalBisnis::wajibValid($tanggal, 'hari libur')] = true;
        }

        return $hasil;
    }

    /**
     * Semua tanggal kalender dari awal sampai akhir (inklusif); kosong bila awal > akhir.
     *
     * @return list<string>
     */
    public static function kalender(string $awal, string $akhir): array
    {
        $tanggal = self::tanggal($awal);
        $batas   = self::tanggal($akhir);
        $hasil   = [];

        while ($tanggal <= $batas) {
            $hasil[] = $tanggal->format('Y-m-d');
            $tanggal = $tanggal->modify('+1 day');
        }

        return $hasil;
    }

    /** Nomor hari ISO: 1 = Senin .. 7 = Minggu. */
    public static function nomorHari(string $tanggal): int
    {
        return (int) self::tanggal($tanggal)->format('N');
    }

    public static function kemarin(string $tanggal): string
    {
        return self::tanggal($tanggal)->modify('-1 day')->format('Y-m-d');
    }

    private static function tanggal(string $tanggal): DateTimeImmutable
    {
        $hasil = DateTimeImmutable::createFromFormat('!Y-m-d', TanggalBisnis::wajibValid($tanggal, 'tanggal'));
        if ($hasil === false) {
            throw new InvalidArgumentException("Tanggal tidak valid: {$tanggal}");
        }

        return $hasil;
    }
}
