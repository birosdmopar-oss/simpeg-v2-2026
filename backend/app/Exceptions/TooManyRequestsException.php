<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * 429 — rate limit (A-07 forgot_attempts).
 */
class TooManyRequestsException extends ApiException
{
    public function __construct(string $message = 'Terlalu banyak permintaan. Coba lagi nanti.')
    {
        parent::__construct($message, 429);
    }
}
