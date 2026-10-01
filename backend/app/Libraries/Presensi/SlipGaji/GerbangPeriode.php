<?php

declare(strict_types=1);

namespace App\Libraries\Presensi\SlipGaji;

use App\Libraries\Kepegawaian\Kalkulasi\TanggalBisnis;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

/**
 * CR-027 (K-CR027-4) — gerbang waktu slip gaji untuk jalur pegawai (G-1..G-6, D-11).
 *
 * `tersedia(P, sekarang) ⇔ P ≥ Januari 2024 ∧ sekarang_WIB ≥ tanggal 4 pukul 10:00:00 WIB bulan P` (batas inklusif).
 * Rilis jatuh pada **bulan yang sama** dengan periodenya: slip Oktober 2026 terbuka mulai 4 Oktober 2026 10:00:00 WIB.
 * [K] `libraries/hr/Lsl_gaji.php:9-12` (konstanta), `:108-137` (`isPeriodeAvailable`, `getAvailableMonths`).
 *
 * Pembanding selalu jam dinding Asia/Jakarta (UTC+7, tanpa DST) apa pun zona objek waktu yang masuk (G-5). Kelas ini
 * tidak membaca jam: `$sekarang` wajib dioper service (`Time::now()`), dan objek milik pemanggil tidak dimutasi
 * (legacy memanggil `setTimezone` pada objek `DateTime` pemanggil, PERBAIKI).
 */
final class GerbangPeriode
{
    public const ZONA = TanggalBisnis::ZONA;

    /**
     * Tanggal rilis dalam bulan periode [K] `Lsl_gaji.php:10`.
     */
    public const HARI_RILIS = 4;

    /**
     * Jam rilis WIB [K] `Lsl_gaji.php:11`.
     */
    public const JAM_RILIS = '10:00:00';

    /**
     * Periode tertua yang pernah tersedia [K] `Lsl_gaji.php:9` (`PERIODE_START_DATE = '2024-01-01'`). Bisa berubah oleh
     * keputusan volume histori D-01 (TUNGGU-USER/DBV).
     */
    public const TAHUN_AWAL = 2024;

    public const BULAN_AWAL = 1;

    private function __construct()
    {
    }

    public static function periodeAwal(): PeriodeSlip
    {
        return PeriodeSlip::buat(self::TAHUN_AWAL, self::BULAN_AWAL);
    }

    /**
     * Waktu rilis periode sebagai jam dinding WIB `Y-m-d H:i:s` (`2026-10-04 10:00:00`).
     */
    public static function waktuRilis(PeriodeSlip $periode): string
    {
        return sprintf('%04d-%02d-%02d %s', $periode->tahun, $periode->bulan, self::HARI_RILIS, self::JAM_RILIS);
    }

    /**
     * Sudah melewati waktu rilis (inklusif). Dibandingkan per detik: ambang jatuh tepat di detik penuh, sehingga
     * `09:59:59.999999` masih "belum" dan `10:00:00.000000` sudah.
     */
    public static function sudahRilis(PeriodeSlip $periode, DateTimeInterface $sekarang): bool
    {
        return self::jamDindingWib($sekarang) >= self::waktuRilis($periode);
    }

    /**
     * Periksa periode yang diminta pegawai. Gagal: `periode_sebelum_awal` (lebih tua dari Januari 2024) atau
     * `periode_belum_rilis` (`detail.waktu_rilis`). Pesan legacy untuk keduanya sama, "Periode slip gaji belum tersedia."
     * (`controllers/hr/Sl_gaji.php:106-108`); v2 membedakan kodenya.
     */
    public static function periksa(PeriodeSlip $periode, DateTimeInterface $sekarang): HasilSlip
    {
        $awal = self::periodeAwal();

        if ($periode->indeks() < $awal->indeks()) {
            return HasilSlip::gagal(
                'periode_sebelum_awal',
                "Periode slip gaji belum tersedia. Periode paling awal adalah {$awal->label()}.",
                ['periode_awal' => $awal->kunci()],
            );
        }

        if (! self::sudahRilis($periode, $sekarang)) {
            return HasilSlip::gagal(
                'periode_belum_rilis',
                sprintf(
                    'Periode slip gaji belum tersedia. Slip %s dapat dibuka mulai %d %s pukul %s WIB.',
                    $periode->label(),
                    self::HARI_RILIS,
                    $periode->label(),
                    substr(self::JAM_RILIS, 0, 5),
                ),
                ['waktu_rilis' => self::waktuRilis($periode)],
            );
        }

        return HasilSlip::berhasil($periode);
    }

    /**
     * Periode terbaru yang tersedia: bulan berjalan (WIB) bila sudah rilis, selain itu bulan sebelumnya; `null` bila
     * hasilnya lebih tua dari periode awal (mis. 1–4 Januari 2024 sebelum 10:00 WIB).
     */
    public static function terbaru(DateTimeInterface $sekarang): ?PeriodeSlip
    {
        $wib      = self::jamDindingWib($sekarang);
        $berjalan = PeriodeSlip::buat((int) substr($wib, 0, 4), (int) substr($wib, 5, 2));
        $terbaru  = self::sudahRilis($berjalan, $sekarang) ? $berjalan : $berjalan->sebelumnya();

        return $terbaru->indeks() < self::periodeAwal()->indeks() ? null : $terbaru;
    }

    /**
     * Daftar periode tersedia, urut naik dari Januari 2024 sampai {@see self::terbaru()} (G-3). Isinya tidak bergantung
     * pada ada-tidaknya data slip; bawaan pilihan = elemen terakhir.
     *
     * @return list<PeriodeSlip>
     */
    public static function daftarTersedia(DateTimeInterface $sekarang): array
    {
        $terbaru = self::terbaru($sekarang);

        if ($terbaru === null) {
            return [];
        }

        $daftar = [];

        for ($periode = self::periodeAwal(); $periode->indeks() <= $terbaru->indeks(); $periode = $periode->berikutnya()) {
            $daftar[] = $periode;
        }

        return $daftar;
    }

    /**
     * Jam dinding WIB `Y-m-d H:i:s` dari objek waktu apa pun, tanpa memutasi objek pemanggil.
     */
    private static function jamDindingWib(DateTimeInterface $sekarang): string
    {
        $teks = DateTimeImmutable::createFromInterface($sekarang)
            ->setTimezone(new DateTimeZone(self::ZONA))
            ->format('Y-m-d H:i:s');

        // Perbandingan string hanya sah untuk tahun 4 digit.
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\z/', $teks) !== 1) {
            throw new InvalidArgumentException("Waktu sekarang di luar tahun 1000-9999: {$teks}");
        }

        return $teks;
    }
}
