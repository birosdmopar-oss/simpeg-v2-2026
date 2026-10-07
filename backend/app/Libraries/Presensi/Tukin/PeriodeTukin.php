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

    /**
     * Periode tukin bulan (tahun, bulan): default 16 bulan sebelumnya s.d. 15 bulan berjalan, atau periode
     * `bkn_periode_ekinper` yang diinjeksikan (rekap legacy `L_presensi.php:11852-11876`). Periode BKN wajib berakhir
     * di (tahun, bulan) yang diminta, karena legacy mengambil baris BKN berdasarkan tahun & bulan tersebut.
     * Untuk Januari, awal periode selalu 16 Desember tahun sebelumnya walau ada baris BKN (`:11859-11862`).
     *
     * @param array{awal?: string, akhir?: string}|null $periodeBkn
     */
    public static function dariBulan(int $tahun, int $bulan, ?array $periodeBkn = null): self
    {
        if ($tahun < 1900 || $tahun > 2100) {
            throw new InvalidArgumentException('Tahun harus 1900 sampai 2100.');
        }
        if ($bulan < 1 || $bulan > 12) {
            throw new InvalidArgumentException('Bulan harus 1 sampai 12.');
        }
        if ($periodeBkn !== null) {
            if (! isset($periodeBkn['awal'], $periodeBkn['akhir'])) {
                throw new InvalidArgumentException('Periode BKN harus memiliki awal dan akhir.');
            }
            $periode = new self($periodeBkn['awal'], $periodeBkn['akhir']);
            if (substr($periode->akhir, 0, 7) !== sprintf('%04d-%02d', $tahun, $bulan)) {
                throw new InvalidArgumentException('Akhir periode BKN tidak berada pada tahun dan bulan yang diminta.');
            }

            return $bulan === 1 ? new self(sprintf('%04d-12-16', $tahun - 1), $periode->akhir) : $periode;
        }
        $tahunSebelumnya = $bulan === 1 ? $tahun - 1 : $tahun;
        $bulanSebelumnya = $bulan === 1 ? 12 : $bulan - 1;

        return new self(sprintf('%04d-%02d-16', $tahunSebelumnya, $bulanSebelumnya), sprintf('%04d-%02d-15', $tahun, $bulan));
    }
}
