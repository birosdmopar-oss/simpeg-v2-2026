<?php

declare(strict_types=1);

namespace App\Libraries\Kepegawaian\Kalkulasi;

use App\Libraries\MasterData\HariLiburRules;
use CodeIgniter\I18n\Time;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

/**
 * CR-025 (B-21) — tanggal bisnis Kepegawaian: TMT, tanggal SK, dan akhir hukuman disiplin adalah tanggal kalender
 * tanpa zona, `string` `YYYY-MM-DD` dari kolom DB `DATE` sampai JSON dan FE (K-CR025-2). Tidak pernah dikonversi ke
 * stempel waktu UTC.
 *
 * Aritmetika bulan/tahun **dijepit ke akhir bulan**, sama dengan MySQL `DATE_ADD` (`2024-02-29` + 2 tahun =
 * `2026-02-28`), bukan `DateTime::modify` legacy yang meluap ke bulan berikutnya (`2024-02-29` + 2 tahun = `2026-03-01`;
 * `2026-01-31` + 1 bulan = `2026-03-03`) — K-CR025-3. Selisih bulan penuh didefinisikan konsisten dengan penambahan ini.
 *
 * "Hari ini" dibaca dalam zona Asia/Jakarta (legacy `config/config.php:4` `date_default_timezone_set("Asia/Jakarta")`),
 * sedangkan v2 memakai `appTimezone = 'UTC'` (`app/Config/App.php`). Hanya {@see self::hariIni()} yang boleh membaca jam
 * di namespace ini (K-CR025-1, K-CR025-11).
 */
final class TanggalBisnis
{
    /**
     * Zona "hari ini" bisnis [K] legacy `config/config.php:4`.
     */
    public const ZONA = 'Asia/Jakarta';

    /**
     * Nama bulan untuk pesan pengguna [K] legacy `config/constants.php:269`.
     */
    public const NAMA_BULAN = [
        1  => 'Januari',
        2  => 'Februari',
        3  => 'Maret',
        4  => 'April',
        5  => 'Mei',
        6  => 'Juni',
        7  => 'Juli',
        8  => 'Agustus',
        9  => 'September',
        10 => 'Oktober',
        11 => 'November',
        12 => 'Desember',
    ];

    private function __construct()
    {
    }

    /**
     * Tanggal input yang diterima: tepat `YYYY-MM-DD`, tanggal kalender nyata, tahun 1900–2100. Didelegasikan ke
     * {@see HariLiburRules::isValidDate()} agar aturan format satu sumber.
     */
    public static function valid(string $tanggal): bool
    {
        return HariLiburRules::isValidDate($tanggal);
    }

    /**
     * Validasi tanggal masukan pengguna. `null` bila valid; selain itu hasil gagal `tanggal_wajib` (kosong/spasi) atau
     * `tanggal_tidak_valid` dengan label field di pesan (K-CR025-4).
     */
    public static function periksa(string $tanggal, string $label): ?HasilKalkulasi
    {
        if (trim($tanggal) === '') {
            return HasilKalkulasi::gagal('tanggal_wajib', "{$label} wajib diisi.");
        }

        if (! self::valid($tanggal)) {
            return HasilKalkulasi::gagal(
                'tanggal_tidak_valid',
                "{$label} tidak valid. Gunakan tanggal kalender dengan format YYYY-MM-DD.",
            );
        }

        return null;
    }

    /**
     * Penjaga data sistem (riwayat dari DB, parameter "hari ini"): tanggal di luar {@see self::valid()} adalah
     * kesalahan program/data, bukan kesalahan input pengguna → `InvalidArgumentException` (fail loud, K-CR025-4).
     */
    public static function wajibValid(string $tanggal, string $nama): string
    {
        if (! self::valid($tanggal)) {
            throw new InvalidArgumentException(sprintf(
                '%s bukan tanggal YYYY-MM-DD yang valid (tahun %d-%d): %s',
                $nama,
                HariLiburRules::YEAR_MIN,
                HariLiburRules::YEAR_MAX,
                var_export($tanggal, true),
            ));
        }

        return $tanggal;
    }

    /**
     * Tanggal hari ini di zona Asia/Jakarta. Antara 00:00–06:59 WIB tanggal UTC masih kemarin, sehingga tanggal dari jam
     * UTC keliru satu hari. `$sekarang` untuk test/pemanggil yang sudah memegang waktu; `null` → `Time::now()` (ikut
     * `Time::setTestNow()`).
     */
    public static function hariIni(?DateTimeInterface $sekarang = null): string
    {
        if ($sekarang === null) {
            return Time::now(self::ZONA)->format('Y-m-d');
        }

        return DateTimeImmutable::createFromInterface($sekarang)
            ->setTimezone(new DateTimeZone(self::ZONA))
            ->format('Y-m-d');
    }

    /**
     * Tambah `$bulan` (≥ 0) bulan kalender; hari dijepit ke hari terakhir bulan tujuan (`2026-01-31` + 1 =
     * `2026-02-28`). Tidak asosiatif: (`2026-01-31` + 1) + 1 = `2026-03-28`, sedangkan `2026-01-31` + 2 = `2026-03-31`,
     * jadi siklus berulang selalu dihitung dari tanggal acuan asli.
     *
     * Hasil boleh melewati tahun 2100 (mis. 2099 + 255 bulan); batas 1900–2100 hanya untuk input.
     */
    public static function tambahBulan(string $tanggal, int $bulan): string
    {
        if ($bulan < 0) {
            throw new InvalidArgumentException("Jumlah bulan tidak boleh negatif: {$bulan}");
        }

        [$tahun, $bulanAsal, $hari] = self::urai($tanggal);

        $indeks      = $tahun * 12 + ($bulanAsal - 1) + $bulan;
        $tahunTujuan = intdiv($indeks, 12);
        $bulanTujuan = $indeks % 12 + 1;

        if ($tahunTujuan > 9999) {
            throw new InvalidArgumentException("Hasil penambahan melewati tahun 9999: {$tanggal} + {$bulan} bulan");
        }

        return self::susun($tahunTujuan, $bulanTujuan, min($hari, self::jumlahHari($tahunTujuan, $bulanTujuan)));
    }

    /**
     * Tambah `$tahun` (≥ 0) tahun = {@see self::tambahBulan()} 12 × `$tahun` (`2024-02-29` + 2 = `2026-02-28`).
     */
    public static function tambahTahun(string $tanggal, int $tahun): string
    {
        if ($tahun < 0) {
            throw new InvalidArgumentException("Jumlah tahun tidak boleh negatif: {$tahun}");
        }

        return self::tambahBulan($tanggal, 12 * $tahun);
    }

    /**
     * Jumlah bulan penuh `n` dari `$dari` sampai `$sampai` (`$dari` ≤ `$sampai`), yaitu `n` terbesar dengan
     * `tambahBulan($dari, n) ≤ $sampai < tambahBulan($dari, n + 1)`. Sama dengan `DateTime::diff` legacy kecuali bila
     * hari awal 29–31 (`2024-02-29` → `2026-02-28` = 24 bulan, `diff` = 23); TMT legacy praktis selalu tanggal 1.
     */
    public static function selisihBulanPenuh(string $dari, string $sampai): int
    {
        [$tahunDari, $bulanDari]     = self::urai($dari);
        [$tahunSampai, $bulanSampai] = self::urai($sampai);

        if ($dari > $sampai) {
            throw new InvalidArgumentException("Tanggal awal {$dari} setelah tanggal akhir {$sampai}");
        }

        $bulan = ($tahunSampai * 12 + $bulanSampai) - ($tahunDari * 12 + $bulanDari);

        // Bulan kalender yang sama tetapi hari tujuan belum tercapai → bulan terakhir belum penuh (paling banyak sekali).
        while ($bulan > 0 && self::tambahBulan($dari, $bulan) > $sampai) {
            $bulan--;
        }

        return $bulan;
    }

    /**
     * Tanggal 1 pada bulan yang sama (`2026-04-15` → `2026-04-01`).
     */
    public static function awalBulan(string $tanggal): string
    {
        [$tahun, $bulan] = self::urai($tanggal);

        return self::susun($tahun, $bulan, 1);
    }

    /**
     * Format pesan pengguna "1 April 2027" (legacy `dateToUserFull_local`, `helpers/function_helper.php:1170-1174`).
     */
    public static function formatIndonesia(string $tanggal): string
    {
        [$tahun, $bulan, $hari] = self::urai($tanggal);

        return sprintf('%d %s %d', $hari, self::NAMA_BULAN[$bulan], $tahun);
    }

    /**
     * Pecah tanggal kalender `YYYY-MM-DD` menjadi [tahun, bulan, hari]. Hanya format + `checkdate` (tanpa batas
     * 1900–2100) agar hasil aritmetika di atas 2100 tetap bisa dirangkai; string lain → `InvalidArgumentException`.
     *
     * @return array{0: int, 1: int, 2: int}
     */
    public static function urai(string $tanggal): array
    {
        if (preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})\z/', $tanggal, $m) !== 1
            || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            throw new InvalidArgumentException('Bukan tanggal kalender YYYY-MM-DD: ' . var_export($tanggal, true));
        }

        return [(int) $m[1], (int) $m[2], (int) $m[3]];
    }

    /**
     * Susun `YYYY-MM-DD`; pemanggil menjamin tanggal ada (hari sudah dijepit).
     */
    public static function susun(int $tahun, int $bulan, int $hari): string
    {
        return sprintf('%04d-%02d-%02d', $tahun, $bulan, $hari);
    }

    /**
     * Jumlah hari dalam bulan, kalender Gregorius (tanpa ext-calendar `cal_days_in_month`).
     */
    public static function jumlahHari(int $tahun, int $bulan): int
    {
        return match ($bulan) {
            2           => self::kabisat($tahun) ? 29 : 28,
            4, 6, 9, 11 => 30,
            default     => 31,
        };
    }

    private static function kabisat(int $tahun): bool
    {
        return ($tahun % 4 === 0 && $tahun % 100 !== 0) || $tahun % 400 === 0;
    }
}
