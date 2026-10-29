<?php

declare(strict_types=1);

namespace App\Libraries\Impor;

/**
 * Hitung baris per tabel (tanpa data pribadi): sumber, ditulis, dilewati (seed sama), dibuang per kode aturan.
 */
final class LaporanImpor
{
    /**
     * @var array<string, array{tier: int, aturan: string, sumber: int, ditulis: int, dilewati: int, dibuang: array<string, int>, catatan: string}>
     */
    private array $tabel = [];

    public function mulai(int $tier, DefinisiTabel $def, int $sumber, string $catatan = ''): void
    {
        $this->tabel[$def->tabel] = [
            'tier'    => $tier, 'aturan' => $def->sumberAturan, 'sumber' => $sumber, 'ditulis' => 0, 'dilewati' => 0,
            'dibuang' => [], 'catatan' => $catatan,
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

    /**
     * @return array<string, array{tier: int, aturan: string, sumber: int, ditulis: int, dilewati: int, dibuang: array<string, int>, catatan: string}>
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
            $buang = [];

            foreach ($t['dibuang'] as $kode => $n) {
                $buang[] = "{$kode}={$n}";
            }

            $out[] = sprintf(
                'T%d %-26s sumber=%d ditulis=%d dilewati=%d dibuang=%d%s%s [%s]',
                $t['tier'],
                $nama,
                $t['sumber'],
                $t['ditulis'],
                $t['dilewati'],
                array_sum($t['dibuang']),
                $buang === [] ? '' : ' (' . implode(', ', $buang) . ')',
                $t['catatan'] === '' ? '' : ' — ' . $t['catatan'],
                $t['aturan'],
            );
        }

        return $out;
    }
}
