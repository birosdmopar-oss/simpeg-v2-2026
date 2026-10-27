<?php

declare(strict_types=1);

namespace App\Interfaces\Layanan;

/**
 * Kontrak "TB penuh bebas presensi" untuk Fase 5 (D-06) — PRD Fase 4 v1.1 §6.7.5, §8, D15. Implementasi ber-DB
 * (`App\Libraries\Layanan\Tb\StatusTugasBelajar`, M5 setelah DBV-022) memuat `riwayat_tb` status 1 + `riwayat_tb_perpan`
 * milik NIP lalu mendelegasikan aturan ke `App\Libraries\Layanan\Tb\BebasPresensiTb` (murni). Didaftarkan sebagai
 * `Config\Services::statusTugasBelajar()` saat implementasi ada.
 */
interface StatusTugasBelajarInterface
{
    /**
     * Pegawai `$nip` bebas presensi penuh pada `$tanggal` (`YYYY-MM-DD`): ada TB disetujui yang rentangnya, atau rentang
     * salah satu perpanjangannya, memuat tanggal itu (inklusif).
     */
    public function bebasPresensiPenuh(string $nip, string $tanggal): bool;

    /**
     * Tanggal-tanggal bebas presensi di `[$dari, $sampai]` (inklusif, urut) — satu kueri untuk satu periode laporan.
     *
     * @return list<string>
     */
    public function rentangBebasPresensi(string $nip, string $dari, string $sampai): array;
}
