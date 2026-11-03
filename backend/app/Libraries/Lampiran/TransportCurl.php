<?php

declare(strict_types=1);

namespace App\Libraries\Lampiran;

use RuntimeException;

/**
 * Transport cURL untuk `lampiran:tarik`: GET, TLS verify aktif, tanpa mengikuti redirect, batas waktu tetap, ukuran
 * respons dibatasi. Tidak menulis log; URL tidak pernah dicetak.
 */
final class TransportCurl implements TransportHttp
{
    public function __construct(private readonly int $timeoutDetik = 60, private readonly int $maksByte = 104_857_600)
    {
        if (! function_exists('curl_init')) {
            throw new RuntimeException('Ekstensi PHP curl wajib untuk lampiran:tarik.');
        }
    }

    public function ambil(string $url): array
    {
        $ch = curl_init($url);

        if ($ch === false) {
            return ['status' => 0, 'tipe' => '', 'lokasi' => null, 'isi' => ''];
        }

        $isi   = '';
        $besar = false;
        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT        => $this->timeoutDetik,
            CURLOPT_HEADER         => false,
            CURLOPT_WRITEFUNCTION  => function ($c, string $potong) use (&$isi, &$besar): int {
                if (strlen($isi) + strlen($potong) > $this->maksByte) {
                    $besar = true;

                    return 0; // batalkan: melebihi batas
                }

                $isi .= $potong;

                return strlen($potong);
            },
        ]);

        $ok = curl_exec($ch);

        if ($besar) {
            curl_close($ch);

            return ['status' => -1, 'tipe' => '', 'lokasi' => null, 'isi' => ''];
        }

        $status = $ok === false ? 0 : (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $tipe   = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $lokasi = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        curl_close($ch);

        return ['status' => $status, 'tipe' => $tipe, 'lokasi' => is_string($lokasi) && $lokasi !== '' ? $lokasi : null, 'isi' => $ok === false ? '' : $isi];
    }
}
