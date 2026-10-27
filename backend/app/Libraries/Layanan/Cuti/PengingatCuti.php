<?php

declare(strict_types=1);

namespace App\Libraries\Layanan\Cuti;

use App\Libraries\Kepegawaian\Kalkulasi\TanggalBisnis;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

/**
 * MAKE-022 Tahap 2b — batas tunda pengingat persetujuan (`skip_al`/`skip_pyb`, D2, PRD Fase 4 v1.1 §6.4.7) [K
 * `Cuti.php:969-990`]: tunda boleh selama sekarang ≤ `tgl_mulai` 12:00:00 WIB (tepat 12:00:00 masih boleh, 12:00:01
 * tidak — T17). "Sekarang" diterima dari pemanggil (tidak membaca jam) dan dibandingkan dalam zona Asia/Jakarta.
 * Reset flag saat login = Q-07 (di luar library ini).
 */
final class PengingatCuti
{
    public const FLAG_AL = 'skip_al';

    public const FLAG_PYB = 'skip_pyb';

    private function __construct()
    {
    }

    /** Batas tunda `YYYY-MM-DD HH:MM:SS` (WIB). */
    public static function batasTunda(string $tglMulai, AturanCuti $aturan): string
    {
        return TanggalBisnis::wajibValid($tglMulai, 'tanggal mulai') . ' ' . $aturan->jamBatasTunda . ':00';
    }

    /**
     * @param DateTimeInterface|string $sekarang waktu sekarang: objek (zona apa pun, dikonversi ke WIB) atau string
     *                                           `YYYY-MM-DD HH:MM:SS` yang sudah dalam WIB
     */
    public static function bolehTunda(DateTimeInterface|string $sekarang, string $tglMulai, AturanCuti $aturan): bool
    {
        return self::waktuWib($sekarang) <= self::batasTunda($tglMulai, $aturan);
    }

    /** Kolom flag menurut tahap aktif (`TransisiCuti::TAHAP_*`). */
    public static function flagTahap(string $tahap): string
    {
        return match ($tahap) {
            TransisiCuti::TAHAP_AL  => self::FLAG_AL,
            TransisiCuti::TAHAP_PYB => self::FLAG_PYB,
            default                 => throw new InvalidArgumentException("Tahap tidak dikenal: {$tahap}"),
        };
    }

    /** Pesan 422 `errors.status` [K `Cuti.php:971-976`]. */
    public static function pesanTolak(string $tglMulai, AturanCuti $aturan): string
    {
        return "Anda hanya dapat melakukan skip sebelum tanggal {$tglMulai} jam {$aturan->jamBatasTunda}:00.";
    }

    private static function waktuWib(DateTimeInterface|string $sekarang): string
    {
        if ($sekarang instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($sekarang)
                ->setTimezone(new DateTimeZone(TanggalBisnis::ZONA))
                ->format('Y-m-d H:i:s');
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\z/', $sekarang) !== 1) {
            throw new InvalidArgumentException("Waktu sekarang harus YYYY-MM-DD HH:MM:SS: {$sekarang}");
        }

        return $sekarang;
    }
}
