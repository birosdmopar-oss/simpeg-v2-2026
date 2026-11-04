<?php

declare(strict_types=1);

namespace App\Libraries\Siasn\Tarik;

use RuntimeException;

/**
 * `siasn:tarik` tidak dijalankan: driver/gateway SIASN bukan adapter BKN nyata (review B6 M1 B-1c). Pesan tanpa nilai rahasia.
 */
final class SiasnTidakSiap extends RuntimeException
{
}
