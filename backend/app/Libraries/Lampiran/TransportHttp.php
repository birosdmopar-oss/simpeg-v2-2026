<?php

declare(strict_types=1);

namespace App\Libraries\Lampiran;

/**
 * Pengambil satu URL untuk `lampiran:tarik`. Implementasi wajib: TLS terverifikasi, redirect TIDAK diikuti, batas waktu.
 * Test memakai transport palsu (tanpa jaringan).
 */
interface TransportHttp
{
    /**
     * @return array{status: int, tipe: string, lokasi: string|null, isi: string} status 0 = galat transport (timeout, DNS, TLS),
     *                                                                         -1 = respons melebihi batas ukuran (tidak diulang)
     */
    public function ambil(string $url): array;
}
