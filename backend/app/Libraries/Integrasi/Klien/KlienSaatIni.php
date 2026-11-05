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

    private ?string $sidikRahasia = null;

    /**
     * @param string|null $sidikRahasia sidik rahasia klien yang baru saja diverifikasi (hanya jalur Basic; null untuk jalur token)
     */
    public function set(int $idKlien, int $jenis, ?string $sidikRahasia = null): void
    {
        $this->id           = $idKlien;
        $this->jenis        = $jenis;
        $this->sidikRahasia = $sidikRahasia;
    }

    /**
     * Sidik rahasia untuk {@see TokenSimapi::terbitkan()} (menolak handshake bila rahasia dirotasi sesudah verifikasi).
     */
    public function sidikRahasia(): ?string
    {
        return $this->sidikRahasia;
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
