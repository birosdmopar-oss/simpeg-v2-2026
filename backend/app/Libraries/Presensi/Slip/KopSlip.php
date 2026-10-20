<?php

declare(strict_types=1);

namespace App\Libraries\Presensi\Slip;

use App\Libraries\MasterData\WebConfigTypes;

/**
 * Kop PDF slip gaji dari nilai `web_config` (PRD 5A §7.3.6, G-09) — dipakai service slip sebelum membentuk
 * {@see DataPdfSlip}; generator PDF tidak me-resolve path sendiri.
 *
 * - Teks kop: `pdf_header_nama_kementerian` (bawaan `nama_kementerian`, pola PDF LKH), `pdf_header_nama_unit`,
 *   `pdf_header_alamat_kementerian` (HTML tersanitasi saat simpan).
 * - Logo: `logo_kementerian_pdf` (kiri) dan `logo_wonderful_pdf` (kanan), tipe ASSET_PATH = path relatif dari akar aset
 *   v2 (`Config\Presensi::$akarAset`). Kosong / tidak sah (path absolut, `..`, ekstensi lain) / berkas tidak ada / keluar
 *   dari akar (symlink) → tanpa logo, PDF tetap dibuat (seperti PDF LKH). Cek PNG/JPEG isi berkas di
 *   {@see PdfSlipGaji::logo()}.
 * - Nama instansi pada kalimat "adalah benar pegawai …": `nama_kementerian`; kosong → literal legacy [K]
 *   {@see DataPdfSlip::NAMA_INSTANSI_BAWAAN}.
 */
final class KopSlip
{
    /**
     * @param array<string, mixed> $wc nilai `WebConfigService::values()`
     *
     * @return array{kop: array{nama_kementerian: string, nama_unit: string, alamat: string}, logoKiri: string|null, logoKanan: string|null, namaInstansi: string}
     */
    public static function dariWebConfig(array $wc, string $akarAset): array
    {
        $namaKementerian = self::teks($wc['nama_kementerian'] ?? null);
        $headerKem       = self::teks($wc['pdf_header_nama_kementerian'] ?? null);

        return [
            'kop' => [
                'nama_kementerian' => $headerKem !== '' ? $headerKem : $namaKementerian,
                'nama_unit'        => self::teks($wc['pdf_header_nama_unit'] ?? null),
                'alamat'           => self::teks($wc['pdf_header_alamat_kementerian'] ?? null),
            ],
            'logoKiri'     => self::resolveAset($akarAset, $wc['logo_kementerian_pdf'] ?? null),
            'logoKanan'    => self::resolveAset($akarAset, $wc['logo_wonderful_pdf'] ?? null),
            'namaInstansi' => $namaKementerian !== '' ? $namaKementerian : DataPdfSlip::NAMA_INSTANSI_BAWAAN,
        ];
    }

    /**
     * Path absolut berkas aset dari path relatif ASSET_PATH; null bila kosong, tidak sah, tidak ada, atau keluar dari akar.
     */
    public static function resolveAset(string $akarAset, mixed $relatif): ?string
    {
        if (! is_string($relatif) || trim($relatif) === ''
            || ! WebConfigTypes::isValidStored(['type' => WebConfigTypes::ASSET_PATH], $relatif)) {
            return null;
        }

        $akar = realpath($akarAset);

        if ($akar === false || ! is_dir($akar)) {
            return null;
        }

        $akar = rtrim($akar, '\\/') . DIRECTORY_SEPARATOR;
        $path = realpath($akar . str_replace('/', DIRECTORY_SEPARATOR, $relatif));

        if ($path === false || ! is_file($path) || ! str_starts_with($path, $akar)) {
            return null;
        }

        return $path;
    }

    private static function teks(mixed $nilai): string
    {
        return is_string($nilai) ? trim($nilai) : '';
    }
}
