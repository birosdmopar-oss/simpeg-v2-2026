<?php

declare(strict_types=1);

namespace App\Libraries\Bkn;

use App\Libraries\CacheService;
use App\Libraries\Integrasi\Http\KlienHttp;
use App\Libraries\Integrasi\Http\OpsiTransport;
use App\Libraries\Integrasi\Http\Permintaan;
use App\Libraries\Integrasi\Http\Respons;
use App\Libraries\Integrasi\KonfigurasiIntegrasi;
use Closure;

/**
 * Penyedia token BKN (PRD Fase 6 §7.0.2, MAKE-063; bentuk ikut legacy `FH:2513-2955` — diekstrak tanpa nilai, CR-092/Q6-17).
 *
 * - Token API gateway [K `getTokenApi_siasn`]: POST form, `Authorization: Basic base64(clientId:clientSecret)`,
 *   `grant_type=client_credentials`.
 * - Token SSO SIASN [K `getTokenSSO_siasn`]: POST form, Basic kredensial gateway, `grant_type=password` + `client_id`,
 *   `username`, `password` (+ `totp` bila dikonfigurasi).
 * - Token e-Kinerja: `ekinerjaToken` statis dari `.env` bila terisi [padanan cron legacy aktif yang memakai token tetap —
 *   di legacy literal di kode, di v2 hanya dari konfigurasi]; bila kosong, SSO e-Kinerja [K `getTokenSSO_siasn_2`]
 *   (Basic `ekinerjaApiUser:ekinerjaApiPass` + `ekinerjaSso*`, `totp` opsional).
 * - Cache lewat {@see CacheService} sampai `expires_in − 60` detik (legacy: tabel `siasn_simpeg.api_token`/`api_ssobkn`);
 *   tanpa `expires_in` yang cukup → tidak di-cache.
 * - SIASN dipanggil dengan DUA header [K `getApi_siasn`]: `Authorization: Bearer <gateway>` + `Auth: bearer <SSO>`;
 *   e-Kinerja satu header `Authorization: Bearer <token>`. Respons 401 → token dibuang dan permintaan diulang SEKALI.
 *
 * Tidak ada token/kredensial literal di kode (Q6-08, AC-03.4); nilai rahasia diteruskan ke {@see Permintaan::$rahasia}
 * agar tersensor dari log (AC-K.1). Validasi konfigurasi per pemakaian: {@see self::pastikanSiasn()} /
 * {@see self::pastikanEkinerja()} dipanggil konstruktor adapter (AC-K.4).
 */
final class TokenBkn
{
    public const LAYANAN = 'bkn';

    private const SISA_DETIK = 60;

    private const CACHE_GATEWAY = 'bkn_token_gateway';

    private const CACHE_SSO = 'bkn_token_sso';

    private const CACHE_SSO_EKINERJA = 'bkn_token_sso_ekinerja';

    /**
     * @param array<string, bool|float|string> $konfigurasi `Config\Integrations::$bkn`
     */
    public function __construct(
        private readonly KlienHttp $klien,
        private readonly CacheService $cache,
        private readonly array $konfigurasi,
    ) {
    }

    public function pastikanSiasn(): void
    {
        KonfigurasiIntegrasi::pastikan(self::LAYANAN, $this->konfigurasi, [
            'baseUrlApi', 'tokenUrlGateway', 'tokenUrlSso', 'clientId', 'clientSecret', 'ssoClientId', 'ssoUsername', 'ssoPassword',
        ]);
    }

    public function pastikanEkinerja(): void
    {
        $wajib = $this->teks('ekinerjaToken') !== ''
            ? ['baseUrlEkinerja']
            : ['baseUrlEkinerja', 'tokenUrlSso', 'ekinerjaApiUser', 'ekinerjaApiPass', 'ekinerjaSsoClientId', 'ekinerjaSsoUsername', 'ekinerjaSsoPassword'];

        KonfigurasiIntegrasi::pastikan(self::LAYANAN, $this->konfigurasi, $wajib);
    }

    /**
     * @return array<string, string>
     */
    public function headerSiasn(): array
    {
        return [
            'Authorization' => 'Bearer ' . $this->tokenGateway(),
            'Auth'          => 'bearer ' . $this->tokenSsoSiasn(),
            'accept'        => 'application/json',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function headerEkinerja(): array
    {
        return ['Authorization' => 'Bearer ' . $this->tokenEkinerja(), 'accept' => 'application/json'];
    }

    /**
     * Kirim permintaan SIASN ber-token; 401 → buang token gateway & SSO, ulang sekali.
     *
     * @param Closure(array<string, string>): Permintaan $buat menerima header auth, mengembalikan permintaan lengkap
     */
    public function kirimSiasn(Closure $buat): Respons
    {
        $respons = $this->kirimDenganHeader($buat, $this->headerSiasn());

        if ($respons->status !== 401) {
            return $respons;
        }

        $this->cache->invalidate(self::CACHE_GATEWAY);
        $this->cache->invalidate(self::CACHE_SSO);

        return $this->kirimDenganHeader($buat, $this->headerSiasn());
    }

    /**
     * Kirim permintaan e-Kinerja ber-token; 401 → buang token SSO e-Kinerja (token statis tidak bisa diperbarui) dan ulang
     * sekali.
     *
     * @param Closure(array<string, string>): Permintaan $buat
     */
    public function kirimEkinerja(Closure $buat): Respons
    {
        $respons = $this->kirimDenganHeader($buat, $this->headerEkinerja());

        if ($respons->status !== 401 || $this->teks('ekinerjaToken') !== '') {
            return $respons;
        }

        $this->cache->invalidate(self::CACHE_SSO_EKINERJA);

        return $this->kirimDenganHeader($buat, $this->headerEkinerja());
    }

    /**
     * @param Closure(array<string, string>): Permintaan $buat
     * @param array<string, string>                      $header
     */
    private function kirimDenganHeader(Closure $buat, array $header): Respons
    {
        $token = array_map(static fn (string $h): string => (string) preg_replace('/^(Bearer|bearer)\s+/', '', $h), array_intersect_key($header, ['Authorization' => 1, 'Auth' => 1]));

        return $this->klien->kirim($buat($header)->denganRahasia(array_values($token)), $this->opsi());
    }

    public function tokenGateway(): string
    {
        return $this->dariCache(self::CACHE_GATEWAY, fn (): Respons => $this->minta('token_gateway', $this->teks('tokenUrlGateway'), $this->teks('clientId'), $this->teks('clientSecret'), [
            'grant_type' => 'client_credentials',
        ]));
    }

    public function tokenSsoSiasn(): string
    {
        return $this->dariCache(self::CACHE_SSO, fn (): Respons => $this->minta('token_sso', $this->teks('tokenUrlSso'), $this->teks('clientId'), $this->teks('clientSecret'), $this->bodySso(
            $this->teks('ssoClientId'),
            $this->teks('ssoUsername'),
            $this->teks('ssoPassword'),
            $this->teks('ssoTotp'),
        )));
    }

    public function tokenEkinerja(): string
    {
        $statis = $this->teks('ekinerjaToken');

        if ($statis !== '') {
            return $statis;
        }

        return $this->dariCache(self::CACHE_SSO_EKINERJA, fn (): Respons => $this->minta('token_sso_ekinerja', $this->teks('tokenUrlSso'), $this->teks('ekinerjaApiUser'), $this->teks('ekinerjaApiPass'), $this->bodySso(
            $this->teks('ekinerjaSsoClientId'),
            $this->teks('ekinerjaSsoUsername'),
            $this->teks('ekinerjaSsoPassword'),
            $this->teks('ekinerjaSsoTotp'),
        )));
    }

    /**
     * @return array<string, string>
     */
    private function bodySso(string $clientId, string $username, string $password, string $totp): array
    {
        $body = ['grant_type' => 'password', 'client_id' => $clientId, 'username' => $username, 'password' => $password];

        if ($totp !== '') {
            $body['totp'] = $totp;
        }

        return $body;
    }

    /**
     * @param Closure(): Respons $minta
     */
    private function dariCache(string $kunci, Closure $minta): string
    {
        $tersimpan = $this->cache->get($kunci);

        if (is_string($tersimpan) && $tersimpan !== '') {
            return $tersimpan;
        }

        $respons = $minta();
        $data    = $respons->sukses() ? $respons->json() : null;
        $token   = is_array($data) && is_string($data['access_token'] ?? null) ? $data['access_token'] : '';

        if ($token === '') {
            throw new GalatTokenBkn("Token BKN ({$kunci}) gagal diperoleh: HTTP {$respons->status}. Rincian teredaksi di integrasi_log.");
        }

        $berlaku = is_numeric($data['expires_in'] ?? null) ? (int) $data['expires_in'] - self::SISA_DETIK : 0;

        if ($berlaku > 0) {
            $this->cache->set($kunci, $token, $berlaku);
        }

        return $token;
    }

    /**
     * @param array<string, string> $body
     */
    private function minta(string $operasi, string $url, string $user, string $pass, array $body): Respons
    {
        $rahasia = array_values(array_filter([$user, $pass, $body['password'] ?? '', $body['totp'] ?? '', base64_encode($user . ':' . $pass)], static fn (string $s): bool => $s !== ''));

        return $this->klien->kirim(new Permintaan(
            metode: 'POST',
            url: $url,
            layanan: self::LAYANAN,
            operasi: $operasi,
            header: ['Authorization' => 'Basic ' . base64_encode($user . ':' . $pass), 'accept' => 'application/json'],
            form: $body,
            rahasia: $rahasia,
        ), $this->opsi());
    }

    private function opsi(): OpsiTransport
    {
        $timeout = (float) ($this->konfigurasi['timeout'] ?? 30.0);

        return new OpsiTransport(min(5.0, $timeout), $timeout, (bool) ($this->konfigurasi['tlsVerify'] ?? true));
    }

    private function teks(string $kunci): string
    {
        $nilai = $this->konfigurasi[$kunci] ?? '';

        return is_string($nilai) ? trim($nilai) : '';
    }
}
