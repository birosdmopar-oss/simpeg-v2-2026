<?php

declare(strict_types=1);

namespace App\Libraries\Kepegawaian\Stub;

use App\Exceptions\BelumTersediaException;
use App\Interfaces\Kepegawaian\StrukturServiceInterface;

/**
 * Stub S0-A (MAKE-002) sampai implementasi nyata WS-2 (MAKE-012). Interface belum punya method; method yang ditambahkan
 * pemiliknya pada stub ini wajib fail-closed (lempar BelumTersediaException → 501).
 */
final class StubStrukturService implements StrukturServiceInterface
{
}
