<?php

declare(strict_types=1);

namespace App\Libraries\Dashboard\Interaksi\Survei;

use App\Exceptions\ValidationException;
use CodeIgniter\Database\BaseConnection;

/**
 * Kode jenis layanan SKL (`skl.jenis_rwy`, Q7-22) [K `libraries/hr/L_survey.php:15-21`]: angka legacy 1–5 dipetakan ke tabel
 * riwayat. Label dibaca dari master `jenis_layanan` berdasarkan kolom `table` (BUKAN id: id master v2 berbeda, 1 = KARPEG);
 * bila baris master tidak ada, label bawaan di bawah dipakai.
 */
final class JenisLayananSkl
{
    /** kode => [tabel riwayat, PK, label bawaan]. */
    public const PETA = [
        1 => ['riwayat_cuti', 'id_riwayat_cuti', 'Cuti'],
        2 => ['riwayat_kariskarsu', 'id_riwayat_kariskarsu', 'KARIS/KARSU'],
        3 => ['riwayat_karpeg', 'id_riwayat_karpeg', 'KARPEG'],
        4 => ['riwayat_tb', 'id_riwayat_tb', 'Tugas Belajar'],
        5 => ['riwayat_ib', 'id_riwayat_ib', 'Izin Belajar'],
    ];

    private function __construct()
    {
    }

    /**
     * @return array{0: string, 1: string} [tabel, PK]
     *
     * @throws ValidationException kode di luar 1–5 (legacy: SQL error `id_` — `Announcement.php:710`)
     */
    public static function tabel(mixed $kode, string $field = 'jenis_rwy'): array
    {
        $k = self::kode($kode, $field);

        return [self::PETA[$k][0], self::PETA[$k][1]];
    }

    public static function kode(mixed $kode, string $field = 'jenis_rwy'): int
    {
        if (is_string($kode) && ctype_digit($kode)) {
            $kode = (int) $kode;
        }

        if (! is_int($kode) || ! isset(self::PETA[$kode])) {
            throw ValidationException::forField($field, 'Jenis layanan harus 1-5.');
        }

        return $kode;
    }

    /**
     * Label per kode dari master `jenis_layanan` (kolom `table`), dengan label bawaan bila tidak ada.
     *
     * @return array<int, string>
     */
    public static function label(BaseConnection $db): array
    {
        $tabel = array_map(static fn (array $p): string => $p[0], self::PETA);
        $nama  = [];

        foreach ($db->table('jenis_layanan')->select('table, jenis_layanan')->whereIn('table', array_values($tabel))->get()->getResultArray() as $r) {
            $nama[(string) $r['table']] ??= (string) $r['jenis_layanan'];
        }

        $hasil = [];

        foreach (self::PETA as $k => [$t, , $bawaan]) {
            $hasil[$k] = isset($nama[$t]) && trim($nama[$t]) !== '' ? $nama[$t] : $bawaan;
        }

        return $hasil;
    }
}
