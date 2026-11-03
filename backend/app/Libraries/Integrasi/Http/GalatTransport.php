<?php

declare(strict_types=1);

namespace App\Libraries\Integrasi\Http;

use RuntimeException;
use Throwable;

/**
 * Tidak ada respons HTTP sama sekali (koneksi gagal, TLS, DNS, timeout). Pesan sudah teredaksi oleh {@see KlienHttp}.
 */
final class GalatTransport extends RuntimeException
{
    public function __construct(string $pesan, public readonly bool $timeout = false, ?Throwable $sebelumnya = null)
    {
        parent::__construct($pesan, 0, $sebelumnya);
    }
}
