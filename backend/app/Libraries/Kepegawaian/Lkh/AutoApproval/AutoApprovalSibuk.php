<?php

declare(strict_types=1);

namespace App\Libraries\Kepegawaian\Lkh\AutoApproval;

use RuntimeException;

/**
 * Run nyata auto-approval LKH lain sedang memegang kunci (`GET_LOCK`, tunggu 0). Pesan tanpa data pribadi.
 */
final class AutoApprovalSibuk extends RuntimeException
{
}
