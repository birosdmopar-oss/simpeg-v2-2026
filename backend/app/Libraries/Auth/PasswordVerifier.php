<?php

declare(strict_types=1);

namespace App\Libraries\Auth;

use App\Models\Auth\PenggunaModel;
use Config\Auth as AuthConfig;

/**
 * Verifikasi password berlapis / lazy rehash MD5 → Argon2id (A-02b, Mapping Migrasi Item Kritis #1 Opsi B).
 *
 * Urutan: cek `password` (Argon2id) dulu; kalau NULL, verifikasi ke `password_legacy` (MD5).
 * MD5 cocok → langsung rehash ke Argon2id, `password_legacy` dikosongkan. Login berikutnya
 * tidak menyentuh MD5 lagi. MD5 salah → gagal seperti login biasa (kena lockout).
 */
class PasswordVerifier
{
    public function __construct(
        private PenggunaModel $pengguna,
        private ?AuthConfig $config = null,
    ) {
        $this->config ??= config(AuthConfig::class);
    }

    public function hash(string $plain): string
    {
        return password_hash($plain, PASSWORD_ARGON2ID);
    }

    public function isArgon2id(?string $hash): bool
    {
        return is_string($hash) && str_starts_with($hash, '$argon2id$');
    }

    /**
     * @param array<string, mixed> $user row pengguna
     *
     * @return array{ok: bool, path: 'argon2id'|'legacy'|'none', rehashed: bool}
     */
    public function verify(array $user, string $plain): array
    {
        $hash   = $user['password'] ?? null;
        $legacy = $user['password_legacy'] ?? null;

        if (is_string($hash) && $hash !== '') {
            if (! password_verify($plain, $hash)) {
                return ['ok' => false, 'path' => 'argon2id', 'rehashed' => false];
            }

            $rehashed = false;

            if (password_needs_rehash($hash, PASSWORD_ARGON2ID)) {
                $this->storeHash($user, $this->hash($plain));
                $rehashed = true;
            }

            return ['ok' => true, 'path' => 'argon2id', 'rehashed' => $rehashed];
        }

        if (is_string($legacy) && $legacy !== '') {
            if (! hash_equals(strtolower($legacy), md5($plain))) {
                return ['ok' => false, 'path' => 'legacy', 'rehashed' => false];
            }

            // Lazy rehash: simpan Argon2id, kosongkan MD5 (MTC-003).
            $this->storeHash($user, $this->hash($plain));

            return ['ok' => true, 'path' => 'legacy', 'rehashed' => true];
        }

        return ['ok' => false, 'path' => 'none', 'rehashed' => false];
    }

    /**
     * Validasi kebijakan password baru (K4, lihat PasswordPolicy); kembalikan daftar pesan error (kosong = valid).
     *
     * @return list<string>
     */
    public function policyErrors(string $plain): array
    {
        return PasswordPolicy::fromConfig($this->config)->errors($plain);
    }

    /**
     * @param array<string, mixed> $user row pengguna
     */
    private function storeHash(array $user, string $hash): void
    {
        // Lewat Model agar audit (masked) tetap tercatat (ADR-012). Rehash hanya terjadi setelah pemilik akun
        // membuktikan password-nya sendiri, jadi actor audit = pemilik akun. Saat login AuthContext masih kosong;
        // tanpa withActor() audit rehash tercatat dengan actor NULL (T-02).
        $this->pengguna->withActor(
            (string) $user['nip'],
            fn (): bool => $this->pengguna->update((int) $user['id_pengguna'], ['password' => $hash, 'password_legacy' => null]),
        );
    }
}
