<?php

declare(strict_types=1);

namespace App\Libraries\Push;

use App\Interfaces\PushNotifGatewayInterface;
use App\Interfaces\PushPlatformInterface;
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
 * - `sendMulticast` = loop per token (F6-11); `invalid_tokens` = token dengan galat `UNREGISTERED`, atau `INVALID_ARGUMENT`
 *   yang pesannya menyebut registration token (dihapus job push, AC-04.2). `INVALID_ARGUMENT` lain (payload ditolak, mis.
 *   data terlalu besar) TIDAK menghapus token. POST tidak di-retry otomatis (BP-3); retry = antrean job.
 * - Per platform ({@see PushPlatformInterface}): web → `webpush.notification.icon` (= `data.icon` bila ada), tautan tetap di
 *   `data.link` (dibuka service worker; `webpush.fcm_options.link` sengaja tidak dipakai karena wajib URL https absolut);
 *   Android → `android.priority` HIGH + `notification.sound` default + `click_action` = judul [K `Cron.php:1036-1046`].
 * - Token perangkat, token akses, dan kunci privat tidak pernah masuk log (AC-K.1, BP-5). Kunci privat TIDAK disimpan di
 *   properti (review MAKE-066 S1): berkas dibaca ulang hanya saat menandatangani JWT (token akses di-cache ±1 jam di cache aplikasi);
 *   parameter token/kunci bertanda `#[\SensitiveParameter]`. `token_uri` dipatok ke {@see self::TOKEN_URI} (S6).
 * - Galat OAuth (token akses gagal diperoleh) dihitung sebagai kiriman gagal, bukan dilempar dari tengah multicast (S2).
 * - {@see self::ujiKoneksi()} (`integrasi:uji-koneksi fcm`, CR-101): token akses baru + `messages:send` `validate_only` ke
 *   topik uji — tanpa pesan ke perangkat mana pun.
 */
final class FcmV1Adapter implements PushNotifGatewayInterface, PushPlatformInterface
{
    public const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    public const URL_KIRIM = 'https://fcm.googleapis.com/v1/projects/%s/messages:send';

    private const GRANT = 'urn:ietf:params:oauth:grant-type:jwt-bearer';

    private const CACHE_TOKEN = 'fcm_token_akses';

    /** Endpoint token OAuth Google; berkas service account dengan `token_uri` lain ditolak (review MAKE-066 S6). */
    public const TOKEN_URI = 'https://oauth2.googleapis.com/token';

    /** Topik untuk uji koneksi `validate_only` (tidak ada perangkat yang berlangganan; pesan tidak dikirim). */
    public const TOPIK_UJI = 'simpeg-uji-koneksi';

    /** Ikon notifikasi web (aset SPA `frontend/public`; ikon legacy `/assets/images/logo-simpeg-v1-200x200.png` tidak ada di v2). */
    public const IKON_BAWAAN = '/apple-touch-icon.png';

    private string $projectId;

    private string $clientEmail;

    private string $credentialsPath;

    /** @var Closure(string): string */
    private Closure $baca;

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

        $this->baca            = $baca;
        $this->credentialsPath = trim((string) $konfigurasi['credentialsPath']);
        $this->clientEmail     = $this->akunLayanan()['client_email'];
    }

    public function send(#[\SensitiveParameter] string $deviceToken, string $title, string $body, array $data = []): array
    {
        return $this->kirimSatu($deviceToken, $title, $body, $data, null)[0];
    }

    public function sendMulticast(array $deviceTokens, string $title, string $body, array $data = []): array
    {
        return $this->multicast(null, $deviceTokens, $title, $body, $data);
    }

    public function sendMulticastPlatform(int $platform, array $deviceTokens, string $title, string $body, array $data = []): array
    {
        if (! in_array($platform, [self::WEB, self::ANDROID], true)) {
            throw new RuntimeException("Platform push {$platform} tidak dikenal.");
        }

        return $this->multicast($platform, $deviceTokens, $title, $body, $data);
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
            'iss'   => $this->clientEmail,
            'scope' => self::SCOPE,
            'aud'   => self::TOKEN_URI,
            'iat'   => $iat,
            'exp'   => $iat + 3600,
        ], $this->akunLayanan()['private_key'], 'RS256');

        $respons = $this->klien->kirim(new Permintaan(
            metode: 'POST',
            url: self::TOKEN_URI,
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
     * Uji koneksi nyata (`integrasi:uji-koneksi fcm`, BP-11, CR-101): token akses OAuth BARU (cache dibuang dulu agar
     * kredensial service account benar-benar diuji; worker berikutnya meminta token lagi) lalu satu `messages:send`
     * `validate_only: true` ke {@see self::TOPIK_UJI} — FCM memeriksa proyek, izin service account, dan bentuk pesan TANPA
     * mengirim ke perangkat mana pun. Hasil hanya kode (`ok`, `token_oauth`, status galat FCM mis. `PERMISSION_DENIED` /
     * `NOT_FOUND`, `respons`); projectId, token, dan kunci tidak pernah dikembalikan. Rincian teredaksi di `integrasi_log`.
     *
     * @return array{ok: bool, http: string, kode: string}
     */
    public function ujiKoneksi(): array
    {
        $this->lupakanTokenAkses();

        try {
            $akses = $this->tokenAkses();
        } catch (GalatTransport $e) {
            return ['ok' => false, 'http' => $e->timeout ? 'timeout' : 'koneksi', 'kode' => 'token_oauth'];
        } catch (RuntimeException) {
            return ['ok' => false, 'http' => '-', 'kode' => 'token_oauth'];
        }

        try {
            $respons = $this->klien->kirim(new Permintaan(
                metode: 'POST',
                url: sprintf(self::URL_KIRIM, rawurlencode($this->projectId)),
                layanan: 'fcm',
                operasi: 'uji_koneksi',
                header: ['Authorization' => 'Bearer ' . $akses, 'Content-Type' => 'application/json'],
                json: ['validate_only' => true, 'message' => ['topic' => self::TOPIK_UJI, 'notification' => ['title' => 'Uji koneksi SIMPEG', 'body' => 'validate_only']]],
                rahasia: [$akses],
            ));
        } catch (GalatTransport $e) {
            return ['ok' => false, 'http' => $e->timeout ? 'timeout' : 'koneksi', 'kode' => 'messages_send'];
        }

        if ($respons->status === 401) {
            $this->lupakanTokenAkses();
        }

        if ($respons->sukses()) {
            return ['ok' => true, 'http' => (string) $respons->status, 'kode' => 'ok'];
        }

        $json = $respons->json();

        return ['ok' => false, 'http' => (string) $respons->status, 'kode' => is_array($json) ? $this->statusGalat($json) : 'respons'];
    }

    /**
     * @param list<string>          $deviceTokens
     * @param array<string, string> $data
     *
     * @return array{status_code: string, message: string, success_count: int, failure_count: int, invalid_tokens: list<string>}
     */
    private function multicast(?int $platform, array $deviceTokens, string $title, string $body, array $data): array
    {
        $sukses   = 0;
        $gagal    = 0;
        $invalid  = [];
        $terakhir = '';

        foreach ($deviceTokens as $token) {
            [$hasil, $tidakSah] = $this->kirimSatu($token, $title, $body, $data, $platform);

            if ($hasil['status_code'] === '200') {
                $sukses++;

                continue;
            }

            $gagal++;
            $terakhir = $hasil['message'];

            if ($tidakSah) {
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
     * @param array<string, string> $data
     *
     * @return array{0: array{status_code: string, message: string, message_id: string|null}, 1: bool} hasil + token tidak sah
     */
    private function kirimSatu(#[\SensitiveParameter] string $deviceToken, string $title, string $body, array $data, ?int $platform): array
    {
        if (trim($deviceToken) === '') {
            return [['status_code' => '400', 'message' => 'Device token kosong.', 'message_id' => null], false];
        }

        try {
            $respons = $this->kirim($deviceToken, $title, $body, $data, $platform);
        } catch (GalatTransport $e) {
            return [['status_code' => $e->timeout ? 'timeout' : 'koneksi', 'message' => $e->getMessage(), 'message_id' => null], false];
        } catch (RuntimeException $e) {
            // Token akses OAuth gagal diperoleh (review MAKE-066 S2): kiriman gagal, token perangkat tidak dianggap tidak sah.
            return [['status_code' => 'oauth', 'message' => $e->getMessage(), 'message_id' => null], false];
        }

        $json = $respons->json() ?? [];

        if ($respons->sukses()) {
            return [['status_code' => '200', 'message' => 'Terkirim.', 'message_id' => is_string($json['name'] ?? null) ? $json['name'] : null], false];
        }

        return [['status_code' => (string) $respons->status, 'message' => $this->statusGalat($json), 'message_id' => null], $this->tokenTidakSah($json)];
    }

    /**
     * @param array<string, string> $data
     */
    private function kirim(#[\SensitiveParameter] string $deviceToken, string $title, string $body, array $data, ?int $platform): Respons
    {
        $akses = $this->tokenAkses();
        $pesan = ['token' => $deviceToken, 'notification' => ['title' => $title, 'body' => $body]];

        if ($data !== []) {
            $pesan['data'] = array_map('strval', $data);
        }

        if ($platform === self::WEB) {
            $pesan['webpush'] = ['notification' => ['icon' => (string) ($data['icon'] ?? self::IKON_BAWAAN)]];
        } elseif ($platform === self::ANDROID) {
            $pesan['android'] = ['priority' => 'HIGH', 'notification' => ['sound' => 'default', 'click_action' => $title]];
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
     * Isi berkas service account (dibaca saat dibutuhkan; kunci privat tidak disimpan di properti, review MAKE-066 S1).
     *
     * @return array{client_email: string, private_key: string}
     */
    private function akunLayanan(): array
    {
        $data = json_decode(($this->baca)($this->credentialsPath), true);

        foreach (['client_email', 'private_key', 'token_uri'] as $kunci) {
            if (! is_array($data) || ! is_string($data[$kunci] ?? null) || trim($data[$kunci]) === '') {
                throw new RuntimeException("Berkas service account FCM tidak sah: kolom {$kunci} kosong.");
            }
        }

        if (rtrim($data['token_uri'], '/') !== self::TOKEN_URI) {
            throw new RuntimeException('Berkas service account FCM tidak sah: token_uri bukan endpoint OAuth Google.');
        }

        return ['client_email' => $data['client_email'], 'private_key' => $data['private_key']];
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

    /**
     * Token perangkat tidak sah/tidak terdaftar (AC-04.2): `UNREGISTERED`, atau `INVALID_ARGUMENT` yang pesannya menyebut
     * registration token. `INVALID_ARGUMENT` karena payload tidak menghapus token.
     *
     * @param array<mixed> $json
     */
    private function tokenTidakSah(array $json): bool
    {
        $status = $this->statusGalat($json);

        if ($status === 'UNREGISTERED') {
            return true;
        }

        $pesan = is_array($json['error'] ?? null) && is_string($json['error']['message'] ?? null) ? $json['error']['message'] : '';

        return $status === 'INVALID_ARGUMENT' && stripos($pesan, 'registration token') !== false;
    }
}
