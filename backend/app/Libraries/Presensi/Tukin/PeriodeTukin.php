<?php

declare(strict_types=1);

namespace App\Libraries\Presensi\Tukin;

use App\Libraries\Kepegawaian\Kalkulasi\TanggalBisnis;
use InvalidArgumentException;

final readonly class PeriodeTukin
{
    public function __construct(public string $awal, public string $akhir)
    {
        TanggalBisnis::wajibValid($awal, 'awal periode');
        TanggalBisnis::wajibValid($akhir, 'akhir periode');
        if ($awal > $akhir) {
            throw new InvalidArgumentException('Awal periode tidak boleh setelah akhir periode.');
        }
    }

    /** @param array{awal?: string, akhir?: string}|null $periodeBkn */
    public static function dariBulan(int $tahun, int $bulan, ?array $periodeBkn = null): self
    {
        if ($bulan < 1 || $bulan > 12) {
            throw new InvalidArgumentException('Bulan harus 1 sampai 12.');
        }
        if ($periodeBkn !== null) {
            if (! isset($periodeBkn['awal'], $periodeBkn['akhir'])) {
                throw new InvalidArgumentException('Periode BKN harus memiliki awal dan akhir.');
            }
            return new self($periodeBkn['awal'], $periodeBkn['akhir']);
        }
        $tahunSebelumnya = $bulan === 1 ? $tahun - 1 : $tahun;
        $bulanSebelumnya = $bulan === 1 ? 12 : $bulan - 1;
        return new self(sprintf('%04d-%02d-16', $tahunSebelumnya, $bulanSebelumnya), sprintf('%04d-%02d-15', $tahun, $bulan));
    }
}
