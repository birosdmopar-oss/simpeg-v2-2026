<?php

declare(strict_types=1);

namespace App\Libraries\Auth;

/**
 * Pemegang claims JWT untuk request yang sedang berjalan.
 * Diisi oleh JwtAuthFilter, dibaca oleh RoleFilter, Service, dan BaseAuditableModel (nip_actor).
 */
class AuthContext
{
    /**
     * @var array<string, mixed>
     */
    private array $claims = [];

    /**
     * @param array<string, mixed> $claims
     */
    public function setClaims(array $claims): void
    {
        $this->claims = $claims;
    }

    public function clear(): void
    {
        $this->claims = [];
    }

    /**
     * @return array<string, mixed>
     */
    public function claims(): array
    {
        return $this->claims;
    }

    public function isAuthenticated(): bool
    {
        return isset($this->claims['sub']) && $this->claims['sub'] !== '';
    }

    public function nip(): ?string
    {
        $sub = $this->claims['sub'] ?? null;

        return $sub === null ? null : (string) $sub;
    }

    public function role(): ?int
    {
        $role = $this->claims['role'] ?? null;

        return $role === null ? null : (int) $role;
    }

    public function idUnit(): ?string
    {
        $v = $this->claims['id_unit'] ?? null;

        return $v === null ? null : (string) $v;
    }

    public function idSatker(): ?string
    {
        $v = $this->claims['id_satker'] ?? null;

        return $v === null ? null : (string) $v;
    }
}
