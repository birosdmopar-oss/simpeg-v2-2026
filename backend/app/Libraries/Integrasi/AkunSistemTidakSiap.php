<?php

declare(strict_types=1);

namespace App\Libraries\Integrasi;

use RuntimeException;

/**
 * Job integrasi tidak boleh menulis tanpa pelaku akun sistem (DBV-027 Z-1): akun belum disiapkan atau menyimpang.
 */
final class AkunSistemTidakSiap extends RuntimeException
{
}
