<?php

declare(strict_types=1);

namespace App\Libraries\Layanan\Tb;

use App\Libraries\Layanan\Studi\RentangTanggal;
use App\Libraries\Layanan\Studi\StatusStudi;
use InvalidArgumentException;

/**
 * MAKE-024 (bagian murni) — predikat "TB penuh bebas presensi" (PRD Fase 4 v1.1 §6.7.5, D15; konsumen Fase 5 D-06).
 * Inti murni di balik `App\Interfaces\Layanan\StatusTugasBelajarInterface` (service DB M5 memuat baris lalu memanggil
 * kelas ini). `status_penuh` = nilai TURUNAN, tanpa kolom (D15; legacy tidak punya kolom/konsep penuh-paruh).
 *
 * Aturan (PRD §6.7.5) [K `L_presensi.php:972-977, 1660, 4127`; `L_user.php:118-119`]: tanggal T bebas presensi bila ada TB
 * milik pegawai berstatus **1** dan (`tgl_mulai ≤ T ≤ tgl_akhir` **atau** T di dalam salah satu rentang perpanjangannya
 * `tgl_mulai_perpan ≤ T ≤ tgl_akhir_perpan`), inklusif. Celah antara akhir TB dan awal perpanjangan **tidak** bebas
 * (sama `laporan_tukin`/`laporan_tukin_us_skp` acuan Tukin; `rekap_full_xls` legacy menganggapnya TB — anomali, dicatat).
 *
 * Bentuk baris TB: `status`, `tgl_mulai`, `tgl_akhir`, `perpanjangan` (list `tgl_mulai_perpan`/`tgl_akhir_perpan`,
 * opsional). Hasil {@see self::rentangBebasPresensi()} siap dipetakan ke input harian Tukin `tugas_belajar = true`
 * (`Libraries/Presensi/Tukin/StatusHarian::tugasBelajar`).
 */
final class BebasPresensiTb
{
    /** Batas panjang rentang tanya (hari) agar pemanggil tidak meminta rentang tak wajar; ±1 periode laporan. */
    public const MAKS_HARI_RENTANG = 366;

    private function __construct()
    {
    }

    /**
     * @param list<array{status: int|string, tgl_mulai: string, tgl_akhir: string, perpanjangan?: list<array{tgl_mulai_perpan: string, tgl_akhir_perpan: string}>}> $daftarTb
     */
    public static function bebasPresensiPenuh(array $daftarTb, string $tanggal): bool
    {
        foreach (self::rentangAktif($daftarTb) as [$mulai, $akhir]) {
            if ($mulai <= $tanggal && $tanggal <= $akhir) {
                return true;
            }
        }

        return false;
    }

    /**
     * Daftar tanggal `YYYY-MM-DD` di `[$dari, $sampai]` (inklusif, urut) yang bebas presensi.
     *
     * @param list<array{status: int|string, tgl_mulai: string, tgl_akhir: string, perpanjangan?: list<array{tgl_mulai_perpan: string, tgl_akhir_perpan: string}>}> $daftarTb
     *
     * @return list<string>
     */
    public static function rentangBebasPresensi(array $daftarTb, string $dari, string $sampai): array
    {
        if ($sampai < $dari) {
            throw new InvalidArgumentException("Rentang terbalik: {$dari} s.d. {$sampai}");
        }

        $hari = RentangTanggal::tanggal($dari)->diff(RentangTanggal::tanggal($sampai))->days;

        if ($hari >= self::MAKS_HARI_RENTANG) {
            throw new InvalidArgumentException('Rentang maksimal ' . self::MAKS_HARI_RENTANG . " hari: {$dari} s.d. {$sampai}");
        }

        $rentang = self::rentangAktif($daftarTb);
        $hasil   = [];

        for ($t = $dari; $t <= $sampai; $t = RentangTanggal::tambahHari($t, 1)) {
            foreach ($rentang as [$mulai, $akhir]) {
                if ($mulai <= $t && $t <= $akhir) {
                    $hasil[] = $t;

                    break;
                }
            }
        }

        return $hasil;
    }

    /**
     * Versi massal {@see self::rentangBebasPresensi()} (MAKE-044): rentang divalidasi sekali, lalu aturan yang sama
     * diterapkan per NIP. NIP tanpa entri di `$daftarTbPerNip` → daftar kosong; NIP ganda muncul sekali. Kunci bertipe
     * `array-key`: PHP mengubah kunci NIP berupa angka menjadi int.
     *
     * @param list<string>                                                                                                                                                        $nip
     * @param array<array-key, list<array{status: int|string, tgl_mulai: string, tgl_akhir: string, perpanjangan?: list<array{tgl_mulai_perpan: string, tgl_akhir_perpan: string}>}>> $daftarTbPerNip
     *
     * @return array<array-key, list<string>>
     */
    public static function rentangBebasPresensiBanyak(array $nip, array $daftarTbPerNip, string $dari, string $sampai): array
    {
        self::rentangBebasPresensi([], $dari, $sampai);

        $hasil = [];

        foreach ($nip as $n) {
            $hasil[$n] ??= self::rentangBebasPresensi($daftarTbPerNip[$n] ?? [], $dari, $sampai);
        }

        return $hasil;
    }

    /**
     * `status_penuh` turunan untuk respons TB: 1 bila hari ini bebas presensi, selain itu 0 (DoD 070-3).
     *
     * @param list<array{status: int|string, tgl_mulai: string, tgl_akhir: string, perpanjangan?: list<array{tgl_mulai_perpan: string, tgl_akhir_perpan: string}>}> $daftarTb
     */
    public static function statusPenuh(array $daftarTb, string $hariIni): int
    {
        return self::bebasPresensiPenuh($daftarTb, $hariIni) ? 1 : 0;
    }

    /**
     * Rentang inklusif dari TB status 1 dan perpanjangannya.
     *
     * @param list<array{status: int|string, tgl_mulai: string, tgl_akhir: string, perpanjangan?: list<array{tgl_mulai_perpan: string, tgl_akhir_perpan: string}>}> $daftarTb
     *
     * @return list<array{0: string, 1: string}>
     */
    private static function rentangAktif(array $daftarTb): array
    {
        $rentang = [];

        foreach ($daftarTb as $tb) {
            if ((int) $tb['status'] !== StatusStudi::DISETUJUI) {
                continue;
            }

            $rentang[] = [$tb['tgl_mulai'], $tb['tgl_akhir']];

            foreach ($tb['perpanjangan'] ?? [] as $p) {
                $rentang[] = [$p['tgl_mulai_perpan'], $p['tgl_akhir_perpan']];
            }
        }

        return $rentang;
    }
}
