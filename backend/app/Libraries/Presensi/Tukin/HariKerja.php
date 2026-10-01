<?php

declare(strict_types=1);

namespace App\Libraries\Presensi\Tukin;

use App\Libraries\Kepegawaian\Kalkulasi\TanggalBisnis;
use DateTimeImmutable;

final class HariKerja
{
    /** @param list<string> $hariLibur */
    public static function daftar(PeriodeTukin $periode, array $hariLibur): array
    {
        $libur = array_fill_keys(array_map(static fn (string $tanggal): string => TanggalBisnis::wajibValid($tanggal, 'hari libur'), $hariLibur), true);
        $tanggal = DateTimeImmutable::createFromFormat('!Y-m-d', $periode->awal);
        $akhir = DateTimeImmutable::createFromFormat('!Y-m-d', $periode->akhir);
        $hasil = [];
        while ($tanggal !== false && $akhir !== false && $tanggal <= $akhir) {
            $nilai = $tanggal->format('Y-m-d');
            if ((int) $tanggal->format('N') < 6 && ! isset($libur[$nilai])) {
                $hasil[] = $nilai;
            }
            $tanggal = $tanggal->modify('+1 day');
        }
        return $hasil;
    }
}
