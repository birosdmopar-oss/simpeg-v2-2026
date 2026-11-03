<?php

declare(strict_types=1);

namespace App\Libraries\Push;

use App\Interfaces\PushNotifGatewayInterface;
use App\Libraries\CacheService;
use App\Libraries\Integrasi\Http\GalatTransport;
use App\Libraries\Integrasi\Http\KlienHttp;
use App\Libraries\Integrasi\Http\Permintaan;
use App\Libraries\Integrasi\Http\Respons;
use App\Libraries\Integrasi\KonfigurasiIntegrasi;
use Closure;
use Firebase\JWT\JWT;
use RuntimeException;

/**
 * Adapter FCM **HTTP v1** (PRD Fase 6 §7.4, F6-11; H-04 MAKE-066) — implementasi {@see PushNotifGatewayInterface} tanpa
 * mengubah kontrak. FCM Legacy API (server key, dipakai legacy) sudah dihentikan Google, jadi protokol wajib berganti;
 * siapa menerima apa tetap ikut legacy (ditentukan produsen/job, bukan adapter).
 *
 * - Auth: OAuth2 service account — JWT RS256 (`firebase/php-jwt`) `{iss: client_email, scope, aud: token_uri, iat, exp}`
 *   → `POST token_uri` (`grant_type=urn:ietf:params:oauth:grant-type:jwt-bearer`); token akses di-cache sampai
 *   `expires_in − 60` dtk. Berkas service account dari `push.credentialsPath` (di luar repo & webroot), dibaca saat dibuat.
 * - Kirim: `POST https://fcm.googleapis.com/v1/projects/{projectId}/messages:send`
 *   `{message: {token, notification: {title, body}, data}}`; `data` = pasangan string (kontrak). Sukses → `status_code`
 *   '200' + `message_id` (`name`). Gagal → kode HTTP sebagai `status_code`, `message` = status galat FCM.
 * - `sendMulticast` = loop per token (F6-11); `invalid_tokens` = token dengan galat `UNREGISTERED` / `INVALID_ARGUMENT`
 *   (dihapus job `push-notif`, AC-04.2). POST tidak di-retry otomatis (BP-3); retry = antrean job.
 * - Token perangkat, token akses, dan kunci privat tidak pernah masuk log (AC-K.1, BP-5).
 */
final class FcmV1Adapter implements PushNotifGatewayInterface
{
    public const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    public const URL_KIRIM = 'https://fcm.googleapis.com/v1/projects/%s/messages:send';

    private const GRANT = 'urn:ietf:params:oauth:grant-type:jwt-bearer';

    private const CACHE_TOKEN = 'fcm_token_akses';

    private const KODE_TOKEN_TIDAK_SAH = ['UNREGISTERED', 'INVALID_ARGUMENT'];

    private string $projectId;

    /** @var array{client_email: string, private_key: string, token_uri: string} */
    private array $akun;

    /** @var Closure(): int */
    private Closure $waktu;

    /**
     * @param array<string, mixed>          $konfigurasi `Config\Integrations::$push`
     * @param (Closure(string): string)|null $baca        pembaca berkas (test)
     * @param (Closure(): int)|null          $waktu       jam UNIX (test)
     */
    public function __construct(
        private readonly KlienHttp $klien,
        private readonly CacheService $cache,
        array $konfigurasi,
        ?Closure $baca = null,
        ?Closure $waktu = null,
    ) {
        KonfigurasiIntegrasi::pastikan('push', $konfigurasi, ['projectId', 'credentialsPath']);

        $this->projectId = trim((string) $konfigurasi['projectId']);
        $this->waktu     = $waktu ?? static fn (): int => time();
        $baca ??= static function (string $path): string {
            $isi = is_file($path) && is_readable($path) ? file_get_contents($path) : false;

            if ($isi === false) {
                throw new RuntimeException('Berkas service account FCM (integrations.push.credentialsPath) tidak terbaca.');
            }

            return $isi;
        };

        $data = json_decode($baca(trim((string) $konfigurasi['credentialsPath'])), true);

        foreach (['client_email', 'private_key', 'token_uri'] as $kunci) {
            if (! is_array($data) || ! is_string($data[$kunci] ?? null) || trim($data[$kunci]) === '') {
                throw new RuntimeException("Berkas service account FCM tidak sah: kolom {$kunci} kosong.");
            }
        }

        $this->akun = ['client_email' => $data['client_email'], 'private_key' => $data['private_key'], 'token_uri' => $data['token_uri']];
    }

    public function send(string $deviceToken, string $title, string $body, array $data = []): array
    {
        if (trim($deviceToken) === '') {
            return ['status_code' => '400', 'message' => 'Device token kosong.', 'message_id' => null];
        }

        try {
            $respons = $this->kirim($deviceToken, $title, $body, $data);
        } catch (GalatTransport $e) {
            return ['status_code' => $e->timeout ? 'timeout' : 'koneksi', 'message' => $e->getMessage(), 'message_id' => null];
        }

        $json = $respons->json() ?? [];

        if ($respons->sukses()) {
            return ['status_code' => '200', 'message' => 'Terkirim.', 'message_id' => is_string($json['name'] ?? null) ? $json['name'] : null];
        }

        return ['status_code' => (string) $respons->status, 'message' => $this->statusGalat($json), 'message_id' => null];
    }

    public function sendMulticast(array $deviceTokens, string $title, string $body, array $data = []): array
    {
        $sukses   = 0;
        $gagal    = 0;
        $invalid  = [];
        $terakhir = '';

        foreach ($deviceTokens as $token) {
            $hasil = $this->send($token, $title, $body, $data);

            if ($hasil['status_code'] === '200') {
                $sukses++;

                continue;
            }

            $gagal++;
            $terakhir = $hasil['message'];

            if (in_array($hasil['message'], self::KODE_TOKEN_TIDAK_SAH, true)) {
                $invalid[] = $token;
            }
        }

        return [
            'status_code'    => $sukses > 0 || $gagal === 0 ? '200' : '502',
            'message'        => sprintf('Multicast %d sukses, %d gagal%s.', $sukses, $gagal, $terakhir !== '' ? " (terakhir: {$terakhir})" : ''),
            'success_count'  => $sukses,
            'failure_count'  => $gagal,
            'invalid_tokens' => $invalid,
        ];
    }

    /**
     * Token akses OAuth service account (dipakai juga manajemen topik F6-12).
     */
    public function tokenAkses(): string
    {
        $tersimpan = $this->cache->get(self::CACHE_TOKEN);

        if (is_string($tersimpan) && $tersimpan !== '') {
            return $tersimpan;
        }

        $iat = ($this->waktu)();
        $jwt = JWT::encode([
            'iss'   => $this->akun['client_email'],
            'scope' => self::SCOPE,
            'aud'   => $this->akun['token_uri'],
            'iat'   => $iat,
            'exp'   => $iat + 3600,
        ], $this->akun['private_key'], 'RS256');

        $respons = $this->klien->kirim(new Permintaan(
            metode: 'POST',
            url: $this->akun['token_uri'],
            layanan: 'fcm',
            operasi: 'token_oauth',
            form: ['grant_type' => self::GRANT, 'assertion' => $jwt],
            rahasia: [$jwt],
        ));

        $data  = $respons->sukses() ? $respons->json() : null;
        $token = is_array($data) && is_string($data['access_token'] ?? null) ? $data['access_token'] : '';

        if ($token === '') {
            throw new RuntimeException("Token akses FCM gagal diperoleh: HTTP {$respons->status}. Rincian teredaksi di integrasi_log.");
        }

        $berlaku = is_numeric($data['expires_in'] ?? null) ? (int) $data['expires_in'] - 60 : 0;

        if ($berlaku > 0) {
            $this->cache->set(self::CACHE_TOKEN, $token, $berlaku);
        }

        return $token;
    }

    /**
     * Buang token akses tersimpan (HTTP 401) supaya permintaan berikutnya meminta token baru.
     */
    public function lupakanTokenAkses(): void
    {
        $this->cache->invalidate(self::CACHE_TOKEN);
    }

    /**
     * @param array<string, string> $data
     */
    private function kirim(string $deviceToken, string $title, string $body, array $data): Respons
    {
        $akses = $this->tokenAkses();
        $pesan = ['token' => $deviceToken, 'notification' => ['title' => $title, 'body' => $body]];

        if ($data !== []) {
            $pesan['data'] = array_map('strval', $data);
        }

        $respons = $this->klien->kirim(new Permintaan(
            metode: 'POST',
            url: sprintf(self::URL_KIRIM, rawurlencode($this->projectId)),
            layanan: 'fcm',
            operasi: 'messages_send',
            header: ['Authorization' => 'Bearer ' . $akses, 'Content-Type' => 'application/json'],
            json: ['message' => $pesan],
            rahasia: [$akses, $deviceToken],
        ));

        if ($respons->status === 401) {
            $this->lupakanTokenAkses();
        }

        return $respons;
    }

    /**
     * Status galat FCM v1: `error.details[].errorCode` (mis. UNREGISTERED) bila ada, selain itu `error.status`.
     *
     * @param array<mixed> $json
     */
    private function statusGalat(array $json): string
    {
        $galat = is_array($json['error'] ?? null) ? $json['error'] : [];

        foreach ((array) ($galat['details'] ?? []) as $d) {
            if (is_array($d) && is_string($d['errorCode'] ?? null)) {
                return $d['errorCode'];
            }
        }

        return is_string($galat['status'] ?? null) ? $galat['status'] : 'FCM gagal';
    }
}
