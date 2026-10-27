<?php

declare(strict_types=1);

namespace App\Libraries\Layanan\Studi;

/**
 * MAKE-024 (bagian murni) — kode status `riwayat_tb.status` / `riwayat_ib.status` [K] COMMENT DDL (`riwayat_tb` :6585,
 * `riwayat_ib` :4936): 0 Pengajuan, 1 Disetujui, 2 Ditolak, 3 Diproses. Alur v2 satu langkah (D14): 0 → 1 | 2; kode 3
 * hanya mungkin dari data impor dan tidak ditulis v2 (PRD §6.7.3). Kode 4 tidak terdefinisi di DDL tetapi ikut disaring
 * kueri tumpang tindih legacy (`L_tb.php:931`). Hapus = fisik, tidak ada 10. Jangan memakai `StatusRiwayat` Fase 3.
 */
final class StatusStudi
{
    public const PENGAJUAN = 0;

    public const DISETUJUI = 1;

    public const DITOLAK = 2;

    /** [K] kode "Diproses" — hanya data impor; v2 tidak menulisnya. */
    public const DIPROSES = 3;

    public const SEMUA = [self::PENGAJUAN, self::DISETUJUI, self::DITOLAK, self::DIPROSES];

    /** Status akhir (PRD §6.7.3 tabel: 1, 2 apa pun → 422). */
    public const FINAL = [self::DISETUJUI, self::DITOLAK];

    /**
     * Status yang ikut dicek tumpang tindih: [K] {0, 3, 4} (`L_tb.php:931, 976`; `L_ib.php:719, 761`) + 1 [V2 TB-04/IB-04,
     * Q-15]. Kode 4 dipertahankan karena data impor bisa memuatnya.
     */
    public const TUMPANG_TINDIH = [self::PENGAJUAN, self::DISETUJUI, self::DIPROSES, 4];

    /** Label [K] COMMENT DDL. */
    public const LABEL = [
        self::PENGAJUAN => 'Pengajuan',
        self::DISETUJUI => 'Disetujui',
        self::DITOLAK   => 'Ditolak',
        self::DIPROSES  => 'Diproses',
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

    public static function label(int $status): string
    {
        return self::LABEL[$status] ?? 'Tidak dikenal';
    }
}
