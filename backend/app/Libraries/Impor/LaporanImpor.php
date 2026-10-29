<?php

declare(strict_types=1);

namespace App\Libraries\Impor;

/**
 * Hitung baris per tabel (tanpa data pribadi): sumber, ditulis, dilewati (seed sama), dibuang per kode aturan, dan
 * koreksi nilai per kode aturan (baris tetap ditulis, mis. rujukan yatim → NULL).
 */
final class LaporanImpor
{
    /**
     * @var array<string, array{tier: int, aturan: string, sumber: int, ditulis: int, dilewati: int, dibuang: array<string, int>, koreksi: array<string, int>, catatan: string}>
     */
    private array $tabel = [];

    public function mulai(int $tier, DefinisiTabel $def, int $sumber, string $catatan = ''): void
    {
        $this->tabel[$def->tabel] = [
            'tier'    => $tier, 'aturan' => $def->sumberAturan, 'sumber' => $sumber, 'ditulis' => 0, 'dilewati' => 0,
            'dibuang' => [], 'koreksi' => [], 'catatan' => $catatan,
        ];
    }

    public function ditulis(string $tabel, int $n): void
    {
        $this->tabel[$tabel]['ditulis'] += $n;
    }

    public function dilewati(string $tabel): void
    {
        $this->tabel[$tabel]['dilewati']++;
    }

    public function dibuang(string $tabel, string $kode): void
    {
        $this->tabel[$tabel]['dibuang'][$kode] = ($this->tabel[$tabel]['dibuang'][$kode] ?? 0) + 1;
    }

    public function koreksi(string $tabel, string $kode): void
    {
        $this->tabel[$tabel]['koreksi'][$kode] = ($this->tabel[$tabel]['koreksi'][$kode] ?? 0) + 1;
    }

    /**
     * @return array<string, array{tier: int, aturan: string, sumber: int, ditulis: int, dilewati: int, dibuang: array<string, int>, koreksi: array<string, int>, catatan: string}>
     */
    public function semua(): array
    {
        return $this->tabel;
    }

    /**
     * Baris teks per tabel untuk CLI / berkas log.
     *
     * @return list<string>
     */
    public function baris(): array
    {
        $out = [];

        foreach ($this->tabel as $nama => $t) {
            $out[] = sprintf(
                'T%d %-26s sumber=%d ditulis=%d dilewati=%d dibuang=%d%s%s%s [%s]',
                $t['tier'],
                $nama,
                $t['sumber'],
                $t['ditulis'],
                $t['dilewati'],
                array_sum($t['dibuang']),
                self::rinci($t['dibuang']),
                $t['koreksi'] === [] ? '' : ' koreksi' . self::rinci($t['koreksi']),
                $t['catatan'] === '' ? '' : ' — ' . $t['catatan'],
                $t['aturan'],
            );
        }

        return $out;
    }

    /**
     * @param array<string, int> $hitung
     */
    private static function rinci(array $hitung): string
    {
        $teks = [];

        foreach ($hitung as $kode => $n) {
            $teks[] = "{$kode}={$n}";
        }

        return $teks === [] ? '' : ' (' . implode(', ', $teks) . ')';
    }
}
