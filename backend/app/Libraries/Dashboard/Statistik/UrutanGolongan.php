<?php

declare(strict_types=1);

namespace App\Libraries\Dashboard\Statistik;

/**
 * Seri kolom statistik golongan (PRD Fase 7 F7-7) — kalkulasi murni tanpa DB.
 *
 * Kolom PNS = `pangkat.gol_ruang` urut `pangkat.order` ASC [V2] (legacy `sort()` string, `L_chart.php:4233`); prefiks
 * "CPNS " dibuang sehingga CPNS digabung ke gol ruang PNS-nya [K `L_chart.php:4224-4229`]. Kolom PPPK (`gol_pppk`) urut
 * `order` ASC ditempatkan setelah seluruh kolom PNS [K `L_chart.php:4235-4248`].
 */
final class UrutanGolongan
{
    /**
     * @param list<array{id_pangkat: int|string, gol_ruang: string, order: int|string}>  $pangkat baris master pangkat yang
     *                                                                                   muncul di data
     * @param list<array{id_gol_pppk: int|string, gol_pppk: string, order: int|string}> $golPppk baris master gol PPPK yang
     *                                                                                   muncul di data
     *
     * @return array{seri: list<array{key: string, label: string}>, peta: array<string, string>} `peta` memetakan
     *                                                                                             "p<id_pangkat>" /
     *                                                                                             "k<id_gol_pppk>" → key seri
     */
    public static function susun(array $pangkat, array $golPppk): array
    {
        $pns  = [];
        $peta = [];

        foreach ($pangkat as $p) {
            $label = self::tanpaCpns((string) $p['gol_ruang']);
            $key   = 'pns_' . self::slug($label);
            $order = (int) $p['order'];

            if (! isset($pns[$key]) || $order < $pns[$key]['order']) {
                $pns[$key] = ['key' => $key, 'label' => $label, 'order' => $order];
            }

            $peta['p' . $p['id_pangkat']] = $key;
        }

        $pppk = [];

        foreach ($golPppk as $g) {
            $label = trim((string) $g['gol_pppk']);
            $key   = 'pppk_' . self::slug($label);
            $order = (int) $g['order'];

            if (! isset($pppk[$key]) || $order < $pppk[$key]['order']) {
                $pppk[$key] = ['key' => $key, 'label' => $label, 'order' => $order];
            }

            $peta['k' . $g['id_gol_pppk']] = $key;
        }

        $urut = static fn (array $a, array $b): int => [$a['order'], $a['label']] <=> [$b['order'], $b['label']];
        usort($pns, $urut);
        usort($pppk, $urut);

        $seri = array_map(static fn (array $s): array => ['key' => $s['key'], 'label' => $s['label']], [...$pns, ...$pppk]);

        return ['seri' => $seri, 'peta' => $peta];
    }

    /**
     * Gol ruang tanpa prefiks "CPNS " (CPNS digabung ke gol ruang PNS).
     */
    public static function tanpaCpns(string $golRuang): string
    {
        return trim((string) preg_replace('/^\s*CPNS\s+/i', '', $golRuang));
    }

    private static function slug(string $label): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower($label)), '_');
    }
}
