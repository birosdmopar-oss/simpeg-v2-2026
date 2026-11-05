<?php

declare(strict_types=1);

namespace App\Libraries\Kepegawaian\Lkh\AutoApproval;

use InvalidArgumentException;

/**
 * MAKE-068 (H-12, F6-25) — aturan murni auto-approval LKH: bulan yang diproses dan rentang tanggalnya. Tanpa DB, tanpa
 * jam sistem: tanggal hari ini (WIB, dari {@see \App\Libraries\Kepegawaian\Kalkulasi\TanggalBisnis::hariIni()}) selalu
 * diinjeksi oleh pemanggil.
 *
 * Aturan (PRD Fase 6 §7.10): run nyata hanya untuk bulan lalu; `--dry-run` boleh bulan lampau lain (dihitung terhadap data
 * saat ini); bulan berjalan dan masa depan ditolak.
 */
final class PenentuAutoApproval
{
    /**
     * Bulan lalu (YYYY-MM) dari tanggal hari ini (YYYY-MM-DD).
     *
     * @throws InvalidArgumentException tanggal tidak berformat YYYY-MM-DD yang sah
     */
    public static function bulanLalu(string $hariIni): string
    {
        [$tahun, $bulan] = self::pecahTanggal($hariIni);

        return $bulan === 1
            ? sprintf('%04d-12', $tahun - 1)
            : sprintf('%04d-%02d', $tahun, $bulan - 1);
    }

    /**
     * Tanggal awal dan akhir bulan (YYYY-MM-DD) untuk bulan YYYY-MM.
     *
     * @return array{awal: string, akhir: string}
     *
     * @throws InvalidArgumentException bulan tidak berformat YYYY-MM yang sah
     */
    public static function rentangBulan(string $bulan): array
    {
        if (preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $bulan, $m) !== 1) {
            throw new InvalidArgumentException('Bulan harus berformat YYYY-MM.');
        }

        $tahun = (int) $m[1];
        $bln   = (int) $m[2];

        return [
            'awal'  => sprintf('%04d-%02d-01', $tahun, $bln),
            'akhir' => sprintf('%04d-%02d-%02d', $tahun, $bln, self::jumlahHari($tahun, $bln)),
        ];
    }

    /**
     * Keputusan bulan yang diminta: `null` bila boleh, selain itu pesan penolakan (tanpa data pribadi).
     *
     * @throws InvalidArgumentException format bulan atau tanggal hari ini tidak sah
     */
    public static function tolakBulan(string $bulan, string $hariIni, bool $dryRun): ?string
    {
        self::rentangBulan($bulan);

        if ($bulan >= substr($hariIni, 0, 7)) {
            return '--bulan tidak boleh bulan berjalan atau masa depan.';
        }

        if (! $dryRun && $bulan !== self::bulanLalu($hariIni)) {
            return 'Run nyata hanya untuk bulan lalu; bulan lain hanya bersama --dry-run.';
        }

        return null;
    }

    /**
     * @return array{int, int}
     *
     * @throws InvalidArgumentException
     */
    private static function pecahTanggal(string $tanggal): array
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $tanggal, $m) !== 1 || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            throw new InvalidArgumentException('Tanggal harus berformat YYYY-MM-DD yang sah.');
        }

        return [(int) $m[1], (int) $m[2]];
    }

    private static function jumlahHari(int $tahun, int $bulan): int
    {
        return match ($bulan) {
            2           => self::kabisat($tahun) ? 29 : 28,
            4, 6, 9, 11 => 30,
            default     => 31,
        };
    }

    private static function kabisat(int $tahun): bool
    {
        return ($tahun % 4 === 0 && $tahun % 100 !== 0) || $tahun % 400 === 0;
    }
}
