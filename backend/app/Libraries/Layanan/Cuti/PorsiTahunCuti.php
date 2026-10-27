<?php

declare(strict_types=1);

namespace App\Libraries\Layanan\Cuti;

use InvalidArgumentException;

/**
 * MAKE-022 Tahap 2b — pemecahan lama cuti ke porsi tahun acuan (`lcTahunIni`) dan tahun berikutnya (`lcTahunDepan`),
 * PRD Fase 4 v1.1 §6.3.3 [K `L_cuti.php:1591-1610`; keputusan #6]. Pecah pada 31-12 / 01-01 dengan hitungan hari yang
 * sama seperti `HitungLamaCuti` (hari kerja untuk jenis 1/3/5, kalender untuk 2/4/6).
 *
 * Hanya dua tahun yang dikenal: rentang di luar `01-01 tahunAcuan .. 31-12 tahunAcuan+1` adalah kesalahan pemanggil
 * (`InvalidArgumentException`) — pengguna sudah ditolak 422 lebih dulu oleh `ValidasiTanggalCuti`.
 */
final class PorsiTahunCuti
{
    private function __construct()
    {
    }

    /**
     * @param list<string> $hariLibur
     *
     * @return array{tahun_ini: int, tahun_depan: int}
     */
    public static function pecah(int $jenis, string $mulai, string $akhir, int $tahunAcuan, array $hariLibur, AturanCuti $aturan): array
    {
        $akhirTahunIni   = sprintf('%04d-12-31', $tahunAcuan);
        $awalTahunDepan  = sprintf('%04d-01-01', $tahunAcuan + 1);
        $akhirTahunDepan = sprintf('%04d-12-31', $tahunAcuan + 1);
        $awalTahunIni    = sprintf('%04d-01-01', $tahunAcuan);

        if ($mulai > $akhir) {
            throw new InvalidArgumentException("Tanggal mulai {$mulai} setelah tanggal akhir {$akhir}");
        }

        if ($mulai < $awalTahunIni || $akhir > $akhirTahunDepan) {
            throw new InvalidArgumentException(
                "Rentang {$mulai}..{$akhir} di luar tahun acuan {$tahunAcuan} dan tahun berikutnya",
            );
        }

        $tahunIni = $mulai <= $akhirTahunIni
            ? HitungLamaCuti::lama($jenis, $mulai, min($akhir, $akhirTahunIni), $hariLibur, $aturan)
            : 0;
        $tahunDepan = $akhir >= $awalTahunDepan
            ? HitungLamaCuti::lama($jenis, max($mulai, $awalTahunDepan), $akhir, $hariLibur, $aturan)
            : 0;

        return ['tahun_ini' => $tahunIni, 'tahun_depan' => $tahunDepan];
    }

    /**
     * Tahun acuan pengajuan = tahun `created_at` dalam zona bisnis (PRD §6.3.3); dari stempel `YYYY-MM-DD…`.
     */
    public static function tahunAcuan(string $tanggalPengajuan): int
    {
        if (preg_match('/^(\d{4})-\d{2}-\d{2}/', $tanggalPengajuan, $m) !== 1) {
            throw new InvalidArgumentException("Tanggal pengajuan tidak valid: {$tanggalPengajuan}");
        }

        return (int) $m[1];
    }
}
