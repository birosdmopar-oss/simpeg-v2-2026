<?php

declare(strict_types=1);

namespace App\Constants;

/**
 * Akun sistem integrasi (DBV-027, PRD Fase 6 §8) — pelaku `updated_by`/`approved_by` penulisan job SIASN/e-Kinerja, padanan
 * akun developer legacy `ID_USR_DEVSIMPEG` (role 1, keputusan Horii 03-11-2026 DBV-Q10). Bukan akun manusia: login selalu
 * ditolak dan Manajemen Akun menyembunyikan serta mengunci akun ini (temuan T3 DBV, defense in depth di samping status '0'
 * tanpa password). Nilai username sama dengan seed migration DBV-027.
 */
final class AkunSistem
{
    public const USERNAME = 'sistem.integrasi';

    /**
     * @param array<string, mixed> $pengguna baris `pengguna`
     */
    public static function adalah(array $pengguna): bool
    {
        return ($pengguna['username'] ?? null) === self::USERNAME;
    }
}
