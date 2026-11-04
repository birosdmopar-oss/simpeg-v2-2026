<?php

declare(strict_types=1);

namespace Tests\Support\Integrasi;

use RuntimeException;

/**
 * Pasangan kunci RSA 2048 yang dibuat saat test berjalan (tidak ada kunci di repo) untuk adapter FCM tiruan.
 */
final class KunciRsaUji
{
    /** @var array{privat: string, publik: string}|null */
    private static ?array $pasangan = null;

    /**
     * @return array{privat: string, publik: string}
     */
    public static function pasangan(): array
    {
        if (self::$pasangan !== null) {
            return self::$pasangan;
        }

        $opsi  = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
        $kunci = openssl_pkey_new($opsi);
        $cnf   = null;

        try {
            if ($kunci === false) {
                // Windows tanpa openssl.cnf: pakai konfigurasi minimal sementara (dihapus sesudahnya, CR-099 S7).
                $cnf = tempnam(sys_get_temp_dir(), 'cnf');
                file_put_contents((string) $cnf, "[req]\ndistinguished_name=dn\n[dn]\n");
                $opsi['config'] = $cnf;
                $kunci          = openssl_pkey_new($opsi);
            }

            if ($kunci === false || ! openssl_pkey_export($kunci, $privat, null, $opsi)) {
                throw new RuntimeException('Kunci RSA uji gagal dibuat.');
            }
        } finally {
            if (is_string($cnf) && is_file($cnf)) {
                unlink($cnf);
            }
        }

        return self::$pasangan = ['privat' => (string) $privat, 'publik' => (string) (openssl_pkey_get_details($kunci)['key'] ?? '')];
    }
}
