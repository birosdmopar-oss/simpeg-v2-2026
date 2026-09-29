<?php

declare(strict_types=1);

namespace App\Libraries\Auth;

/**
 * Pemegang claims JWT untuk request yang sedang berjalan.
 * Diisi oleh JwtAuthFilter, dibaca oleh RoleFilter, Service, dan BaseAuditableModel (pelaku audit).
 *
 * Identitas akun = `id_pengguna` (claim `sub`, DBV-010/CR-013); NIP hanya atribut akun pegawai (claim `nip`, NULL untuk
 * akun role 1/3/4/5/8 tanpa NIP).
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
        return $this->idPengguna() !== null;
    }

    /**
     * id_pengguna akun yang login (claim `sub`); null bila tidak ada sesi atau `sub` bukan id yang valid.
     */
    public function idPengguna(): ?int
    {
        return JwtService::subjectToId($this->claims['sub'] ?? null);
    }

    /**
     * NIP akun yang login (claim `nip`); null untuk akun tanpa NIP.
     */
    public function nip(): ?string
    {
        $nip = $this->claims['nip'] ?? null;

        return $nip === null || $nip === '' ? null : (string) $nip;
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
