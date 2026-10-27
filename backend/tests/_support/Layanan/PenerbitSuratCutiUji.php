<?php

declare(strict_types=1);

namespace Tests\Support\Layanan;

use App\Libraries\Layanan\Cuti\PenerbitSuratCutiNoOp;

/**
 * Penerbit surat uji: menghitung pemanggilan `terbitkanBelumTte` (harus tepat sekali pada transisi final ke 1/4).
 */
final class PenerbitSuratCutiUji extends PenerbitSuratCutiNoOp
{
    public int $dipanggil = 0;

    public function terbitkanBelumTte(array $cuti): void
    {
        $this->dipanggil++;
    }
}
