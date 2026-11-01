<?php

declare(strict_types=1);

namespace App\Libraries\Impor;

/**
 * Penolakan target (murni). Tidak memakai port: larangan 3306 hanya berlaku di mesin coder, bukan di mesin DBA.
 */
final class PenjagaTujuan
{
    private function __construct()
    {
    }

    /**
     * @param list<string> $terlarang `Config\ImporLegacy::$tujuanTerlarang`
     */
    public static function periksa(string $tujuan, string $sumber, ?string $konfirmasi, array $terlarang): void
    {
        $t = strtolower(trim($tujuan));

        if ($t === '') {
            throw new ImporGagal('Database tujuan kosong.');
        }

        foreach ($terlarang as $nama) {
            if ($t === strtolower($nama)) {
                throw new ImporGagal("Database tujuan '{$tujuan}' terlarang untuk impor.");
            }
        }

        if (str_starts_with($t, 'prod')) {
            throw new ImporGagal("Database tujuan '{$tujuan}' tampak seperti produksi.");
        }

        if ($t === strtolower(trim($sumber))) {
            throw new ImporGagal('Database sumber dan tujuan sama.');
        }

        if ($konfirmasi === null || $konfirmasi !== $tujuan) {
            throw new ImporGagal('Opsi --konfirmasi harus sama persis dengan nama database tujuan.');
        }
    }
}
