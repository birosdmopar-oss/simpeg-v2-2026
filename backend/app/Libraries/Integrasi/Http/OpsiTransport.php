<?php

declare(strict_types=1);

namespace App\Libraries\Integrasi\Http;

use InvalidArgumentException;

/**
 * Batas waktu & TLS satu layanan (BP-3): koneksi 5 dtk / total 30 dtk bawaan, TLS verify aktif. `verifyTls = false`
 * hanya untuk e-sign BSrE atas keputusan Horii 03-11-2026 (ikut legacy, dicatat di `.env` server). `caBundle` = path
 * berkas CA (di luar repo/webroot) untuk memverifikasi gateway ber-CA internal; null = CA sistem. Redirect tidak diikuti.
 */
final class OpsiTransport
{
    public function __construct(
        public readonly float $connectTimeout = 5.0,
        public readonly float $timeout = 30.0,
        public readonly bool $verifyTls = true,
        public readonly ?string $caBundle = null,
    ) {
        if ($connectTimeout <= 0 || $timeout <= 0 || $connectTimeout > $timeout) {
            throw new InvalidArgumentException('OpsiTransport: batas waktu harus > 0 dan connectTimeout <= timeout.');
        }
    }
}
