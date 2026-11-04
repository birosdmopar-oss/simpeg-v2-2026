<?php

declare(strict_types=1);

namespace App\Libraries\Layanan\Cuti;

use App\Exceptions\ApiException;
use Closure;
use CodeIgniter\Database\BaseConnection;

/**
 * Named lock (`GET_LOCK`, milik sesi koneksi) per surat cuti — menserialkan penandatanganan e-sign dan perubahan token
 * publik untuk cuti yang sama (review B4a P1/P3c, MAKE-065):
 *
 * - dua `POST cuti/{id}/esign` paralel tidak sama-sama lolos pembatas percobaan (Q6-20) sebelum baris gagal pertama
 *   tercatat, sehingga passphrase tidak bisa ditebak beruntun lewat permintaan serentak;
 * - rotasi token tidak bisa terjadi di antara pembuatan QR tanda tangan (URL ber-token) dan penyimpanan PDF ber-TTE.
 *
 * Tunggu 0 detik: kunci dipegang permintaan lain → 409 `surat_cuti_sedang_diproses` tanpa memanggil gateway. Diambil DI
 * LUAR transaksi (pola `MasterService`/`HariLiburService`) dan dilepas di `finally`. Nama kunci tanpa NIP (id cuti saja).
 */
final class KunciSuratCuti
{
    public const KODE_SIBUK = 'surat_cuti_sedang_diproses';

    public const PESAN_SIBUK = 'Surat cuti ini sedang diproses. Coba lagi sebentar lagi.';

    /**
     * @template T
     *
     * @param Closure(): T $kerja
     *
     * @return T
     */
    public static function dengan(BaseConnection $db, int $idCuti, Closure $kerja): mixed
    {
        $nama  = self::nama($db, $idCuti);
        $dapat = $db->query('SELECT GET_LOCK(?, 0) AS dapat', [$nama])->getRowArray();

        if ((string) ($dapat['dapat'] ?? '') !== '1') {
            throw new ApiException(self::PESAN_SIBUK, 409, null, self::KODE_SIBUK);
        }

        try {
            return $kerja();
        } finally {
            $db->query('SELECT RELEASE_LOCK(?)', [$nama]);
        }
    }

    /**
     * Per database + prefix (test `t_` dan aplikasi pada server MySQL yang sama tidak saling mengunci); maks. 64 karakter.
     */
    public static function nama(BaseConnection $db, int $idCuti): string
    {
        return 'surat_cuti:' . substr(sha1($db->getDatabase() . '|' . $db->getPrefix()), 0, 16) . ':' . $idCuti;
    }
}
