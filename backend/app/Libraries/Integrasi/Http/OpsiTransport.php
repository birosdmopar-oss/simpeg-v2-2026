<?php

declare(strict_types=1);

namespace App\Libraries\Integrasi\Http;

use InvalidArgumentException;

/**
 * Batas waktu & TLS satu layanan (BP-3): koneksi 5 dtk / total 30 dtk bawaan, TLS verify aktif. `verifyTls = false`
 * hanya untuk gateway internal tanpa TLS yang dicatat di `.env` (mis. BSrE jaringan internal).
 */
final class OpsiTransport
{
    public function __construct(
        public readonly float $connectTimeout = 5.0,
        public readonly float $timeout = 30.0,
        public readonly bool $verifyTls = true,
    ) {
        if ($connectTimeout <= 0 || $timeout <= 0 || $connectTimeout > $timeout) {
            throw new InvalidArgumentException('OpsiTransport: batas waktu harus > 0 dan connectTimeout <= timeout.');
        }
    }
}
