<?php

declare(strict_types=1);

namespace App\Libraries\Presensi\Slip;

use RuntimeException;

/**
 * UPDATE bersyarat buka/buka ulang slip mengenai 0 baris (balapan, README aturan 3) — ditangkap service → rollback + 422.
 */
final class SlipSudahDibuka extends RuntimeException
{
}
