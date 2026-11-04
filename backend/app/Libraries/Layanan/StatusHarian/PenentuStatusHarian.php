<?php

declare(strict_types=1);

namespace App\Libraries\Layanan\StatusHarian;

use App\Libraries\Layanan\Studi\RentangTanggal;
use App\Libraries\Layanan\Studi\StatusStudi;

/**
 * MAKE-062 (bagian murni) — keputusan `pegawai.id_jenis_status` harian untuk TB/CLTN (PRD Fase 6 §7.9, F6-20,
 * Q6-24..Q6-27, CR-098). Murni: tanggal proses diinjeksi, tanpa DB/jam sistem.
 *
 * Sumber legacy [K]: TB → 2 saat setujui `L_tb.php:1170-1178`, → 1 saat hapus `L_tb.php:745-758`; CLTN → 3 hanya status 1
 * `L_cuti.php:2423-2433`, → 1 saat hapus `:1369-1377`. Legacy tanpa cron dan tanpa pemulihan sesudah tanggal akhir; harian
 * adalah [V2] (keputusan Horii 27-10-2026). Rentang inklusif per TANGGAL (PRD Fase 4 v1.4), segmen sama dengan
 * {@see \App\Libraries\Layanan\Tb\BebasPresensiTb}: induk dan tiap perpanjangan adalah segmen terpisah.
 *
 * Aturan:
 *  - Status selain 1/2/3 tidak disentuh (AC-11.4).
 *  - Aktif = ada segmen yang memuat tanggal. TB hanya status 1; CLTN hanya status 1 (status 4 Ditangguhkan tidak, Q6-25).
 *  - Bentrok TB dan CLTN aktif (Q6-24): status saat ini 2/3 dan rentang jenis itu masih aktif → tidak diubah; selain itu
 *    rentang dengan `tgl_mulai` segmen aktif paling akhir, seri → TB (2).
 *  - Hanya satu jenis aktif → status jenis itu (2 untuk TB, 3 untuk CLTN).
 *  - Tidak ada yang aktif: status 2/3 kembali 1 hanya bila dijelaskan riwayat jenisnya yang SUDAH berakhir (ada segmen dengan
 *    `tgl_akhir` < tanggal; Q6-26). Tanpa riwayat pendukung tidak disentuh dan ditandai `tanpaRiwayat`. Celah antara akhir
 *    TB dan perpanjangan yang belum mulai ikut kembali 1 (ditandai `celahTb`, keputusan reviewer CR 04-11-2026).
 */
final class PenentuStatusHarian
{
    public const AKTIF = 1;

    public const TB = 2;

    public const CLTN = 3;

    private function __construct()
    {
    }

    /**
     * @param list<array{status: int|string, tgl_mulai: string, tgl_akhir: string, perpanjangan?: list<array{tgl_mulai_perpan: string, tgl_akhir_perpan: string}>}> $daftarTb   riwayat TB milik pegawai
     * @param list<array{status: int|string, tgl_mulai: string, tgl_akhir: string}>                                                                                 $daftarCltn riwayat cuti jenis 6 milik pegawai
     */
    public static function tentukan(int $statusSaatIni, array $daftarTb, array $daftarCltn, string $tanggal): KeputusanStatusHarian
    {
        RentangTanggal::tanggal($tanggal);

        if (! in_array($statusSaatIni, [self::AKTIF, self::TB, self::CLTN], true)) {
            return new KeputusanStatusHarian();
        }

        $tb        = self::segmenTb($daftarTb);
        $cltn      = self::segmenCltn($daftarCltn);
        $mulaiTb   = self::mulaiAktif($tb, $tanggal);
        $mulaiCltn = self::mulaiAktif($cltn, $tanggal);

        if ($mulaiTb !== null && $mulaiCltn !== null) {
            // Status 2/3 yang rentangnya masih aktif tidak berkedip (Q6-24).
            if ($statusSaatIni === self::TB || $statusSaatIni === self::CLTN) {
                return new KeputusanStatusHarian(bentrok: true);
            }

            return self::ubah($statusSaatIni, $mulaiCltn > $mulaiTb ? self::CLTN : self::TB, true);
        }

        if ($mulaiTb !== null) {
            return self::ubah($statusSaatIni, self::TB, false);
        }

        if ($mulaiCltn !== null) {
            return self::ubah($statusSaatIni, self::CLTN, false);
        }

        return self::pulihkan($statusSaatIni, $tb, $cltn, $tanggal);
    }

    private static function ubah(int $statusSaatIni, int $target, bool $bentrok): KeputusanStatusHarian
    {
        if ($statusSaatIni === $target) {
            return new KeputusanStatusHarian(bentrok: $bentrok);
        }

        return new KeputusanStatusHarian(
            $target,
            $target === self::TB ? KeputusanStatusHarian::ALASAN_TB_AKTIF : KeputusanStatusHarian::ALASAN_CLTN_AKTIF,
            $bentrok,
        );
    }

    /**
     * @param list<array{0: string, 1: string}> $tb
     * @param list<array{0: string, 1: string}> $cltn
     */
    private static function pulihkan(int $statusSaatIni, array $tb, array $cltn, string $tanggal): KeputusanStatusHarian
    {
        if ($statusSaatIni === self::AKTIF) {
            return new KeputusanStatusHarian();
        }

        $segmen = $statusSaatIni === self::TB ? $tb : $cltn;

        if (! self::adaYangBerakhir($segmen, $tanggal)) {
            return new KeputusanStatusHarian(tanpaRiwayat: true);
        }

        return new KeputusanStatusHarian(
            self::AKTIF,
            $statusSaatIni === self::TB ? KeputusanStatusHarian::ALASAN_PULIH_TB : KeputusanStatusHarian::ALASAN_PULIH_CLTN,
            celahTb: $statusSaatIni === self::TB && self::adaYangBelumMulai($segmen, $tanggal),
        );
    }

    /**
     * `tgl_mulai` paling akhir di antara segmen yang memuat tanggal; null bila tidak ada yang aktif.
     *
     * @param list<array{0: string, 1: string}> $segmen
     */
    private static function mulaiAktif(array $segmen, string $tanggal): ?string
    {
        $hasil = null;

        foreach ($segmen as [$mulai, $akhir]) {
            if ($mulai <= $tanggal && $tanggal <= $akhir && ($hasil === null || $mulai > $hasil)) {
                $hasil = $mulai;
            }
        }

        return $hasil;
    }

    /**
     * @param list<array{0: string, 1: string}> $segmen
     */
    private static function adaYangBerakhir(array $segmen, string $tanggal): bool
    {
        foreach ($segmen as [, $akhir]) {
            if ($akhir < $tanggal) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array{0: string, 1: string}> $segmen
     */
    private static function adaYangBelumMulai(array $segmen, string $tanggal): bool
    {
        foreach ($segmen as [$mulai]) {
            if ($mulai > $tanggal) {
                return true;
            }
        }

        return false;
    }

    /**
     * Segmen `[mulai, akhir]` dari TB status 1: induk dan tiap perpanjangan.
     *
     * @param list<array{status: int|string, tgl_mulai: string, tgl_akhir: string, perpanjangan?: list<array{tgl_mulai_perpan: string, tgl_akhir_perpan: string}>}> $daftarTb
     *
     * @return list<array{0: string, 1: string}>
     */
    private static function segmenTb(array $daftarTb): array
    {
        $segmen = [];

        foreach ($daftarTb as $tb) {
            if ((int) $tb['status'] !== StatusStudi::DISETUJUI) {
                continue;
            }

            $segmen[] = [$tb['tgl_mulai'], $tb['tgl_akhir']];

            foreach ($tb['perpanjangan'] ?? [] as $p) {
                $segmen[] = [$p['tgl_mulai_perpan'], $p['tgl_akhir_perpan']];
            }
        }

        return $segmen;
    }

    /**
     * @param list<array{status: int|string, tgl_mulai: string, tgl_akhir: string}> $daftarCltn
     *
     * @return list<array{0: string, 1: string}>
     */
    private static function segmenCltn(array $daftarCltn): array
    {
        $segmen = [];

        foreach ($daftarCltn as $c) {
            if ((int) $c['status'] === StatusStudi::DISETUJUI) {
                $segmen[] = [$c['tgl_mulai'], $c['tgl_akhir']];
            }
        }

        return $segmen;
    }
}
