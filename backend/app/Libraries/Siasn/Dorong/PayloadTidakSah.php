<?php

declare(strict_types=1);

namespace App\Libraries\Siasn\Dorong;

use RuntimeException;

/**
 * Riwayat lokal tidak bisa dikirim ke SIASN karena datanya tidak lengkap/tidak sah (divalidasi SEBELUM POST). Pesan
 * (tanpa NIP) disimpan ke `siasn_error`; baris tidak dikirim.
 */
final class PayloadTidakSah extends RuntimeException
{
}
