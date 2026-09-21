<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * 404 — resource tidak ditemukan.
 */
class NotFoundException extends ApiException
{
    public function __construct(string $message = 'Data tidak ditemukan.')
    {
        parent::__construct($message, 404);
    }
}
