<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * 422 — validasi field/bisnis gagal. errors berisi pesan per-field untuk dipetakan ke form (ADR-001).
 */
class ValidationException extends ApiException
{
    /**
     * @param array<string, mixed>|null $errors
     */
    public function __construct(string $message = 'Validasi gagal.', ?array $errors = null)
    {
        parent::__construct($message, 422, $errors);
    }

    public static function forField(string $field, string $message): self
    {
        return new self($message, [$field => [$message]]);
    }
}
