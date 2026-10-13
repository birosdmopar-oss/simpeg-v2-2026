<?php

declare(strict_types=1);

namespace App\Libraries\Kepegawaian\Stub;

use App\Exceptions\BelumTersediaException;
use App\Interfaces\Kepegawaian\PegawaiServiceInterface;
use App\Libraries\Auth\AuthContext;

/**
 * Stub S0-A (MAKE-002). Implementasi nyata `App\Libraries\Kepegawaian\Pegawai\PegawaiService` sejak MAKE-010 (dipasang
 * di Services.php); stub tetap fail-closed (BelumTersediaException → 501).
 */
final class StubPegawaiService implements PegawaiServiceInterface
{
    public function daftar(AuthContext $auth, array $filter = []): array
    {
        throw new BelumTersediaException('daftar pegawai');
    }

    public function detail(AuthContext $auth, string $nip): array
    {
        throw new BelumTersediaException('detail pegawai');
    }
}
