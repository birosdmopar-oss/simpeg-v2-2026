<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Auth antar-sistem (MAKE-058, ADR-036, PRD Fase 6 §7.0.1): klien layanan (HTTP Basic) dan token SIMAPI. Nilai bawaan
 * mengikuti legacy [K] atau keputusan reviewer; tidak ada rahasia di sini.
 */
class KlienLayanan extends BaseConfig
{
    /** Masa aktif token SIMAPI, detik (legacy: 1 jam, `A_auth.php:77-78`, F6-05). */
    public int $tokenTtlDetik = 3600;

    /** Panjang token opak SIMAPI (legacy 30 karakter, F6-05); karakter `[A-Za-z0-9]`. */
    public int $tokenPanjang = 30;

    /** Panjang rahasia klien yang dibangkitkan command `integrasi:klien` (karakter `[A-Za-z0-9_-]`). */
    public int $rahasiaPanjang = 40;

    /** Batas panjang nilai header `Authorization` yang diurai (karakter); lebih panjang ditolak sebelum diproses. */
    public int $headerMaks = 512;

    /** Throttle per klien terautentikasi: permintaan per `throttleDetik`. */
    public int $throttleKlienKapasitas = 600;

    /** Throttle percobaan auth per IP (termasuk yang gagal): permintaan per `throttleDetik`. */
    public int $throttleIpKapasitas = 120;

    public int $throttleDetik = 60;

    /** Token kedaluwarsa lebih tua dari ini (hari) dihapus `integrasi:klien bersihkan-token` (DBV-026 §13.2 A2). */
    public int $tokenRetensiHari = 7;
}
