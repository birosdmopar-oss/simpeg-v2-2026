<?php

declare(strict_types=1);

namespace App\Libraries\Layanan\StatusHarian;

use RuntimeException;

/**
 * MAKE-062 — run nyata `layanan:status-harian` lain sedang berjalan (kunci `GET_LOCK` dipegang). Pesan disusun aplikasi
 * tanpa NIP/nilai rahasia.
 */
final class StatusHarianSibuk extends RuntimeException
{
}
