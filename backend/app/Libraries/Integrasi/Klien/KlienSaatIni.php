<?php

declare(strict_types=1);

namespace App\Libraries\Integrasi\Klien;

/**
 * MAKE-058 — identitas klien layanan yang sudah lolos filter `klienLayanan`/`simapiToken` pada request berjalan. Dipakai
 * controller SIMAPI/`services/*` (A4+) dan penulisan log akses masuk (`integrasi_log.id_klien`). Didaftarkan sebagai service
 * bersama `klienSaatIni` (satu instance per request).
 */
final class KlienSaatIni
{
    private ?int $id = null;

    private ?int $jenis = null;

    public function set(int $idKlien, int $jenis): void
    {
        $this->id    = $idKlien;
        $this->jenis = $jenis;
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function jenis(): ?int
    {
        return $this->jenis;
    }

    public function ada(): bool
    {
        return $this->id !== null;
    }
}
