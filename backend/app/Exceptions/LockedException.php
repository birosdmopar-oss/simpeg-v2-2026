<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * 423 — akun terkunci sementara (A-04 lockout).
 */
class LockedException extends ApiException
{
    public function __construct(
        string $message = 'Akun terkunci sementara karena terlalu banyak percobaan login gagal.',
        private int $retryAfterSeconds = 0,
    ) {
        parent::__construct($message, 423);
    }

    public function getRetryAfterSeconds(): int
    {
        return $this->retryAfterSeconds;
    }
}
