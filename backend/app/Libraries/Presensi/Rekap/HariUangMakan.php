<?php

declare(strict_types=1);

namespace App\Libraries\Presensi\Rekap;

use App\Libraries\Kepegawaian\Kalkulasi\TanggalBisnis;
use App\Libraries\Presensi\Tukin\HariKerja;
use InvalidArgumentException;

/**
 * Aturan hari dibayar uang makan satu pegawai × satu bulan kalender kehadiran — **murni** (PRD 5B §7.2.2, F5B-2: satu
 * aturan untuk nilai uang makan slip D-09a dan rekap uang makan D-09b). Port `Lsl_gaji::getUangMakan` legacy `0cf7e67`
 * (`libraries/hr/Lsl_gaji.php:260-383`) [K]; tanpa DB, jam sistem, atau I/O (dijaga `RekapMurniTest`).
 *
 * Untuk setiap tanggal 1..akhir bulan, dipotong `$hariIni` (`:273-279`):
 * 1. hari libur atau Sabtu/Minggu → lewati (`:358`);
 * 2. tanggal tugas belajar → lewati; bila potongan TB bulan itu untuk TB pemilik tanggal > 0, penanda TB aktif dan
 *    **melekat sampai akhir bulan** (`:360-363`, F5B-3). Potongan TB per (TB, bulan) = 25, atau 0 bila TB itu mulai
 *    setelah tanggal 1 di bulan tersebut; segmen perpanjangan memakai `tgl_mulai` TB induk (`:311-344`). Pemilik tanggal =
 *    segmen terakhir yang mencakupnya menurut urutan masukan (legacy menimpa `$tb[$date]`);
 * 3. penanda TB aktif atau tanggal cuti (status 1, jenis apa pun) → lewati (`:364`);
 * 4. konket `affect_tukin = 1`: **dibayar hanya bila `kategori = 13`**, selain itu lewati (`:366-369`); konket yang
 *    menutupi tanggal yang sama → baris terakhir menurut urutan masukan (legacy menimpa `$konket[$date]`);
 * 5. selain itu dibayar bila presensi tanggal itu punya jam masuk **atau** jam pulang (`:371-373`).
 */
final class HariUangMakan
{
    private const KATEGORI_KONKET_DIBAYAR = 13;

    private function __construct()
    {
    }

    /**
     * @param list<string>                                                                  $libur    tanggal libur (`Y-m-d`)
     * @param list<array{0: string, 1: string}>                                             $cuti     rentang cuti status 1 `[mulai, akhir]`
     * @param list<array{id: int|string, mulai_induk: string, dari: string, sampai: string}> $tb       segmen TB (induk urut `tgl_mulai`, lalu perpanjangan urut `tgl_mulai_perpan`)
     * @param list<array{mulai: string, akhir: string, affect_tukin: int|string, kategori: int|string}> $konket   konket status `V`, urut `date_start` naik
     * @param array<string, array{masuk?: ?string, pulang?: ?string}>                      $presensi per tanggal
     *
     * @return list<string> tanggal yang dibayar uang makan, urut naik
     */
    public static function hariDibayar(
        int $tahun,
        int $bulan,
        ?string $hariIni,
        array $libur,
        array $cuti,
        array $tb,
        array $konket,
        array $presensi,
    ): array {
        if ($bulan < 1 || $bulan > 12) {
            throw new InvalidArgumentException('Bulan kehadiran tidak sah.');
        }

        $awal  = sprintf('%04d-%02d-01', $tahun, $bulan);
        $akhir = HariKerja::kemarin(TanggalBisnis::tambahBulan($awal, 1));
        $loop  = $hariIni !== null && TanggalBisnis::wajibValid($hariIni, 'hari ini') < $akhir ? $hariIni : $akhir;

        if ($loop < $awal) {
            return [];
        }

        $hariLibur                = array_fill_keys($libur, true);
        $hariCuti                 = self::tandai($cuti, $awal, $akhir);
        [$tbPemilik, $tbPotongan] = self::tugasBelajar($tb, $awal, $akhir);
        $hariKonket               = [];

        foreach ($konket as $k) {
            foreach (self::tanggal(max(substr($k['mulai'], 0, 10), $awal), min(substr($k['akhir'], 0, 10), $akhir)) as $t) {
                $hariKonket[$t] = $k;
            }
        }

        $dibayar   = [];
        $penandaTb = false;

        foreach (self::tanggal($awal, $loop) as $t) {
            if (isset($hariLibur[$t]) || HariKerja::nomorHari($t) >= 6) {
                continue;
            }

            if (isset($tbPemilik[$t])) {
                if (($tbPotongan[$tbPemilik[$t]] ?? 0) > 0) {
                    $penandaTb = true;
                }

                continue;
            }

            if ($penandaTb || isset($hariCuti[$t])) {
                continue;
            }

            if (isset($hariKonket[$t]) && (string) $hariKonket[$t]['affect_tukin'] === '1') {
                if ((int) $hariKonket[$t]['kategori'] === self::KATEGORI_KONKET_DIBAYAR) {
                    $dibayar[] = $t;
                }

                continue;
            }

            $masuk  = (string) ($presensi[$t]['masuk'] ?? '');
            $pulang = (string) ($presensi[$t]['pulang'] ?? '');

            if ($masuk !== '' || $pulang !== '') {
                $dibayar[] = $t;
            }
        }

        return $dibayar;
    }

    /**
     * Potongan TB legacy per (TB, bulan): 0 bila TB mulai setelah tanggal 1 di bulan itu, selain itu 25 (`:313-321`).
     */
    public static function potonganTb(string $mulaiInduk, int $tahun, int $bulan): int
    {
        $ym = sprintf('%04d-%02d', $tahun, $bulan);

        return substr($mulaiInduk, 0, 7) === $ym && (int) substr($mulaiInduk, 8, 2) > 1 ? 0 : 25;
    }

    /**
     * @param list<array{id: int|string, mulai_induk: string, dari: string, sampai: string}> $segmen
     *
     * @return array{0: array<string, string>, 1: array<string, int>} pemilik per tanggal (id TB) & potongan per id
     */
    private static function tugasBelajar(array $segmen, string $awal, string $akhir): array
    {
        [$tahun, $bulan] = [(int) substr($awal, 0, 4), (int) substr($awal, 5, 2)];
        $pemilik         = [];
        $potongan        = [];

        foreach ($segmen as $s) {
            $id = (string) $s['id'];

            foreach (self::tanggal(max(substr($s['dari'], 0, 10), $awal), min(substr($s['sampai'], 0, 10), $akhir)) as $t) {
                $pemilik[$t] = $id;
                $potongan[$id] ??= self::potonganTb($s['mulai_induk'], $tahun, $bulan);
            }
        }

        return [$pemilik, $potongan];
    }

    /**
     * @param list<array{0: string, 1: string}> $rentang
     *
     * @return array<string, true>
     */
    private static function tandai(array $rentang, string $awal, string $akhir): array
    {
        $hasil = [];

        foreach ($rentang as [$mulai, $selesai]) {
            foreach (self::tanggal(max(substr($mulai, 0, 10), $awal), min(substr($selesai, 0, 10), $akhir)) as $t) {
                $hasil[$t] = true;
            }
        }

        return $hasil;
    }

    /**
     * @return list<string>
     */
    private static function tanggal(string $mulai, string $selesai): array
    {
        return $mulai > $selesai ? [] : HariKerja::kalender($mulai, $selesai);
    }
}
