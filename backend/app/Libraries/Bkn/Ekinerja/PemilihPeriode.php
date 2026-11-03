<?php

declare(strict_types=1);

namespace App\Libraries\Bkn\Ekinerja;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Aturan pemilihan periode tarik SKP periodik [K `EB:209-234`]: tanggal hari ini (WIB) > 19 → periode bulan berikutnya,
 * selain itu bulan berjalan; periode manual dilewati. Legacy hanya melewati satu id literal `SKPManualFeb2025`
 * (`EB:231-234`); v2 melewati semua id berawalan `SKPManual` (PRD F6-15, jawaban B0 Q5 — mencakup literal legacy).
 */
final class PemilihPeriode
{
    public const AWALAN_MANUAL = 'SKPManual';

    /**
     * @return array{tahun: int, bulan: string} bulan `%02d` (format kolom `bkn_periode_ekinper.bulan`)
     */
    public static function untuk(DateTimeImmutable $sekarang): array
    {
        $wib  = $sekarang->setTimezone(new DateTimeZone('Asia/Jakarta'));
        $awal = $wib->modify('first day of this month');

        if ((int) $wib->format('j') > 19) {
            $awal = $awal->modify('+1 month');
        }

        return ['tahun' => (int) $awal->format('Y'), 'bulan' => $awal->format('m')];
    }

    public static function manual(string $idPeriode): bool
    {
        return str_starts_with($idPeriode, self::AWALAN_MANUAL);
    }
}
