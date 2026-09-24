<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * 400 — request tidak dapat diproses (mis. body JSON rusak).
 */
class BadRequestException extends ApiException
{
    public function __construct(string $message = 'Permintaan tidak valid.')
    {
        parent::__construct($message, 400);
    }
}
