<?php

declare(strict_types=1);

namespace App\Libraries\Layanan\Cuti;

/**
 * MAKE-022 Tahap 2b — kode status cuti `riwayat_cuti.status` [K] COMMENT D1:4440 dan domain `status_al` (D1:4427) /
 * `status_pyb` (D1:4431). Hapus cuti = fisik (D6), sehingga TIDAK ada status 10. Jangan memakai `StatusRiwayat` Fase 3:
 * arti kodenya berbeda (PRD §6.2.7 larangan).
 */
final class StatusCuti
{
    public const PENGAJUAN = 0;

    public const DISETUJUI = 1;

    public const DITOLAK = 2;

    /** Disetujui AL, menunggu PyB (hanya alur dua langkah). */
    public const DISETUJUI_AL = 3;

    public const DITANGGUHKAN = 4;

    public const SEMUA = [self::PENGAJUAN, self::DISETUJUI, self::DITOLAK, self::DISETUJUI_AL, self::DITANGGUHKAN];

    /** Status akhir: tidak ada transisi keluar kecuali hapus fisik (PRD §6.4.2). */
    public const FINAL = [self::DISETUJUI, self::DITOLAK, self::DITANGGUHKAN];

    /** Status yang ikut dihitung "menunggu" pada kuota (PRD §6.3.4, T9) dan tumpang tindih (keputusan #5). */
    public const MENUNGGU = [self::PENGAJUAN, self::DISETUJUI_AL];

    /** Status final yang memicu efek final (potong saldo, CLTN, surat) — PRD §6.4.5. */
    public const EFEK_FINAL = [self::DISETUJUI, self::DITANGGUHKAN];

    /** Domain `status_al` [K] D1:4427 (tidak ada 3). */
    public const DOMAIN_AL = [0, 1, 2, 4];

    /** Domain `status_pyb` [K] D1:4431; nilai 3 ada di COMMENT tetapi tidak pernah ditulis kode legacy (keputusan #3). */
    public const DOMAIN_PYB = [0, 1, 2, 3, 4];

    /** Label [K] COMMENT D1:4440. */
    public const LABEL = [
        self::PENGAJUAN    => 'Pengajuan',
        self::DISETUJUI    => 'Disetujui',
        self::DITOLAK      => 'Ditolak',
        self::DISETUJUI_AL => 'Diproses / Disetujui AL',
        self::DITANGGUHKAN => 'Ditangguhkan',
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

    public static function menunggu(int $status): bool
    {
        return in_array($status, self::MENUNGGU, true);
    }

    public static function label(int $status): string
    {
        return self::LABEL[$status] ?? 'Tidak dikenal';
    }
}
