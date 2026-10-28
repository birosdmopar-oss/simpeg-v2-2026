<?php

declare(strict_types=1);

namespace App\Libraries\Presensi\Rekap;

use App\Libraries\Kepegawaian\Kalkulasi\TanggalBisnis;
use App\Libraries\Presensi\Tukin\HariKerja;
use App\Libraries\Presensi\Tukin\JamKerja;
use InvalidArgumentException;

/**
 * Klasifikasi rekap kehadiran satu pegawai × satu bulan kalender (PRD 5B §7.4.1, D-09b) — **murni**. Port
 * `L_presensi::rekap` (`L_presensi.php:415-810`, ringkas) dan `rekap_full_xls` (`:824-1436`, harian) legacy `0cf7e67` [K].
 *
 * Bulan kalender 1..akhir, dipotong `$hariIni` [K `:444-455`]. Hari kerja = Senin–Jumat yang bukan hari libur. Setiap hari
 * kerja diklasifikasikan menurut **cabang pertama yang cocok**:
 * 1. [V2] **TB** (rentang bebas presensi TB, F5B-10) → `TB` (legacy HTML tidak mengenal TB sehingga hari TB jatuh ke TK;
 *    ekspor harian legacy memeriksa TB lebih dulu `:1087-1099`). Tidak dihitung di kolom lain.
 * 2. Cuti (`riwayat_cuti` status 1, jenis apa pun) → `CT`, cuti + tidak hadir [K `:644-648`].
 * 3. Konket `V` (affect_tukin apa pun) — kategori 8 `DL` dinas luar + tidak hadir; 9 `CT` cuti + tidak hadir; 10 `SKT`
 *    sakit + tidak hadir; 13 `LL` lain-lain + **hadir**; kategori lain → tidak masuk kolom mana pun, kode kosong
 *    [K `:652-675`, `:1110-1129`]. Konket yang menutupi tanggal sama → baris terakhir menurut urutan masukan.
 * 4. Tanpa jam masuk & pulang → `TK`, TK + tidak hadir [K `:690-694`].
 * 5. Selain itu `H` hadir. Dengan jam standar {@see JadwalKerjaRekap} (selisih menit penuh `JamKerja::selisihMenit`):
 *    masuk & pulang → TL bila `tl > 60`, atau `30 < tl ≤ 60` dan `psw > −60`, atau `0 < tl ≤ 30` dan `psw > −30`; PSW bila
 *    `psw > 0` [K `:722-740`]; hanya pulang → PSW bila `psw > 0`; hanya masuk → TL bila `tl > 0` (tanpa kompensasi)
 *    [K `:748-806`]. TL/PSW menghitung hari, bukan menit.
 *
 * `tidak_hadir` = cuti + dinas luar + sakit + TK [K]; `tb` [V2] tidak masuk `tidak_hadir`. Hari non-kerja di grid harian =
 * `weekend` / `libur` (Sabtu/Minggu didahulukan, [K `:899-914`]).
 */
final class KlasifikasiKehadiran
{
    public const KOLOM = ['hari_kerja', 'hadir', 'tl', 'psw', 'tk', 'sakit', 'dinas_luar', 'lain_lain', 'cuti', 'tidak_hadir', 'tb'];

    public const WEEKEND = 'weekend';

    public const LIBUR = 'libur';

    private const KONKET = [8 => ['DL', 'dinas_luar', false], 9 => ['CT', 'cuti', false], 10 => ['SKT', 'sakit', false], 13 => ['LL', 'lain_lain', true]];

    private function __construct()
    {
    }

    /**
     * @param list<string>                                                      $libur    tanggal libur `Y-m-d`
     * @param list<array{0: string, 1: string}>                                 $cuti     rentang cuti status 1
     * @param list<array{0: string, 1: string}>                                 $tb       rentang bebas presensi TB
     * @param list<array{mulai: string, akhir: string, kategori: int|string}>   $konket   konket status `V`, urut naik
     * @param array<string, array{masuk?: ?string, pulang?: ?string}>           $presensi per tanggal
     *
     * @return array{ringkas: array<string, int>, harian: array<string, string|null>} `harian` = setiap tanggal kalender
     *         sampai batas → kode (`TB`/`CT`/`DL`/`SKT`/`LL`/`H`/`TK`), `null` (konket kategori lain), `weekend`, `libur`
     */
    public static function rekap(
        int $tahun,
        int $bulan,
        ?string $hariIni,
        array $libur,
        array $cuti,
        array $tb,
        array $konket,
        array $presensi,
        JadwalKerjaRekap $jadwal,
    ): array {
        if ($bulan < 1 || $bulan > 12) {
            throw new InvalidArgumentException('Bulan tidak sah.');
        }

        $awal    = sprintf('%04d-%02d-01', $tahun, $bulan);
        $akhir   = HariKerja::kemarin(TanggalBisnis::tambahBulan($awal, 1));
        $batas   = $hariIni !== null && TanggalBisnis::wajibValid($hariIni, 'hari ini') < $akhir ? $hariIni : $akhir;
        $ringkas = array_fill_keys(self::KOLOM, 0);
        $harian  = [];

        if ($batas < $awal) {
            return ['ringkas' => $ringkas, 'harian' => []];
        }

        $hariLibur  = array_fill_keys($libur, true);
        $hariCuti   = self::tandai($cuti, $awal, $akhir);
        $hariTb     = self::tandai($tb, $awal, $akhir);
        $hariKonket = [];

        foreach ($konket as $k) {
            foreach (self::tanggal(max(substr($k['mulai'], 0, 10), $awal), min(substr($k['akhir'], 0, 10), $akhir)) as $t) {
                $hariKonket[$t] = (int) $k['kategori'];
            }
        }

        foreach (HariKerja::kalender($awal, $batas) as $t) {
            if (HariKerja::nomorHari($t) >= 6) {
                $harian[$t] = self::WEEKEND;

                continue;
            }

            if (isset($hariLibur[$t])) {
                $harian[$t] = self::LIBUR;

                continue;
            }

            $ringkas['hari_kerja']++;
            $harian[$t] = self::hari($t, $hariTb, $hariCuti, $hariKonket, $presensi[$t] ?? [], $jadwal, $ringkas);
        }

        return ['ringkas' => $ringkas, 'harian' => $harian];
    }

    /**
     * @param array<string, true>                         $hariTb
     * @param array<string, true>                         $hariCuti
     * @param array<string, int>                          $hariKonket
     * @param array{masuk?: ?string, pulang?: ?string}   $absen
     * @param array<string, int>                          $ringkas
     */
    private static function hari(string $t, array $hariTb, array $hariCuti, array $hariKonket, array $absen, JadwalKerjaRekap $jadwal, array &$ringkas): ?string
    {
        if (isset($hariTb[$t])) {
            $ringkas['tb']++;

            return 'TB';
        }

        if (isset($hariCuti[$t])) {
            $ringkas['cuti']++;
            $ringkas['tidak_hadir']++;

            return 'CT';
        }

        if (isset($hariKonket[$t])) {
            [$kode, $kolom, $hadir] = self::KONKET[$hariKonket[$t]] ?? [null, null, false];

            if ($kolom !== null) {
                $ringkas[$kolom]++;
                $ringkas[$hadir ? 'hadir' : 'tidak_hadir']++;
            }

            return $kode;
        }

        $masuk  = trim((string) ($absen['masuk'] ?? ''));
        $pulang = trim((string) ($absen['pulang'] ?? ''));

        if ($masuk === '' && $pulang === '') {
            $ringkas['tk']++;
            $ringkas['tidak_hadir']++;

            return 'TK';
        }

        $ringkas['hadir']++;
        $standar = $jadwal->untuk($t);
        $tl      = $masuk === '' ? null : JamKerja::selisihMenit($standar['masuk'], JamKerja::normalisasiJam($masuk));
        $psw     = $pulang === '' ? null : JamKerja::selisihMenit(JamKerja::normalisasiJam($pulang), $standar['pulang']);

        if ($tl !== null && ($psw === null ? $tl > 0 : ($tl > 60 || ($tl > 30 && $psw > -60) || ($tl > 0 && $tl <= 30 && $psw > -30)))) {
            $ringkas['tl']++;
        }

        if ($psw !== null && $psw > 0) {
            $ringkas['psw']++;
        }

        return 'H';
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
