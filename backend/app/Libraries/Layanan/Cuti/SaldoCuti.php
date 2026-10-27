<?php

declare(strict_types=1);

namespace App\Libraries\Layanan\Cuti;

/**
 * MAKE-022 Tahap 2b — value object saldo cuti tahunan satu pegawai satu tahun (`pegawai_sisa_cuti`, D8):
 * `n` = `sisa_cuti`, `n1` = `sisa_cuti_n1`, `n2` = `sisa_cuti_n2`, `utang` = `utang_cuti`,
 * `ditangguhkan` = `sisa_cuti_ditangguhkan`. Tidak membaca/menulis DB; nilai negatif dari data impor tetap bisa
 * direpresentasikan (dilaporkan {@see self::periksaBatas()}), tetapi tidak pernah dihasilkan `KuotaCuti`.
 */
final readonly class SaldoCuti
{
    public const KOLOM = [
        'n'            => 'sisa_cuti',
        'n1'           => 'sisa_cuti_n1',
        'n2'           => 'sisa_cuti_n2',
        'utang'        => 'utang_cuti',
        'ditangguhkan' => 'sisa_cuti_ditangguhkan',
    ];

    public function __construct(
        public int $n,
        public int $n1 = 0,
        public int $n2 = 0,
        public int $utang = 0,
        public int $ditangguhkan = 0,
    ) {
    }

    /**
     * Dari baris `pegawai_sisa_cuti` (kolom NULL dianggap 0 — DDL DEFAULT 0, NULL-able).
     *
     * @param array<string, mixed> $baris
     */
    public static function dariBaris(array $baris): self
    {
        return new self(
            (int) ($baris['sisa_cuti'] ?? 0),
            (int) ($baris['sisa_cuti_n1'] ?? 0),
            (int) ($baris['sisa_cuti_n2'] ?? 0),
            (int) ($baris['utang_cuti'] ?? 0),
            (int) ($baris['sisa_cuti_ditangguhkan'] ?? 0),
        );
    }

    /**
     * @return array{sisa_cuti: int, sisa_cuti_n1: int, sisa_cuti_n2: int, utang_cuti: int, sisa_cuti_ditangguhkan: int}
     */
    public function keBaris(): array
    {
        return [
            'sisa_cuti'              => $this->n,
            'sisa_cuti_n1'           => $this->n1,
            'sisa_cuti_n2'           => $this->n2,
            'utang_cuti'             => $this->utang,
            'sisa_cuti_ditangguhkan' => $this->ditangguhkan,
        ];
    }

    /** `saldoTahunIni` = N + N-1 + N-2 (PRD §6.3.3). */
    public function total(): int
    {
        return $this->n + $this->n1 + $this->n2;
    }

    /** `saldoTahunDepan` = MAKS_UTANG − utang (PRD §6.3.3); tidak pernah negatif. */
    public function saldoTahunDepan(AturanCuti $aturan): int
    {
        return max(0, $aturan->maksUtang - $this->utang);
    }

    public function dengan(?int $n = null, ?int $n1 = null, ?int $n2 = null, ?int $utang = null, ?int $ditangguhkan = null): self
    {
        return new self(
            $n ?? $this->n,
            $n1 ?? $this->n1,
            $n2 ?? $this->n2,
            $utang ?? $this->utang,
            $ditangguhkan ?? $this->ditangguhkan,
        );
    }

    public function sama(self $lain): bool
    {
        return $this->keBaris() === $lain->keBaris();
    }

    /**
     * Batas D8 untuk input admin kelola saldo (PRD §6.3.6): `sisa_cuti ≤ 12`, `n1 ≤ 6`, `n2 ≤ 6`, `utang ≤ 12`, semua ≥ 0.
     * Mengembalikan pesan per kolom (kosong = sah) untuk dipetakan ke 422 per kolom oleh service.
     *
     * @return array<string, string>
     */
    public function periksaBatas(AturanCuti $aturan): array
    {
        $batas = [
            'sisa_cuti'              => $aturan->kuotaN,
            'sisa_cuti_n1'           => $aturan->maksN1,
            'sisa_cuti_n2'           => $aturan->maksN2,
            'utang_cuti'             => $aturan->maksUtang,
            'sisa_cuti_ditangguhkan' => null,
        ];
        $errors = [];

        foreach ($this->keBaris() as $kolom => $nilai) {
            if ($nilai < 0) {
                $errors[$kolom] = 'Nilai tidak boleh negatif.';
            } elseif ($batas[$kolom] !== null && $nilai > $batas[$kolom]) {
                $errors[$kolom] = "Nilai maksimal {$batas[$kolom]} hari.";
            }
        }

        return $errors;
    }
}
