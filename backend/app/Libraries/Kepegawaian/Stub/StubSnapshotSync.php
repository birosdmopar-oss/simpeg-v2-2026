<?php

declare(strict_types=1);

namespace App\Libraries\Kepegawaian\Stub;

use App\Exceptions\BelumTersediaException;
use App\Interfaces\Kepegawaian\SnapshotSyncInterface;
use App\Libraries\Kepegawaian\Riwayat\RiwayatDefinisi;

/**
 * Stub fail-closed S0-A (MAKE-002) sampai SnapshotSync WS-1 M1 (MAKE-004): pemanggilan → 501 (transaksi pemanggil
 * ikut gagal, snapshot tidak pernah ditulis setengah).
 */
final class StubSnapshotSync implements SnapshotSyncInterface
{
    public function sinkronkan(RiwayatDefinisi $definisi, string $nip): void
    {
        throw new BelumTersediaException('sinkron snapshot');
    }
}
