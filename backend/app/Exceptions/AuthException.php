<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Kegagalan autentikasi token. Pesan sengaja generik (tidak membocorkan detail) — Tech Spec Bagian 9.
 */
class AuthException extends RuntimeException
{
    public const REASON_MISSING   = 'missing';
    public const REASON_INVALID   = 'invalid';
    public const REASON_EXPIRED   = 'expired';
    public const REASON_REVOKED   = 'revoked';
    public const REASON_REUSED    = 'reused';
    public const REASON_NOT_FOUND = 'not_found';

    private string $reason;

    public function __construct(string $reason, string $message = 'Unauthorized')
    {
        parent::__construct($message, 401);
        $this->reason = $reason;
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public static function missingToken(): self
    {
        return new self(self::REASON_MISSING, 'Token tidak ditemukan.');
    }

    public static function invalidToken(): self
    {
        return new self(self::REASON_INVALID, 'Token tidak valid.');
    }

    public static function expiredToken(): self
    {
        return new self(self::REASON_EXPIRED, 'Token sudah kedaluwarsa.');
    }

    public static function revokedToken(): self
    {
        return new self(self::REASON_REVOKED, 'Token sudah dicabut.');
    }

    public static function reusedToken(): self
    {
        return new self(self::REASON_REUSED, 'Refresh token sudah pernah dipakai.');
    }

    public static function unknownToken(): self
    {
        return new self(self::REASON_NOT_FOUND, 'Refresh token tidak dikenal.');
    }
}
