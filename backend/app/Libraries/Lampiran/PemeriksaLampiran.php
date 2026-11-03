<?php

declare(strict_types=1);

namespace App\Libraries\Lampiran;

/**
 * `lampiran:periksa` (MAKE-069 §4): tiap path manifest dicek di akar storage v2 — ada, hilang, atau checksum beda dari
 * yang dicatat penarikan. Hasil hanya jumlah per `tabel.kolom` (satu path dihitung di setiap kolom yang merujuknya).
 */
final class PemeriksaLampiran
{
    private function __construct()
    {
    }

    /**
     * @param array{berkas: array<string, array{sumber: string, rujukan: array<string, int>, sha256: string|null, hasil: string|null}>} $manifest
     *
     * @return array<string, array{ada: int, hilang: int, beda_checksum: int}>
     */
    public static function periksa(array $manifest, string $akar): array
    {
        $hasil = [];

        foreach ($manifest['berkas'] as $path => $b) {
            $lokal = $akar . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, (string) $path);
            $kode  = ! is_file($lokal) ? 'hilang' : ($b['sha256'] !== null && hash_file('sha256', $lokal) !== $b['sha256'] ? 'beda_checksum' : 'ada');

            foreach (array_keys($b['rujukan']) as $label) {
                $hasil[$label] ??= ['ada' => 0, 'hilang' => 0, 'beda_checksum' => 0];
                $hasil[$label][$kode]++;
            }
        }

        ksort($hasil);

        return $hasil;
    }
}
