<?php

declare(strict_types=1);

namespace App\Libraries\Kepegawaian\Stub;

use App\Exceptions\BelumTersediaException;
use App\Interfaces\Kepegawaian\StrukturServiceInterface;
use App\Libraries\Auth\AuthContext;

/**
 * Stub S0-A (MAKE-002); implementasi nyata WS-2 MAKE-012 (StrukturService). Fail-closed (501).
 */
final class StubStrukturService implements StrukturServiceInterface
{
    public function pohon(AuthContext $auth, array $filter = []): array
    {
        throw new BelumTersediaException('struktur organisasi');
    }
}
