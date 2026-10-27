<?php

declare(strict_types=1);

namespace App\Libraries\Layanan\Studi;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * MAKE-024 (bagian murni) — utilitas tanggal `YYYY-MM-DD` untuk TB/IB/PMK: urai tanpa jam sistem
 * (`createFromFormat('!Y-m-d')`, satu-satunya pengecualian pemindai kemurnian), selisih tahun/bulan `DateTime::diff` [K],
 * irisan rentang inklusif [K kueri `'{mulai}' <= tgl_akhir AND '{akhir}' >= tgl_mulai`], dan tambah hari.
 */
final class RentangTanggal
{
    private function __construct()
    {
    }

    /**
     * Tanggal sah `YYYY-MM-DD` → objek pada 00:00. Tanggal tidak sah = kesalahan pemanggil (validasi dulu).
     */
    public static function tanggal(string $tanggal): DateTimeImmutable
    {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $tanggal);

        if ($d === false || $d->format('Y-m-d') !== $tanggal) {
            throw new InvalidArgumentException("Tanggal tidak valid: {$tanggal}");
        }

        return $d;
    }

    /**
     * Selisih `DateTime::diff` (tahun & bulan penuh; sisa hari dibuang, tidak inklusif) [K `Local.php:3203-3206, 4588`].
     *
     * @return array{tahun: int, bulan: int, hari: int}
     */
    public static function selisih(string $dari, string $sampai): array
    {
        $diff = self::tanggal($dari)->diff(self::tanggal($sampai));

        return ['tahun' => $diff->y, 'bulan' => $diff->m, 'hari' => $diff->d];
    }

    /**
     * Dua rentang inklusif beririsan.
     */
    public static function beririsan(string $mulaiA, string $akhirA, string $mulaiB, string $akhirB): bool
    {
        return $mulaiA <= $akhirB && $akhirA >= $mulaiB;
    }

    public static function tambahHari(string $tanggal, int $hari): string
    {
        return self::tanggal($tanggal)->modify(sprintf('%+d day', $hari))->format('Y-m-d');
    }
}
