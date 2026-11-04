<?php

declare(strict_types=1);

namespace App\Interfaces;

/**
 * Dorong riwayat ke SIASN BKN (H-03, MAKE-067 M2; PRD Fase 6 §7.3) — kebutuhan tambahan di luar
 * {@see SiasnGatewayInterface} (kontraknya tidak diubah). POST tidak di-retry otomatis (BP-3); kegagalan tidak dilempar.
 */
interface SiasnDorongInterface
{
    /**
     * Simpan satu riwayat ke SIASN [K `postApi_siasn`]. `jalur` = path relatif (mis. `diklat/save`).
     *
     * `status_code`: `'200'` bila HTTP 2xx dan `success === true`; selain itu kode HTTP, `'respons'` (badan tidak sah /
     * `success` bukan true), `'timeout'` / `'koneksi'` (status di SIASN tidak diketahui), atau `'token'`. `data` =
     * `mapData` balasan (array atau skalar) saat sukses, selain itu null. `message` = pesan singkat tanpa host/token.
     *
     * @param array<string, mixed> $payload
     * @param list<string>         $rahasia nilai yang disensor dari log (mis. id PNS SIASN)
     *
     * @return array{status_code: string, message: string, data: mixed}
     */
    public function simpanRiwayat(string $jalur, array $payload, array $rahasia = []): array;
}
