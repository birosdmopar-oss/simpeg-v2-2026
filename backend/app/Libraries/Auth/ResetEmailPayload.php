<?php

declare(strict_types=1);

namespace App\Libraries\Auth;

use Config\Encryption as EncryptionConfig;
use Config\Services;
use SensitiveParameter;
use Throwable;

/**
 * Isi job antrean `reset-password-email` (CR-014, keputusan E2): token dan tautan reset TIDAK pernah tersimpan polos
 * di queue_jobs / queue_jobs_failed.
 *
 * data job = { v: 1, id_pengguna, sealed }, dengan sealed = base64(Encryption->encrypt(json{ id_pengguna, token, link,
 * expires_at })) — CI4 Encryption (OpenSSL AES-256 + HMAC, encryption.key; rotasi lewat encryption.previousKeys).
 * Alamat email tidak ikut disimpan: worker membacanya ulang dari tabel pengguna saat mengirim.
 */
final class ResetEmailPayload
{
    public const VERSION = 1;

    /**
     * @return array{v: int, id_pengguna: int, sealed: string}
     */
    public static function seal(
        EncryptionConfig $config,
        int $idPengguna,
        #[SensitiveParameter]
        string $token,
        #[SensitiveParameter]
        string $link,
        string $expiresAt,
    ): array {
        $plain = json_encode([
            'id_pengguna' => $idPengguna,
            'token'       => $token,
            'link'        => $link,
            'expires_at'  => $expiresAt,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return [
            'v'           => self::VERSION,
            'id_pengguna' => $idPengguna,
            'sealed'      => base64_encode(Services::encrypter($config)->encrypt($plain)),
        ];
    }

    /**
     * Buka data job. Null bila versi tidak dikenal, data rusak/diubah (HMAC gagal), kunci berbeda, atau id_pengguna
     * di dalam tidak sama dengan di luar — pemanggil TIDAK mengirim apa pun dan tidak mencoba ulang.
     *
     * @param array<array-key, mixed> $data
     *
     * @return array{id_pengguna: int, token: string, link: string, expires_at: string}|null
     */
    public static function open(EncryptionConfig $config, #[SensitiveParameter] array $data): ?array
    {
        if (($data['v'] ?? null) !== self::VERSION || ! is_int($data['id_pengguna'] ?? null) || ! is_string($data['sealed'] ?? null)) {
            return null;
        }

        $raw = base64_decode($data['sealed'], true);

        if ($raw === false || $raw === '') {
            return null;
        }

        try {
            $decoded = json_decode(Services::encrypter($config)->decrypt($raw), true, 4, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        if (
            ! is_array($decoded)
            || ($decoded['id_pengguna'] ?? null) !== $data['id_pengguna']
            || ! is_string($decoded['token'] ?? null) || ! is_string($decoded['link'] ?? null)
            || ! is_string($decoded['expires_at'] ?? null)
            || preg_match('/^[0-9a-f]{64}$/', $decoded['token']) !== 1
            || ! str_ends_with($decoded['link'], '#token=' . rawurlencode($decoded['token']))
        ) {
            return null;
        }

        return [
            'id_pengguna' => $data['id_pengguna'],
            'token'       => $decoded['token'],
            'link'        => $decoded['link'],
            'expires_at'  => $decoded['expires_at'],
        ];
    }
}
