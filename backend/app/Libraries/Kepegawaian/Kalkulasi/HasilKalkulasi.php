<?php

declare(strict_types=1);

namespace App\Libraries\Kepegawaian\Kalkulasi;

use App\Exceptions\ValidationException;

/**
 * CR-025 (K-CR025-4) — hasil kalkulasi/validasi atas **input pengguna**. Gagal membawa `kode` stabil (untuk test dan FE)
 * dan `pesan` Bahasa Indonesia; service ber-DB (B-08/B-09/B-14) memetakannya ke 422 lewat {@see self::lemparBilaGagal()}.
 * Kesalahan data sistem (riwayat DB, parameter program) tidak memakai kelas ini, melainkan `InvalidArgumentException`.
 *
 * `tanggal` = tanggal hasil (`YYYY-MM-DD`) bila ada; `detail` = nilai pendukung (mis. periode terdekat, batas minimal).
 */
final readonly class HasilKalkulasi
{
    /**
     * @param array<string, mixed> $detail
     */
    private function __construct(
        public bool $valid,
        public ?string $tanggal,
        public ?string $kode,
        public ?string $pesan,
        public array $detail,
    ) {
    }

    /**
     * Hasil valid. `kode`/`pesan` opsional untuk hasil valid yang tetap perlu info ke pengguna (mis. `tanpa_masa`).
     *
     * @param array<string, mixed> $detail
     */
    public static function berhasil(?string $tanggal, array $detail = [], ?string $kode = null, ?string $pesan = null): self
    {
        return new self(true, $tanggal, $kode, $pesan, $detail);
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
