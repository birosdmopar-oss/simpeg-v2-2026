<?php

declare(strict_types=1);

namespace App\Libraries\Kepegawaian\Nip;

/**
 * CR-036 — status tabel legacy di v2 menurut dokumen fase (`0x-*.md`), Mapping Migrasi, dan draf skema yang sudah ada.
 */
enum KeberadaanV2: string
{
    /**
     * Tabel dibuat di v2; fase dan task pemilik migration-nya diketahui.
     */
    case Ada = 'ada';

    /**
     * Tabel tidak dibuat di v2 (cadangan bertanggal, arsip, atau tidak dipakai kode legacy).
     */
    case TidakDiimpor = 'tidak_diimpor';

    /**
     * Belum ada dokumen yang memutuskan; alasan di `catatan`.
     */
    case BelumDiputuskan = 'belum_diputuskan';
}
