<?php

declare(strict_types=1);

namespace App\Libraries\Presensi\SlipGaji;

use App\Libraries\Kepegawaian\Kalkulasi\TanggalBisnis;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * CR-027 — format keluaran slip yang tidak bergantung pada UI: tanggal cetak, waktu dibuka, label total, dan nama berkas
 * PDF (P-2, P-5..P-7). Waktu selalu dioper pemanggil; tampilan memakai WIB dan nama bulan Indonesia.
 */
final class FormatSlip
{
    private function __construct()
    {
    }

    /**
     * Tanggal cetak "4 Oktober 2026" (tanggal WIB, tanpa jam) [K] `libraries/hr/Lsl_gaji.php:366-369`; templat menambah
     * "Jakarta, ". Perlu jam atau tidak: TUNGGU-USER (P-5).
     */
    public static function tanggalCetak(DateTimeInterface $sekarang): string
    {
        return TanggalBisnis::formatIndonesia(TanggalBisnis::hariIni($sekarang));
    }

    /**
     * Stempel waktu (mis. `viewed_at` UTC dari DB, sudah dijadikan objek waktu oleh service) → "4 Oktober 2026 10:00 WIB".
     * Legacy memakai tiga format berbeda, salah satunya dengan nama bulan Inggris (P-6, PERBAIKI; format final
     * TUNGGU-USER).
     */
    public static function waktuWib(DateTimeInterface $waktu): string
    {
        $wib = DateTimeImmutable::createFromInterface($waktu)->setTimezone(new DateTimeZone(TanggalBisnis::ZONA));

        return sprintf(
            '%d %s %s %s WIB',
            (int) $wib->format('j'),
            TanggalBisnis::NAMA_BULAN[(int) $wib->format('n')],
            $wib->format('Y'),
            $wib->format('H:i'),
        );
    }

    /**
     * Label baris total "Total Penghasilan Bulan Oktober 2026" [K] `views/hr/employee/sl_gaji/_slip_content.php:97`.
     */
    public static function labelTotal(PeriodeSlip $periode): string
    {
        return 'Total Penghasilan Bulan ' . $periode->label();
    }

    /**
     * Nama berkas PDF `Slip_Gaji_{NAMA}_{Bulan}_{Tahun}.pdf` [K] `Lsl_gaji.php:411`. Nama disanitasi ke `[A-Z0-9_]`
     * (karakter lain → `_`, dipadatkan, dipangkas); bila hasilnya kosong dipakai NIP (P-7, PERBAIKI: legacy hanya
     * memakai NIP bila nama `null`).
     */
    public static function namaBerkasPdf(?string $nama, string $nip, PeriodeSlip $periode): string
    {
        $bagian = self::sanitasi($nama ?? '');

        if ($bagian === '') {
            $bagian = self::sanitasi($nip);
        }

        return sprintf(
            'Slip_Gaji_%s_%s_%d.pdf',
            $bagian === '' ? 'PEGAWAI' : $bagian,
            TanggalBisnis::NAMA_BULAN[$periode->bulan],
            $periode->tahun,
        );
    }

    private static function sanitasi(string $teks): string
    {
        return trim(preg_replace('/[^A-Z0-9]+/', '_', strtoupper($teks)) ?? '', '_');
    }
}
