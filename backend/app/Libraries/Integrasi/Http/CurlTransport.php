<?php

declare(strict_types=1);

namespace App\Libraries\Integrasi\Http;

use CodeIgniter\HTTP\CURLRequest;
use CodeIgniter\HTTP\Exceptions\HTTPException;
use Config\Services;

/**
 * Transport produksi di atas `CURLRequest` CI4 (BP-3). `http_errors = false`: status 4xx/5xx dikembalikan sebagai
 * {@see Respons}; hanya kegagalan tanpa respons yang menjadi {@see GalatTransport}. Satu instance `CURLRequest` baru per
 * panggilan (tidak berbagi state header/body antar permintaan).
 */
final class CurlTransport implements TransportHttpInterface
{
    public function kirim(Permintaan $permintaan, OpsiTransport $opsi): Respons
    {
        /** @var CURLRequest $klien */
        $klien = Services::curlrequest(self::opsiKlien($opsi), null, null, false);

        $opsiCi = ['headers' => $permintaan->header];

        if ($permintaan->query !== []) {
            $opsiCi['query'] = $permintaan->query;
        }

        if ($permintaan->json !== null) {
            $opsiCi['json'] = $permintaan->json;
        } elseif ($permintaan->form !== null) {
            $opsiCi['form_params'] = $permintaan->form;
        } elseif ($permintaan->multipart !== null) {
            $opsiCi['multipart'] = $this->multipart($permintaan->multipart);
        }

        try {
            $respons = $klien->request(strtoupper($permintaan->metode), $permintaan->url, $opsiCi);
        } catch (HTTPException $e) {
            $pesan = $e->getMessage();

            throw new GalatTransport(self::pesanAman($pesan), stripos($pesan, 'timed out') !== false || str_contains($pesan, '28 '), $e);
        }

        $header = [];

        foreach ($respons->headers() as $nama => $nilai) {
            $header[strtolower((string) $nama)] = is_array($nilai) ? implode(', ', array_map(static fn ($h): string => $h->getValueLine(), $nilai)) : $nilai->getValueLine();
        }

        return new Respons($respons->getStatusCode(), (string) $respons->getBody(), $header);
    }

    /**
     * @param list<array{nama: string, isi: string, namaBerkas?: string, mime?: string}> $bagian
     *
     * @return array<string, \CURLStringFile|string>
     */
    private function multipart(array $bagian): array
    {
        $hasil = [];

        foreach ($bagian as $b) {
            $hasil[$b['nama']] = isset($b['namaBerkas'])
                ? new \CURLStringFile($b['isi'], $b['namaBerkas'], $b['mime'] ?? 'application/pdf')
                : $b['isi'];
        }

        return $hasil;
    }

    /**
     * Opsi klien CI4 dari {@see OpsiTransport}: `verify` false = peer DAN host tidak diverifikasi (CI4: VERIFYPEER false,
     * VERIFYHOST 0) — hanya e-sign, keputusan Horii 03-11-2026; `caBundle` = CURLOPT_CAINFO + verifikasi penuh; redirect
     * tidak pernah diikuti (pengaman BP-3).
     *
     * @return array{timeout: float, connect_timeout: float, verify: bool|string, allow_redirects: false, http_errors: false}
     */
    public static function opsiKlien(OpsiTransport $opsi): array
    {
        return [
            'timeout'         => $opsi->timeout,
            'connect_timeout' => $opsi->connectTimeout,
            'verify'          => $opsi->verifyTls ? ($opsi->caBundle ?? true) : false,
            'allow_redirects' => false,
            'http_errors'     => false,
        ];
    }

    /**
     * Pesan galat cURL tanpa host/IP/URL tujuan (alamat layanan dari `.env` server tidak masuk log, BP-5): sisakan nomor
     * galat + teks generik `curl_strerror`.
     */
    public static function pesanAman(string $pesan): string
    {
        if (preg_match('/^\s*(\d{1,3})\s*:/', $pesan, $m) === 1 && function_exists('curl_strerror')) {
            return 'cURL error ' . $m[1] . ': ' . (curl_strerror((int) $m[1]) ?? 'galat transport');
        }

        return 'Galat transport HTTP (rincian alamat disembunyikan).';
    }
}
