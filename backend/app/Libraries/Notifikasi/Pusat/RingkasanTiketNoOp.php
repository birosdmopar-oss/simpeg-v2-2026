<?php

declare(strict_types=1);

namespace App\Libraries\Notifikasi\Pusat;

use App\Interfaces\Notifikasi\RingkasanTiketInterface;
use App\Libraries\Auth\AuthContext;

/**
 * Binding sementara penghitung tiket (Q8-06 = B): selalu null sampai Fase 8 menyediakan implementasinya.
 */
final class RingkasanTiketNoOp implements RingkasanTiketInterface
{
    public function jumlahBelumDibaca(AuthContext $auth): ?int
    {
        return null;
    }
}
