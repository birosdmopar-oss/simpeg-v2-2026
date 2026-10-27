<?php

declare(strict_types=1);

namespace App\Libraries\Dashboard\Statistik;

/**
 * Bentuk respons statistik F-03 (PRD Fase 7 F7-13, kompatibel `features/laporan/types.ts` mockup): kategori (sumbu X),
 * seri, baris per kategori dengan nilai per seri + total, subtotal per seri, total. Kategori tanpa data tetap tampil 0.
 */
final class BentukLaporan
{
    /**
     * @param list<array{id: string, label: string}>    $kategori
     * @param list<array{key: string, label: string}>   $seri
     * @param array<string, array<string, int>>         $matriks [kategori_id][seri_key] => jumlah
     *
     * @return array<string, mixed>
     */
    public static function susun(string $jenis, string $judul, LingkupStatistik $lingkup, array $kategori, array $seri, array $matriks, string $periode): array
    {
        $baris    = [];
        $subtotal = array_fill(0, count($seri), 0);

        foreach ($kategori as $k) {
            $nilai = [];

            foreach ($seri as $i => $s) {
                $n       = (int) ($matriks[$k['id']][$s['key']] ?? 0);
                $nilai[] = $n;
                $subtotal[$i] += $n;
            }

            $baris[] = ['kategori_id' => $k['id'], 'label' => $k['label'], 'nilai' => $nilai, 'total' => array_sum($nilai)];
        }

        return [
            'jenis'          => $jenis,
            'judul'          => $judul,
            'lingkup'        => $lingkup->toArray(),
            'label_kategori' => $lingkup->labelKategori(),
            'kategori'       => $kategori,
            'seri'           => $seri,
            'baris'          => $baris,
            'subtotal'       => $subtotal,
            'total'          => array_sum($subtotal),
            'periode'        => $periode,
        ];
    }
}
