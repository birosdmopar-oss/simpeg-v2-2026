<?php

declare(strict_types=1);

namespace App\Libraries\Layanan\StatusHarian;

use App\Libraries\Layanan\Cuti\StatusCuti;
use App\Libraries\Layanan\Studi\RentangTanggal;
use App\Libraries\Layanan\Studi\StatusStudi;
use InvalidArgumentException;

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
 *  - Aktif = ada segmen yang memuat tanggal. TB hanya status 1; CLTN hanya status 1 (status 4 Ditangguhkan dan 3 tidak,
 *    Q6-25). Segmen bertanggal tidak sah (format salah, `0000-00-00`, akhir < mulai) dilewati dan ditandai.
 *  - Bentrok TB dan CLTN aktif (Q6-24): status saat ini 2/3 dan rentang jenis itu masih aktif → tidak diubah; selain itu
 *    rentang dengan `tgl_mulai` segmen aktif paling akhir, seri → TB (2).
 *  - Hanya satu jenis aktif → status jenis itu (2 untuk TB, 3 untuk CLTN).
 *  - Tidak ada yang aktif: status 2/3 kembali 1 hanya bila dijelaskan riwayat jenisnya yang SUDAH berakhir (ada segmen dengan
 *    `tgl_akhir` < tanggal; Q6-26). Tanpa riwayat pendukung tidak disentuh dan ditandai `tanpaRiwayat`. Celah antara akhir
 *    TB dan perpanjangan yang belum mulai ikut kembali 1 (ditandai `celahTb`, keputusan reviewer CR 04-11-2026).
 *
 * Masukan boleh membawa kunci `id` (id riwayat) pada baris TB/CLTN supaya keputusan menunjuk riwayat penentu.
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
     * @param list<array{id?: int, status: int|string, tgl_mulai: string, tgl_akhir: string, perpanjangan?: list<array{tgl_mulai_perpan: string, tgl_akhir_perpan: string}>}> $daftarTb   riwayat TB milik pegawai
     * @param list<array{id?: int, status: int|string, tgl_mulai: string, tgl_akhir: string}>                                                                                 $daftarCltn riwayat cuti jenis 6 milik pegawai
     */
    public static function tentukan(int $statusSaatIni, array $daftarTb, array $daftarCltn, string $tanggal): KeputusanStatusHarian
    {
        RentangTanggal::tanggal($tanggal);

        if (! in_array($statusSaatIni, [self::AKTIF, self::TB, self::CLTN], true)) {
            return new KeputusanStatusHarian();
        }

        $tidakSah = false;
        $tb       = self::segmenTb($daftarTb, $tidakSah);
        $cltn     = self::segmenCltn($daftarCltn, $tidakSah);
        $aktifTb  = self::aktif($tb, $tanggal);
        $aktifCl  = self::aktif($cltn, $tanggal);

        if ($aktifTb !== null && $aktifCl !== null) {
            // Status 2/3 yang rentangnya masih aktif tidak berkedip (Q6-24).
            if ($statusSaatIni === self::TB || $statusSaatIni === self::CLTN) {
                return new KeputusanStatusHarian(bentrok: true, segmenTidakSah: $tidakSah);
            }

            return $aktifCl[0] > $aktifTb[0]
                ? self::ubah($statusSaatIni, self::CLTN, true, $tidakSah, $aktifCl[1])
                : self::ubah($statusSaatIni, self::TB, true, $tidakSah, $aktifTb[1]);
        }

        if ($aktifTb !== null) {
            return self::ubah($statusSaatIni, self::TB, false, $tidakSah, $aktifTb[1]);
        }

        if ($aktifCl !== null) {
            return self::ubah($statusSaatIni, self::CLTN, false, $tidakSah, $aktifCl[1]);
        }

        return self::pulihkan($statusSaatIni, $tb, $cltn, $tanggal, $tidakSah);
    }

    private static function ubah(int $statusSaatIni, int $target, bool $bentrok, bool $tidakSah, ?int $idRiwayat): KeputusanStatusHarian
    {
        if ($statusSaatIni === $target) {
            return new KeputusanStatusHarian(bentrok: $bentrok, segmenTidakSah: $tidakSah);
        }

        return new KeputusanStatusHarian(
            $target,
            $target === self::TB ? KeputusanStatusHarian::ALASAN_TB_AKTIF : KeputusanStatusHarian::ALASAN_CLTN_AKTIF,
            $bentrok,
            segmenTidakSah: $tidakSah,
            jenisRiwayat: $target === self::TB ? KeputusanStatusHarian::RIWAYAT_TB : KeputusanStatusHarian::RIWAYAT_CUTI,
            idRiwayat: $idRiwayat,
        );
    }

    /**
     * @param list<array{0: string, 1: string, 2: int|null}> $tb
     * @param list<array{0: string, 1: string, 2: int|null}> $cltn
     */
    private static function pulihkan(int $statusSaatIni, array $tb, array $cltn, string $tanggal, bool $tidakSah): KeputusanStatusHarian
    {
        if ($statusSaatIni === self::AKTIF) {
            return new KeputusanStatusHarian(segmenTidakSah: $tidakSah);
        }

        $segmen   = $statusSaatIni === self::TB ? $tb : $cltn;
        $berakhir = self::berakhirTerakhir($segmen, $tanggal);

        if ($berakhir === null) {
            return new KeputusanStatusHarian(tanpaRiwayat: true, segmenTidakSah: $tidakSah);
        }

        return new KeputusanStatusHarian(
            self::AKTIF,
            $statusSaatIni === self::TB ? KeputusanStatusHarian::ALASAN_PULIH_TB : KeputusanStatusHarian::ALASAN_PULIH_CLTN,
            celahTb: $statusSaatIni === self::TB && self::adaYangBelumMulai($segmen, $tanggal),
            segmenTidakSah: $tidakSah,
            jenisRiwayat: $statusSaatIni === self::TB ? KeputusanStatusHarian::RIWAYAT_TB : KeputusanStatusHarian::RIWAYAT_CUTI,
            idRiwayat: $berakhir[1],
        );
    }

    /**
     * Segmen yang memuat tanggal dengan `tgl_mulai` paling akhir: `[mulai, id]`; null bila tidak ada yang aktif.
     *
     * @param list<array{0: string, 1: string, 2: int|null}> $segmen
     *
     * @return array{0: string, 1: int|null}|null
     */
    private static function aktif(array $segmen, string $tanggal): ?array
    {
        $hasil = null;

        foreach ($segmen as [$mulai, $akhir, $id]) {
            if ($mulai <= $tanggal && $tanggal <= $akhir && ($hasil === null || $mulai > $hasil[0])) {
                $hasil = [$mulai, $id];
            }
        }

        return $hasil;
    }

    /**
     * Segmen yang sudah berakhir sebelum tanggal dengan `tgl_akhir` paling akhir: `[akhir, id]`; null bila tidak ada.
     *
     * @param list<array{0: string, 1: string, 2: int|null}> $segmen
     *
     * @return array{0: string, 1: int|null}|null
     */
    private static function berakhirTerakhir(array $segmen, string $tanggal): ?array
    {
        $hasil = null;

        foreach ($segmen as [, $akhir, $id]) {
            if ($akhir < $tanggal && ($hasil === null || $akhir > $hasil[0])) {
                $hasil = [$akhir, $id];
            }
        }

        return $hasil;
    }

    /**
     * @param list<array{0: string, 1: string, 2: int|null}> $segmen
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
     * Segmen `[mulai, akhir, id]` dari TB status 1: induk dan tiap perpanjangan (id perpanjangan = id TB induknya).
     *
     * @param list<array{id?: int, status: int|string, tgl_mulai: string, tgl_akhir: string, perpanjangan?: list<array{tgl_mulai_perpan: string, tgl_akhir_perpan: string}>}> $daftarTb
     *
     * @return list<array{0: string, 1: string, 2: int|null}>
     */
    private static function segmenTb(array $daftarTb, bool &$tidakSah): array
    {
        $segmen = [];

        foreach ($daftarTb as $tb) {
            if ((int) $tb['status'] !== StatusStudi::DISETUJUI) {
                continue;
            }

            $id = $tb['id'] ?? null;
            self::tambah($segmen, $tb['tgl_mulai'], $tb['tgl_akhir'], $id, $tidakSah);

            foreach ($tb['perpanjangan'] ?? [] as $p) {
                self::tambah($segmen, $p['tgl_mulai_perpan'], $p['tgl_akhir_perpan'], $id, $tidakSah);
            }
        }

        return $segmen;
    }

    /**
     * @param list<array{id?: int, status: int|string, tgl_mulai: string, tgl_akhir: string}> $daftarCltn
     *
     * @return list<array{0: string, 1: string, 2: int|null}>
     */
    private static function segmenCltn(array $daftarCltn, bool &$tidakSah): array
    {
        $segmen = [];

        foreach ($daftarCltn as $c) {
            if ((int) $c['status'] === StatusCuti::DISETUJUI) {
                self::tambah($segmen, $c['tgl_mulai'], $c['tgl_akhir'], $c['id'] ?? null, $tidakSah);
            }
        }

        return $segmen;
    }

    /**
     * @param list<array{0: string, 1: string, 2: int|null}> $segmen
     */
    private static function tambah(array &$segmen, string $mulai, string $akhir, ?int $id, bool &$tidakSah): void
    {
        try {
            RentangTanggal::tanggal($mulai);
            RentangTanggal::tanggal($akhir);
        } catch (InvalidArgumentException) {
            $tidakSah = true;

            return;
        }

        if ($akhir < $mulai) {
            $tidakSah = true;

            return;
        }

        $segmen[] = [$mulai, $akhir, $id];
    }
}
