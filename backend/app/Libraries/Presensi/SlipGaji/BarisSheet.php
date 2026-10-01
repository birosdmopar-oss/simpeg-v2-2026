<?php

declare(strict_types=1);

namespace App\Libraries\Presensi\SlipGaji;

/**
 * CR-027 — satu baris data sheet GPP/TK yang sudah diurai {@see BarisImpor::urai()}.
 *
 * `nip`/`periode` bernilai `null` bila selnya tidak valid (alasannya ada di `galat`), sehingga baris itu tidak punya
 * kunci dan tidak dipasangkan dengan sheet lain. `nama` `null` = sheet tidak punya kolom `nama`; `''` = kolomnya ada
 * tetapi selnya kosong. `nominal` = field sheet ini => sen (sel kosong non-wajib dan sel bergalat bernilai 0).
 */
final readonly class BarisSheet
{
    /**
     * @param array<string, int>                         $nominal
     * @param list<array{kode: string, pesan: string}>   $galat
     */
    public function __construct(
        public string $sheet,
        public int $nomorBaris,
        public ?string $nip,
        public ?PeriodeSlip $periode,
        public ?string $nama,
        public array $nominal,
        public array $galat,
    ) {
    }

    /**
     * Kunci NIP + periode ({@see PratinjauImpor::kunci()}), atau `null` bila salah satunya tidak valid.
     */
    public function kunci(): ?string
    {
        if ($this->nip === null || $this->periode === null) {
            return null;
        }

        return PratinjauImpor::kunci($this->nip, $this->periode);
    }

    public function valid(): bool
    {
        return $this->galat === [];
    }
}
