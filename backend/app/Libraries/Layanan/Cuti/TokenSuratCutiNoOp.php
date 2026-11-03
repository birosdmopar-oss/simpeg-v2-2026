<?php

declare(strict_types=1);

namespace App\Libraries\Layanan\Cuti;

use App\Interfaces\Layanan\TokenSuratCutiInterface;

/**
 * Bawaan sementara {@see TokenSuratCutiInterface} sampai tabel `surat_cuti_token` (DBV-030) ada di `main`: tidak ada token
 * aktif, sehingga route publik surat cuti selalu 404 seragam.
 */
final class TokenSuratCutiNoOp implements TokenSuratCutiInterface
{
    public function idCutiAktif(string $tokenMentah): ?int
    {
        return null;
    }
}
