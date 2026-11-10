<?php

declare(strict_types=1);

namespace App\Libraries\Dashboard\Beranda;

use DateTimeImmutable;

/**
 * Proyeksi Satyalancana Karya Satya berikutnya — port murni `L_employee::get_all_tj` / `get_tj_satker` / `get_tj_unit`
 * (`libraries/hr/L_employee.php:3177-3619`, logika sama, hanya populasi berbeda) untuk kartu Tanda Jasa beranda (F-13).
 *
 * Aturan [K]:
 * - `lama_bekerja` = selisih tahun penuh antara `{tahunAcuan}-08-01` dan TMT CPNS (`DateTime::diff`, nilai mutlak);
 *   tanpa TMT CPNS → tidak ada proyeksi.
 * - ≥ 30 tahun dan belum punya XXX (id 26) → XXX, tanggal = CPNS + 30 tahun; 20–29 tanpa XX (27) → XX, +20; 10–19 tanpa X
 *   (28) → X, +10. Sudah punya tingkat itu → tidak ada proyeksi (tingkat lain tidak dicoba).
 * - Tanggal penghargaan dibulatkan ke 1 Agustus: bulan ≤ 8 → `{tahun}-08-01`, selain itu `{tahun+1}-08-01`.
 *   `+N year` mengikuti `strtotime` legacy (29 Februari meluap ke 1 Maret).
 * Murni: tanpa DB, tanpa jam.
 */
final class ProyeksiTandaJasa
{
    public const ID_X = 28;

    public const ID_XX = 27;

    public const ID_XXX = 26;

    /** @var list<array{min: int, maks: int|null, tahun: int, id: int, label: string}> */
    private const TINGKAT = [
        ['min' => 30, 'maks' => null, 'tahun' => 30, 'id' => self::ID_XXX, 'label' => 'Satyalancana Karya Satya XXX Tahun'],
        ['min' => 20, 'maks' => 30, 'tahun' => 20, 'id' => self::ID_XX, 'label' => 'Satyalancana Karya Satya XX Tahun'],
        ['min' => 10, 'maks' => 20, 'tahun' => 10, 'id' => self::ID_X, 'label' => 'Satyalancana Karya Satya X Tahun'],
    ];

    private function __construct()
    {
    }

    /**
     * @param list<int> $dimiliki id tanda jasa 26/27/28 yang sudah dimiliki (riwayat status 1)
     *
     * @return array{tgl_penghargaan: string, tanda_jasa: string}|null
     */
    public static function berikutnya(?string $tmtCpns, int $tahunAcuan, array $dimiliki): ?array
    {
        $cpns = self::tanggal($tmtCpns);

        if ($cpns === null) {
            return null;
        }

        $lama = (new DateTimeImmutable(sprintf('%04d-08-01', $tahunAcuan)))->diff($cpns)->y;

        foreach (self::TINGKAT as $t) {
            if ($lama < $t['min'] || ($t['maks'] !== null && $lama >= $t['maks'])) {
                continue;
            }

            if (in_array($t['id'], $dimiliki, true)) {
                return null;
            }

            $tgl   = $cpns->modify(sprintf('+%d year', $t['tahun']));
            $tahun = (int) $tgl->format('Y') + ((int) $tgl->format('n') <= 8 ? 0 : 1);

            return ['tgl_penghargaan' => sprintf('%04d-08-01', $tahun), 'tanda_jasa' => $t['label']];
        }

        return null;
    }

    private static function tanggal(?string $ymd): ?DateTimeImmutable
    {
        if ($ymd === null || preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $ymd, $m) !== 1 || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }

        return new DateTimeImmutable(sprintf('%s-%s-%s', $m[1], $m[2], $m[3]));
    }
}
