<?php

declare(strict_types=1);

namespace App\Libraries\Dashboard\Statistik;

/**
 * Kelompok usia statistik F-03 (PRD Fase 7 F7-6) — kalkulasi murni tanpa DB.
 *
 * Bucket mengikuti versi legacy 2025-08 (`libraries/hr/L_chart_20250813.php:2523`) dengan dua perbaikan [V2]: umur di
 * bawah 18 masuk kelompok terbawah (legacy menjatuhkannya ke "> 60") dan kelompok terbawah berlabel "≤ 30". Versi
 * legacy aktif tidak mem-bucket (satu kolom per tahun usia) dan tidak ditiru.
 */
final class BucketUsia
{
    /**
     * Seri usia, urut naik. Batas atas inklusif; null = tanpa batas.
     *
     * @var list<array{key: string, label: string, sampai: int|null}>
     */
    public const SERI = [
        ['key' => 'le30', 'label' => '≤ 30', 'sampai' => 30],
        ['key' => '31_40', 'label' => '31–40', 'sampai' => 40],
        ['key' => '41_45', 'label' => '41–45', 'sampai' => 45],
        ['key' => '46_50', 'label' => '46–50', 'sampai' => 50],
        ['key' => '51_56', 'label' => '51–56', 'sampai' => 56],
        ['key' => '57_60', 'label' => '57–60', 'sampai' => 60],
        ['key' => 'gt60', 'label' => '> 60', 'sampai' => null],
    ];

    /**
     * Key bucket untuk umur (tahun penuh). Umur negatif/0 (tgl_lahir rusak) ikut kelompok terbawah.
     */
    public static function key(int $umur): string
    {
        foreach (self::SERI as $s) {
            if ($s['sampai'] === null || $umur <= $s['sampai']) {
                return $s['key'];
            }
        }

        return self::SERI[array_key_last(self::SERI)]['key'];
    }

    /**
     * @return list<array{key: string, label: string}>
     */
    public static function seri(): array
    {
        return array_map(static fn (array $s): array => ['key' => $s['key'], 'label' => $s['label']], self::SERI);
    }
}
