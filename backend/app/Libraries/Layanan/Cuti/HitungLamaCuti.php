<?php

declare(strict_types=1);

namespace App\Libraries\Layanan\Cuti;

use App\Libraries\Kepegawaian\Kalkulasi\TanggalBisnis;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * MAKE-022 Tahap 2b — lama cuti (D9, PRD Fase 4 v1.1 §6.3.2), murni: input tanggal `YYYY-MM-DD` + daftar tanggal libur
 * (sudah disaring `status = 1` oleh pemanggil lewat `HariLiburService::tanggalLibur()`), output jumlah hari.
 *
 * - Hari kerja (jenis 1/3/5): Senin–Jumat yang bukan tanggal libur, inklusif [K `FH:997-999`, `FH:2175-2215`]. Libur
 *   yang jatuh di Sabtu/Minggu tidak dihitung dua kali; cuti bersama yang tercatat sebagai hari libur ikut dikurangi
 *   (tidak memotong kuota) [K]. Dihitung sendiri (keputusan #13) dengan test paritas terhadap
 *   `Presensi\Tukin\HariKerja::hariKerja()`; `HariKerja`/`HariLiburService` tidak diubah.
 * - Hari kalender (jenis 2/4/6): inklusif (`tgl_mulai = tgl_akhir` → 1, Q-24).
 * - `tgl_akhir` cuti Besar: tanggal yang sama pada bulan `tgl_mulai + durasi`, dijepit ke akhir bulan bila tidak ada,
 *   dikurangi 1 hari [K `addMonth` `FH:960-966`; keputusan #2]: 15-01 + 1 → 14-02; 31-01-2025 + 1 → 27-02-2025;
 *   31-01-2024 + 1 → 28-02-2024; 31-03 + 1 → 29-04. BUKAN PHP `+1 month`.
 *
 * Rentang terbalik (`akhir < mulai`) → 0 hari (validasi tanggal adalah urusan `ValidasiTanggalCuti`).
 */
final class HitungLamaCuti
{
    private function __construct()
    {
    }

    /**
     * Lama cuti menurut satuan jenisnya; jenis tidak dikenal → `InvalidArgumentException`.
     *
     * @param list<string> $hariLibur
     */
    public static function lama(int $jenis, string $mulai, string $akhir, array $hariLibur, AturanCuti $aturan): int
    {
        return $aturan->satuanHariKerja($jenis)
            ? self::hariKerja($mulai, $akhir, $hariLibur)
            : self::hariKalender($mulai, $akhir);
    }

    /**
     * @param list<string> $hariLibur
     */
    public static function hariKerja(string $mulai, string $akhir, array $hariLibur): int
    {
        $libur = self::himpunanLibur($hariLibur);
        $n     = 0;

        foreach (self::kalender($mulai, $akhir) as $tanggal) {
            if (self::hariKerjaTunggal($tanggal, $libur)) {
                $n++;
            }
        }

        return $n;
    }

    public static function hariKalender(string $mulai, string $akhir): int
    {
        return count(self::kalender($mulai, $akhir));
    }

    /**
     * Satu tanggal: Senin–Jumat dan bukan libur. Dipakai test paritas terhadap `HariKerja::hariKerja()`.
     *
     * @param array<string, true> $libur
     */
    public static function hariKerjaTunggal(string $tanggal, array $libur): bool
    {
        return ! self::akhirPekan($tanggal) && ! isset($libur[$tanggal]);
    }

    /** Sabtu/Minggu: `date('N') >= 6` [K `FH:997-999`]. */
    public static function akhirPekan(string $tanggal): bool
    {
        return (int) self::tanggal($tanggal)->format('N') >= 6;
    }

    /**
     * Tanggal libur dalam rentang yang benar-benar mengurangi hari kerja (bukan akhir pekan) — untuk respons
     * `hari_libur[]` kalkulator (PRD §6.3.7).
     *
     * @param list<string> $hariLibur
     *
     * @return list<string>
     */
    public static function liburTerpakai(string $mulai, string $akhir, array $hariLibur): array
    {
        $libur = self::himpunanLibur($hariLibur);
        $hasil = [];

        foreach (self::kalender($mulai, $akhir) as $tanggal) {
            if (isset($libur[$tanggal]) && ! self::akhirPekan($tanggal)) {
                $hasil[] = $tanggal;
            }
        }

        return $hasil;
    }

    /**
     * `tgl_akhir` cuti Besar dari `tgl_mulai` + `durasi` bulan (lihat docblock kelas). Durasi di luar pilihan →
     * `InvalidArgumentException` (validasi input ada di `ValidasiTanggalCuti`).
     */
    public static function tglAkhirBesar(string $mulai, int $durasi, AturanCuti $aturan): string
    {
        if (! in_array($durasi, $aturan->durasiBesar, true)) {
            throw new InvalidArgumentException("Durasi cuti besar tidak dikenal: {$durasi}");
        }

        return self::tanggal(TanggalBisnis::tambahBulan($mulai, $durasi))->modify('-1 day')->format('Y-m-d');
    }

    /**
     * Himpunan tanggal libur (duplikat digabung, setiap tanggal divalidasi).
     *
     * @param list<string> $hariLibur
     *
     * @return array<string, true>
     */
    public static function himpunanLibur(array $hariLibur): array
    {
        $hasil = [];

        foreach ($hariLibur as $tanggal) {
            $hasil[TanggalBisnis::wajibValid($tanggal, 'hari libur')] = true;
        }

        return $hasil;
    }

    /**
     * Semua tanggal kalender `mulai..akhir` inklusif; kosong bila `mulai > akhir`.
     *
     * @return list<string>
     */
    public static function kalender(string $mulai, string $akhir): array
    {
        $tanggal = self::tanggal($mulai);
        $batas   = self::tanggal($akhir);
        $hasil   = [];

        while ($tanggal <= $batas) {
            $hasil[] = $tanggal->format('Y-m-d');
            $tanggal = $tanggal->modify('+1 day');
        }

        return $hasil;
    }

    /**
     * Parse `YYYY-MM-DD` dengan jam nol (`!` — bukan jam sekarang).
     */
    public static function tanggal(string $tanggal): DateTimeImmutable
    {
        $hasil = DateTimeImmutable::createFromFormat('!Y-m-d', TanggalBisnis::wajibValid($tanggal, 'tanggal'));

        if ($hasil === false) {
            throw new InvalidArgumentException("Tanggal tidak valid: {$tanggal}");
        }

        return $hasil;
    }
}
