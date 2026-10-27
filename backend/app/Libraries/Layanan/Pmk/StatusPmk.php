<?php

declare(strict_types=1);

namespace App\Libraries\Layanan\Pmk;

/**
 * MAKE-024 (bagian murni) — kode status `riwayat_pmk_req.status` [K] COMMENT DDL :6246: 0 Pengajuan, 1 Selesai, 2 Ditolak,
 * 3 Proses. State machine D16 (PRD Fase 4 v1.1 §6.9.2): 0 → 3 → {1, 2} atau 0 → {1, 2}; 1 dan 2 final. Label UI v2
 * (PRD §6.9.2) dibedakan dari label kolom [K].
 */
final class StatusPmk
{
    public const PENGAJUAN = 0;

    public const SELESAI = 1;

    public const DITOLAK = 2;

    public const PROSES = 3;

    public const SEMUA = [self::PENGAJUAN, self::SELESAI, self::DITOLAK, self::PROSES];

    public const FINAL = [self::SELESAI, self::DITOLAK];

    /** Label kolom [K] DDL :6246. */
    public const LABEL = [
        self::PENGAJUAN => 'Pengajuan',
        self::SELESAI   => 'Selesai',
        self::DITOLAK   => 'Ditolak',
        self::PROSES    => 'Proses',
    ];

    /** Label UI v2 (PRD §6.9.2). */
    public const LABEL_UI = [
        self::PENGAJUAN => 'Menunggu PIC',
        self::SELESAI   => 'Selesai',
        self::DITOLAK   => 'Ditolak',
        self::PROSES    => 'Diproses',
    ];

    private function __construct()
    {
    }

    public static function valid(int $status): bool
    {
        return in_array($status, self::SEMUA, true);
    }

    public static function final(int $status): bool
    {
        return in_array($status, self::FINAL, true);
    }

    /**
     * Ikut cek tumpang tindih: semua status kecuali Ditolak [K `L_pmk.php:854, 883` `status != '2'`].
     */
    public static function ikutTumpangTindih(int $status): bool
    {
        return $status !== self::DITOLAK;
    }

    /**
     * Detail pengajuan boleh dibuka untuk semua status termasuk 0 [V2 Q-16; legacy P-11 menolak status 0].
     */
    public static function detailBolehDibuka(int $status): bool
    {
        return self::valid($status);
    }

    /**
     * Hapus pengajuan ditolak bila Selesai [K `Pmk.php:357, 693`] → `errors.status`.
     */
    public static function bolehHapus(int $status): bool
    {
        return $status !== self::SELESAI;
    }

    public static function label(int $status): string
    {
        return self::LABEL[$status] ?? 'Tidak dikenal';
    }

    public static function labelUi(int $status): string
    {
        return self::LABEL_UI[$status] ?? 'Tidak dikenal';
    }
}
