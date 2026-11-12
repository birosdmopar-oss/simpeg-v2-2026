<?php

declare(strict_types=1);

namespace App\Libraries\Kepegawaian\Arsip;

use App\Interfaces\Kepegawaian\StorageAdapterInterface;
use App\Libraries\Kepegawaian\Lampiran\PathLegacy;
use Config\Arsip;
use Throwable;

/**
 * Status & URL buka satu baris `document_attachment` untuk halaman Arsip (PRD F-10 §7.2, F10-23, F10-24, F10-32, F10-34).
 *
 * Path dinormalisasi lewat {@see PathLegacy::normalisasi()} lalu dicocokkan dengan tiga regex `Config\Arsip`:
 *  - kunci unggahan v2 → `akses_berkas` 'privat' (selalu), `url_berkas` = `pegawai/<nip>/arsip/berkas/<id>/buka`;
 *  - `arsip/…` / `assets/upload/…` (legacy) → 'publik' (`url_berkas` = dasar + path ter-encode per segmen) atau
 *    'privat' saat saklar `penyajianBerkasLegacy` = 'privat';
 *  - lainnya / path tidak sah → `tak_dapat_dibuka` (`url_berkas` null).
 * Cocok tetapi berkas tidak ada di disk (`StorageAdapterInterface::ada()`, galat = tidak ada) → `hilang`.
 *
 * `jenis_buka` dari ekstensi segmen terakhir path ternormalisasi (huruf kecil), bukan kolom `file_ext`. Hasil tidak pernah
 * memuat path mentah selain di dalam `url_berkas` publik berkas legacy (deviasi sadar F10-18 (a)).
 */
final class PenyajiUrlBerkas
{
    public const STATUS_ADA = 'ada';

    public const STATUS_HILANG = 'hilang';

    public const STATUS_TAK_DAPAT_DIBUKA = 'tak_dapat_dibuka';

    public const JENIS_PRATINJAU = 'pratinjau';

    public const JENIS_UNDUH = 'unduh';

    public function __construct(
        private readonly StorageAdapterInterface $storage,
        private readonly Arsip $config,
    ) {
    }

    /**
     * Path tersimpan berbentuk kunci unggahan v2 (`YYYY/MM/<32 hex>.<ext>`)?
     */
    public static function kunciV2(?string $path): bool
    {
        $normal = $path === null ? null : PathLegacy::normalisasi($path);

        return $normal !== null && self::cocok(Arsip::REGEX_KUNCI_V2, $normal);
    }

    /**
     * Path tersimpan cocok salah satu dari dua pola legacy yang disajikan Nginx?
     */
    public static function polaLegacy(string $normal): bool
    {
        return self::cocok(Arsip::REGEX_LEGACY_ARSIP, $normal) || self::cocok(Arsip::REGEX_LEGACY_ASSETS, $normal);
    }

    /**
     * Ekstensi segmen terakhir path ternormalisasi (huruf kecil); '' bila tanpa titik.
     */
    public static function ekstensi(string $normal): string
    {
        $akhir = basename(str_replace('\\', '/', $normal));
        $titik = strrpos($akhir, '.');

        return $titik === false ? '' : strtolower(substr($akhir, $titik + 1));
    }

    /**
     * Content-Type dari daftar tertutup (F10-34 (4)).
     */
    public static function tipe(string $ekstensi): string
    {
        return Arsip::TIPE_PRATINJAU[$ekstensi] ?? Arsip::TIPE_UNDUH;
    }

    /**
     * @return array{berkas_ada: bool, status_berkas: string, akses_berkas: string|null, url_berkas: string|null, jenis_buka: string|null, ekstensi: string}
     */
    public function nilai(?string $path, string $nip, int $idAttachment): array
    {
        $normal = $path === null ? null : PathLegacy::normalisasi($path);

        if ($path === null || $normal === null) {
            return self::tanpaUrl(self::STATUS_TAK_DAPAT_DIBUKA, '');
        }

        $akses = $this->akses($normal);

        if ($akses === null) {
            return self::tanpaUrl(self::STATUS_TAK_DAPAT_DIBUKA, '');
        }

        $ekstensi = self::ekstensi($normal);

        if (! $this->ada($path)) {
            return self::tanpaUrl(self::STATUS_HILANG, $ekstensi);
        }

        return [
            'berkas_ada'    => true,
            'status_berkas' => self::STATUS_ADA,
            'akses_berkas'  => $akses,
            'url_berkas'    => $akses === Arsip::PENYAJIAN_PRIVAT ? self::urlBuka($nip, $idAttachment) : $this->urlPublik($normal),
            'jenis_buka'    => isset(Arsip::TIPE_PRATINJAU[$ekstensi]) ? self::JENIS_PRATINJAU : self::JENIS_UNDUH,
            'ekstensi'      => $ekstensi,
        ];
    }

    /**
     * Path API relatif endpoint `buka` (memuat id, bukan path; F10-34 (2)).
     */
    public static function urlBuka(string $nip, int $idAttachment): string
    {
        return 'pegawai/' . rawurlencode($nip) . '/arsip/berkas/' . $idAttachment . '/buka';
    }

    /**
     * 'privat' (kunci v2, atau berkas legacy saat saklar 'privat'), 'publik' (berkas legacy), null (tidak cocok regex).
     */
    private function akses(string $normal): ?string
    {
        if (self::cocok(Arsip::REGEX_KUNCI_V2, $normal)) {
            return Arsip::PENYAJIAN_PRIVAT;
        }

        if (self::polaLegacy($normal)) {
            return $this->config->modeLegacy();
        }

        return null;
    }

    private function ada(string $path): bool
    {
        try {
            return $this->storage->ada($path);
        } catch (Throwable) {
            return false;
        }
    }

    private function urlPublik(string $normal): string
    {
        $dasar = rtrim($this->config->dasarUrlBerkas, '/');

        return $dasar . '/' . implode('/', array_map('rawurlencode', explode('/', $normal)));
    }

    private static function cocok(string $regex, string $normal): bool
    {
        return preg_match('#' . $regex . '#u', $normal) === 1;
    }

    /**
     * @return array{berkas_ada: bool, status_berkas: string, akses_berkas: null, url_berkas: null, jenis_buka: null, ekstensi: string}
     */
    private static function tanpaUrl(string $status, string $ekstensi): array
    {
        return [
            'berkas_ada'    => false,
            'status_berkas' => $status,
            'akses_berkas'  => null,
            'url_berkas'    => null,
            'jenis_buka'    => null,
            'ekstensi'      => $ekstensi,
        ];
    }
}
