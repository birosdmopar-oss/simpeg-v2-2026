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

    /**
     * Prediksi Tanda Jasa satu pegawai untuk kartu beranda pegawai — port `L_employee::next_tj` (`L_employee.php:2910-3040`) [K],
     * BERBEDA dari {@see self::berikutnya()} (`get_all_tj`):
     * - lama bekerja per **31 Desember tahun berjalan**;
     * - ≥ 30: XXX bila belum punya (selain itu tidak ada); 20–29: XX bila belum punya, selain itu XXX (+30); 10–19: X bila
     *   belum punya, selain itu XX (+20); < 10 (atau TMT CPNS tidak ada — legacy: string kosong < 10): X (+10);
     * - dibulatkan ke 1 Agustus seperti `get_all_tj`; bila tanggal itu sudah lewat dari hari ini → naik satu tingkat dengan
     *   tanggal + 10 tahun (XXX yang lewat → tidak ada);
     * - tanpa TMT CPNS: dasar `strtotime("+N year")` legacy = hari ini (kuirk legacy dipertahankan).
     *
     * @param list<int> $dimiliki
     *
     * @return array{tgl_penghargaan: string, tanda_jasa: string}|null
     */
    public static function prediksiPegawai(?string $tmtCpns, string $hariIni, array $dimiliki): ?array
    {
        $hari  = new DateTimeImmutable($hariIni);
        $cpns  = self::tanggal($tmtCpns);
        $lama  = $cpns === null ? null : (new DateTimeImmutable($hari->format('Y') . '-12-31'))->diff($cpns)->y;
        $punya = static fn (int $id): bool => in_array($id, $dimiliki, true);

        if ($lama !== null && $lama >= 30) {
            if ($punya(self::ID_XXX)) {
                return null;
            }
            [$idx, $tambah] = [3, 30];
        } elseif ($lama !== null && $lama >= 20) {
            [$idx, $tambah] = $punya(self::ID_XX) ? [3, 30] : [2, 20];
        } elseif ($lama !== null && $lama >= 10) {
            [$idx, $tambah] = $punya(self::ID_X) ? [2, 20] : [1, 10];
        } else {
            [$idx, $tambah] = [1, 10];
        }

        $dasar = $cpns ?? $hari;
        $tgl   = $dasar->modify(sprintf('+%d year', $tambah));
        $tahun = (int) $tgl->format('Y') + ((int) $tgl->format('n') <= 8 ? 0 : 1);
        $hasil = sprintf('%04d-08-01', $tahun);

        if ($hasil < $hariIni) {
            if ($idx >= 3) {
                return null;
            }

            $idx++;
            $hasil = sprintf('%04d-08-01', $tahun + 10);
        }

        return ['tgl_penghargaan' => $hasil, 'tanda_jasa' => self::LABEL_INDEKS[$idx]];
    }

    /** @var array<int, string> */
    private const LABEL_INDEKS = [
        1 => 'Satyalancana Karya Satya X Tahun',
        2 => 'Satyalancana Karya Satya XX Tahun',
        3 => 'Satyalancana Karya Satya XXX Tahun',
    ];

    private static function tanggal(?string $ymd): ?DateTimeImmutable
    {
        if ($ymd === null || preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $ymd, $m) !== 1 || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }

        return new DateTimeImmutable(sprintf('%s-%s-%s', $m[1], $m[2], $m[3]));
    }
}
