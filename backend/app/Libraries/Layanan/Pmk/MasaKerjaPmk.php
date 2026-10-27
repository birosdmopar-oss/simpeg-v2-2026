<?php

declare(strict_types=1);

namespace App\Libraries\Layanan\Pmk;

use App\Libraries\Layanan\Studi\RentangTanggal;

/**
 * MAKE-024 (bagian murni) — kalkulator `mker_th`/`mker_bl` untuk `POST pmk/hitung-mker` (PRD Fase 4 v1.1 §6.9.1; nilai
 * pengajuan tetap **dari klien** [K D19/P-4, Q-03] — server tidak menghitung ulang saat simpan).
 *
 * Rumus [K `controllers/hr/services/Local.php:4574-4601`]: `diff = DateTime(tgl_awal)->diff(DateTime(tgl_akhir))`;
 * `mker_th = diff.y`, `mker_bl = diff.m`; sisa hari dibuang, tanggal akhir tidak inklusif. Contoh [K]: 01-01-2024 s.d.
 * 31-12-2025 = 1 th 11 bl; 29-02-2024 s.d. 28-02-2025 = 0 th 11 bl; 01-03-2024 s.d. 31-03-2024 = 0 th 0 bl.
 */
final class MasaKerjaPmk
{
    /** [K] pesan validasi klien `views/hr/employee/rwy/pmk/req/form.php:333-338` (server legacy tidak menolak). */
    public const PESAN_KURANG_SEBULAN = 'Lama masa kerja harus melebihi 1 bulan.';

    private function __construct()
    {
    }

    /**
     * Pemanggil wajib memastikan kedua tanggal sah dan `tgl_akhir >= tgl_awal` ({@see ValidasiPmk}).
     *
     * @return array{mker_th: int, mker_bl: int}
     */
    public static function hitung(string $tglAwal, string $tglAkhir): array
    {
        $s = RentangTanggal::selisih($tglAwal, $tglAkhir);

        return ['mker_th' => $s['tahun'], 'mker_bl' => $s['bulan']];
    }

    /**
     * Penanda klien [K]: hasil 0 th 0 bl dianggap kurang dari sebulan (peringatan kalkulator, bukan penolakan server).
     */
    public static function kurangSebulan(int $tahun, int $bulan): bool
    {
        return $tahun === 0 && $bulan === 0;
    }
}
