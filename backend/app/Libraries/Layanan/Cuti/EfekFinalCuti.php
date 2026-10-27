<?php

declare(strict_types=1);

namespace App\Libraries\Layanan\Cuti;

/**
 * MAKE-022 Tahap 2b — predikat efek final (D4, PRD Fase 4 v1.1 §6.4.5): efek hanya pada transisi ke 1 atau 4, tidak
 * pernah pada 2 atau 3. Eksekusinya (potong saldo lewat `KuotaCutiService`, `pegawai.id_jenis_status = 3`, surat PDF,
 * audit) ada di M3; di sini hanya keputusan ya/tidak dari input.
 *
 * CLTN [K `L:2416-2433`, `L:3494-3503`]: jenis 6 status 1 dan hari ini di dalam `tgl_mulai..tgl_akhir` (inklusif) →
 * aktif; CLTN yang mulai di masa depan tidak diubah (A-12). T18: TM = hari ini / TA = hari ini → aktif; TM = besok → tidak.
 */
final class EfekFinalCuti
{
    private function __construct()
    {
    }

    public static function adaEfek(int $statusKe): bool
    {
        return in_array($statusKe, StatusCuti::EFEK_FINAL, true);
    }

    /** Potong saldo hanya jenis 1 dan hanya ke 1/4. */
    public static function perluPotongSaldo(int $jenis, int $statusKe): bool
    {
        return $jenis === AturanCuti::JENIS_TAHUNAN && self::adaEfek($statusKe);
    }

    /** Surat cuti PDF (belum TTE) diterbitkan pada 1/4 untuk semua jenis. */
    public static function perluSurat(int $statusKe): bool
    {
        return self::adaEfek($statusKe);
    }

    /** `pegawai.id_jenis_status` → 3 bila CLTN disetujui dan sedang berjalan hari ini. */
    public static function cltnAktif(int $jenis, int $status, string $tglMulai, string $tglAkhir, string $hariIni): bool
    {
        return $jenis === AturanCuti::JENIS_CLTN
            && $status === StatusCuti::DISETUJUI
            && $tglMulai <= $hariIni
            && $hariIni <= $tglAkhir;
    }

    /**
     * Ringkasan efek untuk satu transisi.
     *
     * @return array{potong_saldo: bool, cltn_aktif: bool, surat: bool}
     */
    public static function daftar(int $jenis, int $statusKe, string $tglMulai, string $tglAkhir, string $hariIni): array
    {
        return [
            'potong_saldo' => self::perluPotongSaldo($jenis, $statusKe),
            'cltn_aktif'   => self::cltnAktif($jenis, $statusKe, $tglMulai, $tglAkhir, $hariIni),
            'surat'        => self::perluSurat($statusKe),
        ];
    }
}
