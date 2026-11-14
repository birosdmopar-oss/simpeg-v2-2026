<?php

declare(strict_types=1);

namespace App\Libraries\HaloSimpeg\Chatbot;

use Config\Chatbot;

/**
 * Token kepemilikan feedback 👍/👎 (PRD Fase 8 F8-35, K-4): pengganti daftar `id_log` di session PHP dev (A7), karena
 * `log_chatbot` anonim. Bentuk: `base64url(kedaluwarsa) . '.' . base64url(HMAC-SHA256(kunci, 'vanya-fb|v1|' + id_log + '|'
 * + id_pengguna + '|' + kedaluwarsa))`; `kedaluwarsa` = detik Unix UTC. Diverifikasi dengan `hash_equals`. Token dan kunci
 * tidak pernah dicatat. Kunci kosong/kurang dari 32 byte = tidak tersedia (balas tetap jalan, feedback 403).
 */
final class TokenFeedback
{
    private const KONTEKS = 'vanya-fb|v1|';

    private const KUNCI_MIN = 32;

    /** @var (callable(): int)|null */
    private $jam;

    public function __construct(private readonly Chatbot $config, ?callable $jam = null)
    {
        $this->jam = $jam;
    }

    public function tersedia(): bool
    {
        return strlen($this->config->kunciTokenFeedback) >= self::KUNCI_MIN;
    }

    public function buat(int $idLog, int $idPengguna): ?string
    {
        if (! $this->tersedia()) {
            return null;
        }

        $exp = $this->sekarang() + $this->config->masaTokenFeedback;

        return self::b64((string) $exp) . '.' . self::b64($this->tanda($idLog, $idPengguna, $exp));
    }

    /**
     * Sah bila terurai, tanda tangan cocok untuk log dan akun ini, dan belum kedaluwarsa.
     */
    public function sah(mixed $token, int $idLog, int $idPengguna): bool
    {
        if (! $this->tersedia() || ! is_string($token) || strlen($token) > 200) {
            return false;
        }

        $bagian = explode('.', $token);

        if (count($bagian) !== 2) {
            return false;
        }

        $exp = self::unb64($bagian[0]);
        $sig = self::unb64($bagian[1]);

        if ($exp === null || $sig === null || preg_match('/^[1-9][0-9]{0,11}\z/', $exp) !== 1) {
            return false;
        }

        if (! hash_equals($this->tanda($idLog, $idPengguna, (int) $exp), $sig)) {
            return false;
        }

        return (int) $exp >= $this->sekarang();
    }

    private function tanda(int $idLog, int $idPengguna, int $exp): string
    {
        return hash_hmac('sha256', self::KONTEKS . $idLog . '|' . $idPengguna . '|' . $exp, $this->config->kunciTokenFeedback, true);
    }

    private function sekarang(): int
    {
        return $this->jam !== null ? ($this->jam)() : time();
    }

    private static function b64(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    private static function unb64(string $s): ?string
    {
        if ($s === '' || preg_match('/^[A-Za-z0-9_-]+\z/', $s) !== 1) {
            return null;
        }

        $hasil = base64_decode(strtr($s, '-_', '+/'), true);

        return $hasil === false ? null : $hasil;
    }
}
