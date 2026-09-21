<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * 403 — role tidak berhak, atau data di luar scope satker (ADR-005: scoping di Service).
 */
class ForbiddenException extends ApiException
{
    public function __construct(string $message = 'Forbidden')
    {
        parent::__construct($message, 403);
    }
}
