<?php

declare(strict_types=1);

namespace App\Libraries\Kepegawaian\Stub;

use App\Exceptions\BelumTersediaException;
use App\Interfaces\Kepegawaian\NipCascadeInterface;
use App\Libraries\Auth\AuthContext;

/**
 * Stub S0-A (MAKE-002). Implementasi nyata `App\Libraries\Kepegawaian\NipCascadeService` sejak MAKE-010 (dipasang
 * di Services.php); stub tetap fail-closed (BelumTersediaException → 501).
 */
final class StubNipCascade implements NipCascadeInterface
{
    public function koreksi(AuthContext $auth, string $nipLama, mixed $nipBaru): array
    {
        throw new BelumTersediaException('koreksi NIP');
    }
}
