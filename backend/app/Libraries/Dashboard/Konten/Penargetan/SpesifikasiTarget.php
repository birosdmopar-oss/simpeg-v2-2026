<?php

declare(strict_types=1);

namespace App\Libraries\Dashboard\Konten\Penargetan;

use InvalidArgumentException;

/**
 * Spesifikasi penargetan popup/banner/form digital (PRD Fase 7 v2.0 §5.3, Q7-15; legacy `L_announcement.php:447-620`).
 * Nilai murni (tanpa DB):
 * - `unitSatker`: token `"0"` (seluruh kementerian), `"<id_unit>"` (angka), atau `"sat_<id_satker>"`;
 * - `pegawai`: NIP yang ditambahkan eksplisit;
 * - `kecuali`: NIP yang dikecualikan (menang atas dua daftar di atas).
 * Daftar dinormalkan: string, dipangkas, tanpa duplikat, urutan pertama dipertahankan. Format token dan NIP salah →
 * {@see InvalidArgumentException} (controller memetakan ke 422).
 */
final class SpesifikasiTarget
{
    public const SEMUA_KEMENTERIAN = '0';

    private const POLA_UNIT = '/^(0|[1-9][0-9]{0,9}|sat_[1-9][0-9]{0,9})$/';

    private const POLA_NIP = '/^[0-9A-Za-z.\-]{1,30}$/';

    /**
     * @param list<string> $unitSatker
     * @param list<string> $pegawai
     * @param list<string> $kecuali
     */
    private function __construct(
        public readonly array $unitSatker,
        public readonly array $pegawai,
        public readonly array $kecuali,
    ) {
    }

    /**
     * @param array<string, mixed> $data kunci `unit_satker`, `pegawai`, `kecuali` (semua opsional)
     */
    public static function dariArray(array $data): self
    {
        $unit = self::normalisasi($data['unit_satker'] ?? [], 'unit_satker', self::POLA_UNIT);

        return new self(
            $unit,
            self::normalisasi($data['pegawai'] ?? [], 'pegawai', self::POLA_NIP),
            self::normalisasi($data['kecuali'] ?? [], 'kecuali', self::POLA_NIP),
        );
    }

    /**
     * Dari kolom DB `target_uns` / `target_peg` / `excl_peg` (JSON array atau NULL).
     */
    public static function dariKolom(?string $targetUns, ?string $targetPeg, ?string $exclPeg): self
    {
        return self::dariArray([
            'unit_satker' => self::jsonList($targetUns),
            'pegawai'     => self::jsonList($targetPeg),
            'kecuali'     => self::jsonList($exclPeg),
        ]);
    }

    /**
     * @return array{unit_satker: list<string>, pegawai: list<string>, kecuali: list<string>}
     */
    public function keArray(): array
    {
        return ['unit_satker' => $this->unitSatker, 'pegawai' => $this->pegawai, 'kecuali' => $this->kecuali];
    }

    /**
     * Nilai kolom DB: `[target_uns, target_peg, excl_peg]` sebagai JSON array, atau NULL bila daftar kosong.
     *
     * @return array{target_uns: ?string, target_peg: ?string, excl_peg: ?string}
     */
    public function keKolom(): array
    {
        $json = static fn (array $l): ?string => $l === [] ? null : json_encode($l, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return ['target_uns' => $json($this->unitSatker), 'target_peg' => $json($this->pegawai), 'excl_peg' => $json($this->kecuali)];
    }

    public function kosong(): bool
    {
        return $this->unitSatker === [] && $this->pegawai === [];
    }

    public function seluruhKementerian(): bool
    {
        return in_array(self::SEMUA_KEMENTERIAN, $this->unitSatker, true);
    }

    /**
     * @return list<int> id_unit dari token angka (bukan "0")
     */
    public function idUnit(): array
    {
        $id = [];

        foreach ($this->unitSatker as $t) {
            if ($t !== self::SEMUA_KEMENTERIAN && ! str_starts_with($t, 'sat_')) {
                $id[] = (int) $t;
            }
        }

        return $id;
    }

    /**
     * @return list<int> id_satker dari token "sat_<id>"
     */
    public function idSatker(): array
    {
        $id = [];

        foreach ($this->unitSatker as $t) {
            if (str_starts_with($t, 'sat_')) {
                $id[] = (int) substr($t, 4);
            }
        }

        return $id;
    }

    /**
     * @return list<string>
     */
    private static function jsonList(?string $json): array
    {
        if ($json === null || trim($json) === '') {
            return [];
        }

        $data = json_decode($json, true);

        return is_array($data) ? array_values(array_map('strval', array_filter($data, 'is_scalar'))) : [];
    }

    /**
     * @return list<string>
     */
    private static function normalisasi(mixed $nilai, string $kolom, string $pola): array
    {
        if (! is_array($nilai)) {
            throw new InvalidArgumentException("Target {$kolom} harus berupa daftar.");
        }

        $hasil = [];

        foreach ($nilai as $item) {
            if (! is_string($item) && ! is_int($item)) {
                throw new InvalidArgumentException("Target {$kolom} berisi nilai tidak valid.");
            }

            $s = trim((string) $item);

            if ($s === '') {
                continue;
            }

            if (preg_match($pola, $s) !== 1) {
                throw new InvalidArgumentException("Target {$kolom} berisi format tidak valid: {$s}.");
            }

            $hasil[$s] = $s;
        }

        return array_values($hasil);
    }
}
