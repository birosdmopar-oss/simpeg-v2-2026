<?php

declare(strict_types=1);

namespace App\Libraries\Kepegawaian\Kalkulasi;

use InvalidArgumentException;

/**
 * CR-036 — batas usia pensiun (BUP) dan TMT pensiun PNS.
 *
 * Spesifikasi: SRS-FR-033, FSD-FN-H-08, dan Tech Spec H-08 memproyeksikan usia pensiun 58/60/65 menurut jenis jabatan.
 * Legacy: `getTanggalPensiun` (`helpers/function_helper.php:2400-2418`). Fungsi ini dipakai `get_pensiun`
 * (`libraries/hr/L_employee.php:1185-1224`), daftar pensiun per unit/satker (:1226-1273, hanya `id_jenis_pegawai = 1`),
 * dan prediksi dashboard PNS (`libraries/hr/L_user.php:705-720`). Untuk PPPK, legacy memakai akhir masa perjanjian
 * kerja `mhpk_akhir` (:2207-2225), bukan BUP. Karena itu pemanggil menyaring PNS terlebih dahulu.
 *
 * Masukan berasal dari data sistem (tanggal lahir di `pegawai`, jabatan di snapshot). Data salah dilempar sebagai
 * `InvalidArgumentException` (K-CR025-4).
 */
final class Pensiun
{
    /**
     * BUP bawaan legacy (`$masaKerja = 58`).
     */
    public const BUP_UMUM = 58;

    /**
     * BUP pejabat struktural Eselon I/II (komentar legacy "ES.I & ES.II PENSIUN 60 THN").
     */
    public const BUP_ESELON_I_II = 60;

    /**
     * `group_jabatan` 1 = Struktural (G-02).
     */
    public const ID_GROUP_STRUKTURAL = 1;

    /**
     * `sub_group_jabatan` 1 = E.I dan 2 = E.II (legacy `getEselon`, `helpers/function_helper.php:164-166`).
     */
    public const ID_SUB_GROUP_ESELON_I_II = [1, 2];

    private function __construct()
    {
    }

    /**
     * BUP dalam tahun, urutan sama dengan legacy:
     * 1. `jabatan.umur_pensiun` > 0. Di sinilah 65 untuk JF Ahli Utama dan 60 untuk JF Madya dicatat.
     * 2. Struktural Eselon I/II → 60.
     * 3. Selain itu 58.
     *
     * `null` berarti pegawai belum punya jabatan aktif atau nilainya kosong.
     */
    public static function batasUsia(?int $umurPensiunJabatan, ?int $idGroupJabatan, ?int $idSubGroupJabatan): int
    {
        if ($umurPensiunJabatan !== null && $umurPensiunJabatan > 0) {
            return $umurPensiunJabatan;
        }

        if ($idGroupJabatan === self::ID_GROUP_STRUKTURAL
            && in_array($idSubGroupJabatan, self::ID_SUB_GROUP_ESELON_I_II, true)) {
            return self::BUP_ESELON_I_II;
        }

        return self::BUP_UMUM;
    }

    /**
     * TMT pensiun: tanggal 1 pada bulan sesudah bulan ulang tahun ke-`$batasUsia`. Contoh: lahir 15 Maret 1968 dengan
     * BUP 58 → 1 April 2026.
     *
     * Legacy menghitung `tanggal lahir + BUP tahun + 1 bulan` dengan `DateTime::modify`, lalu memformatnya `Y-m-01`.
     * Untuk tanggal lahir 29–31, luapan bulan bisa membuat hasil legacy terlambat satu bulan: 31 Januari 1968 →
     * 1 Maret 2026 (seharusnya 1 Februari), 29 Februari 1968 → 1 April 2026 (seharusnya 1 Maret), dan 31 Maret 1968 →
     * 1 Mei 2026 (seharusnya 1 April). v2 menghitung dari awal bulan lahir sehingga tidak meluap (K-CR025-3). Untuk
     * tanggal lahir 1–28, hasilnya sama dengan legacy.
     */
    public static function tanggalPensiun(string $tanggalLahir, int $batasUsia): string
    {
        TanggalBisnis::wajibValid($tanggalLahir, 'Tanggal lahir');

        if ($batasUsia <= 0) {
            throw new InvalidArgumentException("Batas usia pensiun harus > 0: {$batasUsia}");
        }

        return TanggalBisnis::tambahBulan(TanggalBisnis::awalBulan($tanggalLahir), $batasUsia * 12 + 1);
    }
}
