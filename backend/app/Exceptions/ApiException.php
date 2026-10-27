<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Base exception yang dipetakan langsung ke response API (ADR-001 envelope, ADR-002 handler global).
 * Ditangkap oleh ApiController::_remap() dan App\Libraries\ApiExceptionHandler.
 *
 * Kode galat mesin opsional (Fase 5A, aditif): parameter `$kode` (snake_case) → envelope memuat `code` di level atas
 * (bentuk `JwtAuthFilter` `password_change_required`, MAKE-020). Tanpa kode, envelope tidak berubah.
 */
class ApiException extends RuntimeException
{
    /**
     * @param array<string, mixed>|null $errors error per-field (422)
     * @param string|null               $kode   kode galat mesin opsional untuk field `code` envelope
     */
    public function __construct(
        string $message,
        private int $statusCode = 400,
        private ?array $errors = null,
        private ?string $kode = null,
    ) {
        parent::__construct($message, $statusCode);
    }

    public function getKode(): ?string
    {
        return $this->kode;
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
