<?php

declare(strict_types=1);

namespace App\Libraries\MasterData;

/**
 * Hook perubahan status master (opsional, CR-031). Kelas hook yang terdaftar lewat opsi definisi `hooks` boleh
 * MENAMBAHKAN interface ini di samping MasterHooks; master yang hook-nya tidak mengimplementasikannya tidak berubah
 * perilakunya.
 *
 * Dipanggil MasterService di dalam named lock tabel master, sebelum status ditulis, pada setiap jalur yang mengubah
 * status: PATCH status (Aktifkan/Nonaktifkan/Pulihkan), DELETE (soft delete → 10), dan PUT yang membawa field status.
 * Jalur ini tidak melewati MasterHooks::beforeWrite() untuk PATCH status/DELETE, sehingga aturan bisnis yang bergantung
 * pada status (mis. keunikan koordinat lokasi presensi saat dipulihkan, lokasi yang masih dirujuk aturan aktif) harus
 * ditegakkan di sini. Exception (mis. ValidationException → 422) membatalkan perubahan.
 */
interface MasterStatusHooks
{
    /**
     * @param array<string, mixed> $row  baris final setelah perubahan lain di permintaan yang sama (PATCH/DELETE: baris
     *                                   saat ini; PUT: perubahan + baris saat ini)
     * @param string               $from status saat ini ('1' | '2' | '10')
     * @param string               $to   status tujuan ('1' | '2' | '10'), selalu berbeda dari $from
     */
    public function beforeStatusChange(array $row, string $from, string $to): void;
}
