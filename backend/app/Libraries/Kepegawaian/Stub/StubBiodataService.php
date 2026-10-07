<?php

declare(strict_types=1);

namespace App\Libraries\Kepegawaian\Stub;

use App\Interfaces\Kepegawaian\BiodataServiceInterface;

/**
 * Stub S0-A (MAKE-002) sampai implementasi nyata WS-2 (MAKE-010). Interface belum punya method; method yang ditambahkan
 * pemiliknya pada stub ini wajib fail-closed (lempar BelumTersediaException → 501).
 */
final class StubBiodataService implements BiodataServiceInterface
{
}
