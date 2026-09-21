<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Base exception yang dipetakan langsung ke response API (ADR-001 envelope, ADR-002 handler global).
 * Ditangkap oleh ApiController::_remap() dan App\Libraries\ApiExceptionHandler.
 */
class ApiException extends RuntimeException
{
    /**
     * @param array<string, mixed>|null $errors error per-field (422)
     */
    public function __construct(
        string $message,
        private int $statusCode = 400,
        private ?array $errors = null,
    ) {
        parent::__construct($message, $statusCode);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getErrors(): ?array
    {
        return $this->errors;
    }
}
