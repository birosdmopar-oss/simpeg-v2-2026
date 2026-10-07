<?php

declare(strict_types=1);

namespace App\Libraries\Presensi\SlipGaji;

use App\Exceptions\ValidationException;

/**
 * CR-027 (K-CR027-5) — hasil validasi atas **input pengguna** di logika Slip Gaji. Pola sama dengan `HasilKalkulasi`
 * CR-025, tetapi membawa `nilai` bebas (periode, nominal sen, hasil pratinjau) alih-alih `tanggal`, supaya Presensi
 * tidak bergantung pada namespace Kalkulasi Kepegawaian dan semantik tanggalnya.
 *
 * Gagal membawa `kode` stabil (untuk test dan FE) dan `pesan` Bahasa Indonesia; service Fase 5 memetakannya ke 422
 * lewat {@see self::lemparBilaGagal()}. Kesalahan data sistem (nilai DB, parameter program) memakai
 * `InvalidArgumentException`, bukan kelas ini.
 */
final readonly class HasilSlip
{
    /**
     * @param array<string, mixed> $detail
     */
    private function __construct(
        public bool $valid,
        public mixed $nilai,
        public ?string $kode,
        public ?string $pesan,
        public array $detail,
    ) {
    }

    /**
     * Hasil valid. `kode`/`pesan` opsional untuk hasil valid yang tetap membawa info ke pengguna.
     *
     * @param array<string, mixed> $detail
     */
    public static function berhasil(mixed $nilai = null, array $detail = [], ?string $kode = null, ?string $pesan = null): self
    {
        return new self(true, $nilai, $kode, $pesan, $detail);
    }

    /**
     * @param array<string, mixed> $detail
     */
    public static function gagal(string $kode, string $pesan, array $detail = []): self
    {
        return new self(false, null, $kode, $pesan, $detail);
    }

    /**
     * Hasil gagal → `ValidationException` 422 dengan `errors[$field] = [pesan]` (ADR-001); hasil valid → dirinya sendiri.
     */
    public function lemparBilaGagal(string $field): self
    {
        if (! $this->valid) {
            throw ValidationException::forField($field, (string) $this->pesan);
        }

        return $this;
    }
}
