<?php

declare(strict_types=1);

namespace App\Libraries\Layanan\Cuti;

/**
 * MAKE-022 — snapshot saldo pada baris `riwayat_cuti` yang dihapus (`sisa_cuti`, `sisa_cuti_n1`, `sisa_cuti_n2`),
 * dipakai {@see KuotaCuti::kembalikan()} untuk menentukan batas pengembalian per kolom (PRD Fase 4 v1.3 §6.3.5, Q-05).
 * Data impor 2026 punya saldo di atas batas D8 (mis. N-1 > 6); refund tidak boleh menghanguskan hari yang sudah ada
 * di snapshot, jadi batas kolom = `max(batas D8, snapshot kolom itu)`. Kolom NULL (data lama tanpa snapshot) → batas D8.
 * Murni; angka D8 selalu dari {@see AturanCuti}.
 */
final readonly class SnapshotSaldoCuti
{
    public function __construct(
        public ?int $n = null,
        public ?int $n1 = null,
        public ?int $n2 = null,
    ) {
    }

    /**
     * Dari baris `riwayat_cuti` (kolom NULL tetap NULL; string MySQLi dikonversi ke int).
     *
     * @param array<string, mixed> $baris
     */
    public static function dariBaris(array $baris): self
    {
        return new self(
            self::angka($baris['sisa_cuti'] ?? null),
            self::angka($baris['sisa_cuti_n1'] ?? null),
            self::angka($baris['sisa_cuti_n2'] ?? null),
        );
    }

    /**
     * Batas pengembalian per kolom: `max(batas D8, snapshot)`; snapshot NULL/lebih kecil → batas D8.
     *
     * @return array{n: int, n1: int, n2: int}
     */
    public function batas(AturanCuti $aturan): array
    {
        return [
            'n'  => max($aturan->kuotaN, $this->n ?? 0),
            'n1' => max($aturan->maksN1, $this->n1 ?? 0),
            'n2' => max($aturan->maksN2, $this->n2 ?? 0),
        ];
    }

    private static function angka(mixed $nilai): ?int
    {
        return $nilai === null || $nilai === '' ? null : (int) $nilai;
    }
}
