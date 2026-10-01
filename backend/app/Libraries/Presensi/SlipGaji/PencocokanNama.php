<?php

declare(strict_types=1);

namespace App\Libraries\Presensi\SlipGaji;

/**
 * CR-027 (K-CR027-7) — pencocokan nama di berkas impor dengan `pegawai.nama` (tanpa gelar) milik NIP itu, dan format
 * nama bergelar.
 *
 * Kelonggaran pencocokan disengaja [K] (`libraries/hr/Lsl_gaji.php:715-737`): yang dikejar adalah NIP salah ketik yang
 * kebetulan milik orang lain, bukan beda penulisan nama. Konsekuensinya nama pendek yang menjadi bagian nama lain
 * dianggap cocok ("Ani" ~ "Daniel"); evaluasi lewat log ketidakcocokan (M-3, TUNGGU-USER/BK).
 */
final class PencocokanNama
{
    private function __construct()
    {
    }

    /**
     * Huruf kecil ASCII lalu buang semua karakter selain `[a-z0-9]` (M-2). Spasi, tanda baca, dan huruf non-ASCII hilang;
     * huruf gelar tetap ada.
     */
    public static function normalisasi(string $nama): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower($nama)) ?? '';
    }

    /**
     * Cocok bila sama setelah normalisasi atau salah satu substring yang lain; salah satu sisi kosong → tidak cocok (M-3).
     */
    public static function cocok(string $namaBerkas, string $namaDb): bool
    {
        $berkas = self::normalisasi($namaBerkas);
        $db     = self::normalisasi($namaDb);

        if ($berkas === '' || $db === '') {
            return false;
        }

        return str_contains($berkas, $db) || str_contains($db, $berkas);
    }

    /**
     * Nama bergelar `"{gelar awal} {nama}, {gelar akhir}"` setara `formatNamaGelar` legacy
     * (`helpers/function_helper.php:102-108`); setiap bagian di-trim dan bagian kosong dilewati, jadi gelar akhir yang
     * hanya spasi tidak menghasilkan koma menggantung (P-9, usul — format final TUNGGU-USER). Pembersihan `stripslashes`
     * adalah urusan migrasi data, bukan fungsi ini.
     */
    public static function denganGelar(?string $gelarAwal, string $nama, ?string $gelarAkhir): string
    {
        $depan = trim(implode(' ', array_filter(
            [NilaiSel::rapikan($gelarAwal ?? ''), NilaiSel::rapikan($nama)],
            static fn (string $bagian): bool => $bagian !== '',
        )));
        $akhir = NilaiSel::rapikan($gelarAkhir ?? '');

        if ($akhir === '') {
            return $depan;
        }

        return $depan === '' ? $akhir : "{$depan}, {$akhir}";
    }
}
