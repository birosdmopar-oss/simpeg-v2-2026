<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Base exception yang dipetakan langsung ke response API (ADR-001 envelope, ADR-002 handler global).
 * Ditangkap oleh ApiController::_remap() dan App\Libraries\ApiExceptionHandler.
 *
 * Kode galat mesin opsional (Fase 5A, aditif): {@see self::denganKode()} → envelope memuat `code` (pola
 * `password_change_required` MAKE-020), mis. `pratinjau_tidak_ditemukan`, `konflik_data`. Tanpa kode, envelope tidak berubah.
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

    private ?string $kode = null;

    /**
     * Pasang kode galat mesin (snake_case) untuk field `code` di envelope; mengembalikan exception yang sama.
     */
    public function denganKode(string $kode): static
    {
        $this->kode = $kode;

        return $this;
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
