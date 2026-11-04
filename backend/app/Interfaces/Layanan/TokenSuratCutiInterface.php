<?php

declare(strict_types=1);

namespace App\Interfaces\Layanan;

/**
 * Pencarian token publik surat cuti (PRD Fase 6 v1.0f Q6-22/Q6-23, tabel `surat_cuti_token` DBV-030): token mentah →
 * `id_riwayat_cuti` bila token AKTIF (status 1), selain itu null. Implementasi mencocokkan `hash('sha256', token)`;
 * token mentah tidak pernah disimpan atau dicatat. Implementasi: {@see \App\Libraries\Layanan\Cuti\TokenSuratCutiService}.
 */
interface TokenSuratCutiInterface
{
    public function idCutiAktif(string $tokenMentah): ?int;
}
