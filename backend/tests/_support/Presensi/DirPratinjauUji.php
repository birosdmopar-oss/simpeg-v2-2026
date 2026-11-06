<?php

declare(strict_types=1);

namespace Tests\Support\Presensi;

/**
 * Direktori pratinjau & berkas sementara milik test impor Slip Gaji (dipakai ImporSlipUjiTrait), dengan pembersih yang
 * dijaga (CR-108).
 *
 * Latar: dulu `bersihkanImporSlip()` menjalankan `glob($dirPratinjau . '*')` dengan `$dirPratinjau = ''` bawaan. Bila
 * setUp gagal sebelum direktori disiapkan (mis. DB scratch dipakai dua proses PHPUnit), tearDown tetap dipanggil dan
 * `glob('*')` menyapu direktori kerja `backend/` sehingga composer.json, spark, phpunit.dist.xml, dst. terhapus.
 *
 * Pembersih di sini hanya menghapus bila SEMUA syarat terpenuhi; bila satu gagal, tidak ada yang dihapus:
 * - direktori: sudah disiapkan (bukan null/''), ada, bukan symlink, induk langsungnya = direktori temp sistem, dan
 *   namanya berawalan self::AWALAN_DIR; yang dihapus hanya berkas biasa di tingkat pertama direktori itu;
 * - berkas sementara: berkas biasa (bukan symlink), induk langsungnya = direktori temp sistem, dan namanya berawalan
 *   self::AWALAN_BERKAS.
 */
final class DirPratinjauUji
{
    public const AWALAN_DIR    = 'slip-impor-uji-';
    public const AWALAN_BERKAS = 'slipuji';

    /**
     * Path direktori pratinjau baru (belum dibuat) di temp sistem, diakhiri pemisah direktori.
     */
    public static function buat(): string
    {
        return sys_get_temp_dir() . DIRECTORY_SEPARATOR . self::AWALAN_DIR . bin2hex(random_bytes(4)) . DIRECTORY_SEPARATOR;
    }

    /**
     * Path berkas sementara baru (belum dibuat) di temp sistem.
     */
    public static function berkasSementara(string $akhiran): string
    {
        return sys_get_temp_dir() . DIRECTORY_SEPARATOR . self::AWALAN_BERKAS . bin2hex(random_bytes(5)) . $akhiran;
    }

    /**
     * @param list<string> $berkasSementara path dari berkasSementara()
     */
    public static function bersihkan(?string $dir, array $berkasSementara): void
    {
        foreach ($berkasSementara as $path) {
            if (self::berkasMilikUji($path)) {
                @unlink($path);
            }
        }

        $nyata = self::dirMilikUji($dir);

        if ($nyata === null) {
            return;
        }

        foreach (self::berkasDi($nyata) as $nama) {
            @unlink($nyata . DIRECTORY_SEPARATOR . $nama);
        }

        @rmdir($nyata);
    }

    /**
     * Nama berkas (tanpa dotfile) di direktori pratinjau; [] bila direktori belum disiapkan atau tidak lolos guard.
     *
     * @return list<string>
     */
    public static function isi(?string $dir): array
    {
        $nyata = self::dirMilikUji($dir);

        if ($nyata === null) {
            return [];
        }

        return array_values(array_filter(self::berkasDi($nyata), static fn (string $nama): bool => ! str_starts_with($nama, '.')));
    }

    /**
     * Path nyata direktori bila lolos guard, null bila tidak.
     */
    public static function dirMilikUji(?string $dir): ?string
    {
        if ($dir === null || trim($dir) === '') {
            return null;
        }

        $dir = rtrim($dir, '/\\');

        if ($dir === '' || is_link($dir) || ! is_dir($dir)) {
            return null;
        }

        $nyata = realpath($dir);

        if ($nyata === false || ! self::langsungDiTemp($nyata) || ! str_starts_with(basename($nyata), self::AWALAN_DIR)) {
            return null;
        }

        return $nyata;
    }

    public static function berkasMilikUji(string $path): bool
    {
        if (trim($path) === '' || is_link($path) || ! is_file($path)) {
            return false;
        }

        $nyata = realpath($path);

        return $nyata !== false && self::langsungDiTemp($nyata) && str_starts_with(basename($nyata), self::AWALAN_BERKAS);
    }

    /**
     * Induk langsung $nyata (path hasil realpath) = direktori temp sistem.
     */
    private static function langsungDiTemp(string $nyata): bool
    {
        $temp  = realpath(sys_get_temp_dir());
        $induk = realpath(dirname($nyata));

        if ($temp === false || $induk === false) {
            return false;
        }

        // Windows: sistem berkas tidak peka huruf besar/kecil.
        return DIRECTORY_SEPARATOR === '\\' ? strcasecmp($induk, $temp) === 0 : $induk === $temp;
    }

    /**
     * Nama berkas biasa (bukan folder/symlink) di tingkat pertama $nyata.
     *
     * @return list<string>
     */
    private static function berkasDi(string $nyata): array
    {
        $hasil = [];

        foreach (scandir($nyata) ?: [] as $nama) {
            $path = $nyata . DIRECTORY_SEPARATOR . $nama;

            if ($nama !== '.' && $nama !== '..' && is_file($path) && ! is_link($path)) {
                $hasil[] = $nama;
            }
        }

        return $hasil;
    }
}
