<?php

declare(strict_types=1);

namespace App\Libraries\Kepegawaian\Riwayat;

use Config\Kepegawaian;

/**
 * Status baris riwayat (kolom `status` tabel riwayat_*, ikut legacy): 0 Menunggu, 1 Disetujui, 2 Ditolak, 10 Dihapus
 * (hapus lunak). Nilai 3 "Diproses" ada tetapi hanya berlaku bila Config\Kepegawaian::$statusDiprosesAktif aktif
 * (keputusan #8 Sprint 0, nonaktif default).
 *
 * Snapshot `pegawai_*` hanya mengikuti baris berstatus Disetujui (ADR-006, dok DBV-012 §5.1).
 */
enum StatusRiwayat: int
{
    case Menunggu  = 0;
    case Disetujui = 1;
    case Ditolak   = 2;
    case Diproses  = 3;
    case Dihapus   = 10;

    public function label(): string
    {
        return match ($this) {
            self::Menunggu  => 'Menunggu',
            self::Disetujui => 'Disetujui',
            self::Ditolak   => 'Ditolak',
            self::Diproses  => 'Diproses',
            self::Dihapus   => 'Dihapus',
        };
    }

    /**
     * Status yang berlaku; tanpa argumen mengikuti Config\Kepegawaian::$statusDiprosesAktif.
     *
     * @return list<self>
     */
    public static function berlaku(?bool $diprosesAktif = null): array
    {
        $diprosesAktif ??= self::diprosesAktif();

        return array_values(array_filter(
            self::cases(),
            static fn (self $status): bool => $status !== self::Diproses || $diprosesAktif,
        ));
    }

    public function isBerlaku(?bool $diprosesAktif = null): bool
    {
        return in_array($this, self::berlaku($diprosesAktif), true);
    }

    /**
     * Nilai kolom → status yang berlaku; null bila nilai tidak dikenal atau status 3 sementara flag nonaktif.
     */
    public static function dariNilai(int|string|null $nilai, ?bool $diprosesAktif = null): ?self
    {
        if ($nilai === null || (is_string($nilai) && ! ctype_digit($nilai))) {
            return null;
        }

        $status = self::tryFrom((int) $nilai);

        return $status !== null && $status->isBerlaku($diprosesAktif) ? $status : null;
    }

    private static function diprosesAktif(): bool
    {
        return config(Kepegawaian::class)->statusDiprosesAktif;
    }
}
