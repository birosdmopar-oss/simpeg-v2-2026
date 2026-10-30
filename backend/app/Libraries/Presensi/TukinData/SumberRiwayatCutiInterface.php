<?php

declare(strict_types=1);

namespace App\Libraries\Presensi\TukinData;

/**
 * Daftar riwayat cuti utuh per NIP untuk rekap IL (CR-085): legacy `laporan_tukin_us_skp_il` membangun jadwal potongan
 * cuti dari **seluruh** `$listCuti[nip][id]` (`L_presensi.php:13903-13911`, `:14176-14423`), bukan dari pemenang per
 * tanggal, sehingga riwayat yang semua tanggalnya tertimpa riwayat lain tetap dijadwalkan.
 */
interface SumberRiwayatCutiInterface
{
    /**
     * Riwayat cuti `status = 1` yang beririsan [awal, akhir], urut `tgl_mulai` lalu `id_riwayat_cuti`, satu entri per id.
     * Jenis di luar 1..6 dilewati (galatnya sudah dilaporkan sumber harian).
     *
     * @param list<string> $nip
     *
     * @return array<string, list<array{id: int, jenis: string, mulai: string, akhir: string, lama: int}>> per NIP
     */
    public function riwayatCutiBanyak(array $nip, string $awal, string $akhir): array;
}
