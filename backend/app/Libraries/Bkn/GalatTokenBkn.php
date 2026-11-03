<?php

declare(strict_types=1);

namespace App\Libraries\Bkn;

use RuntimeException;

/**
 * Token BKN tidak bisa diperoleh (respons token gagal/tanpa `access_token`). Pesan tidak memuat kredensial/token; rinciannya
 * (teredaksi) ada di `integrasi_log`.
 */
final class GalatTokenBkn extends RuntimeException
{
}
