<?php

declare(strict_types=1);

namespace App\Libraries\Layanan\Studi;

use InvalidArgumentException;

/**
 * MAKE-024 (bagian murni) — aturan/konstanta Tugas Belajar (TB) & Izin Belajar (IB) sebagai value object murni (PRD
 * Fase 4 v1.1 §6.7.2, §6.8.1, Q-23). Sumber nilai runtime = `Config\Layanan::aturanStudi()`; {@see self::bawaan()} = nilai
 * PRD untuk test dan pemanggil tanpa config. Library murni tidak boleh membaca `Config\*`, jadi aturan diterima sebagai
 * parameter (pola `AturanCuti`).
 */
final readonly class AturanStudi
{
    public const JENIS_TB = 'tb';

    public const JENIS_IB = 'ib';

    public const JENIS = [self::JENIS_TB, self::JENIS_IB];

    /** `id_jenjang_pendidikan` harus lebih besar dari nilai ini (jenjang S1 ke atas) [K PRD §6.7.2]. */
    public const JENJANG_MIN_EKSKLUSIF = 3;

    /** Bawaan isian `up` [K PRD §6.7.2]. */
    public const UP_BAWAAN = 'Kepala Biro Sumber Daya Manusia dan Organisasi';

    /** `reason_note` ≤ 255 byte sebagai validasi input baru untuk semua modul [V2 Q-23]. */
    public const MAKS_BYTE_REASON = 255;

    public function __construct(
        public int $jenjangMinEksklusif = self::JENJANG_MIN_EKSKLUSIF,
        public string $upBawaan = self::UP_BAWAAN,
        public int $maksByteReason = self::MAKS_BYTE_REASON,
    ) {
        if ($maksByteReason < 1) {
            throw new InvalidArgumentException("Batas byte alasan harus positif: {$maksByteReason}");
        }
    }

    public static function bawaan(): self
    {
        return new self();
    }

    /**
     * Nama layanan untuk teks pesan: "tugas belajar" / "izin belajar". Jenis tidak dikenal = kesalahan program.
     */
    public static function namaLayanan(string $jenis): string
    {
        return match ($jenis) {
            self::JENIS_TB => 'tugas belajar',
            self::JENIS_IB => 'izin belajar',
            default        => throw new InvalidArgumentException("Jenis layanan studi tidak dikenal: {$jenis}"),
        };
    }
}
