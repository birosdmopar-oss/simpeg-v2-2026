<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Konfigurasi Modul A — Autentikasi & Akun (Fase 1).
 * Nilai bisa di-override lewat .env dengan prefix `auth.` (mis. auth.lockoutMaxAttempts).
 *
 * Catatan keputusan yang masih [TBD] di dokumen sumber (FSD/SRS/RTM Open Issues):
 * - lockoutMaxAttempts = 5 diambil dari MTC-001 ("password salah 5x berturut-turut, percobaan ke-6 ditolak").
 * - lockoutDurationMinutes dan resetTokenTtl tidak disebut di sumber mana pun — default di bawah adalah
 *   asumsi kerja yang perlu dikonfirmasi Horii/Tech Lead.
 */
class Auth extends BaseConfig
{
    // ------------------------------------------------------------------
    // A-04 Login attempts lockout
    // ------------------------------------------------------------------

    /**
     * Jumlah kegagalan login berturut-turut (tanpa sukses di antaranya) sebelum akun terkunci.
     */
    public int $lockoutMaxAttempts = 5;

    /**
     * Jendela waktu (menit) kegagalan dihitung "berturut-turut".
     */
    public int $lockoutWindowMinutes = 15;

    /**
     * Lama akun terkunci (menit) sejak kegagalan terakhir. [TBD] belum ditentukan di sumber.
     */
    public int $lockoutDurationMinutes = 15;

    // ------------------------------------------------------------------
    // A-02 / A-06 Password
    // ------------------------------------------------------------------

    /**
     * Panjang minimal password baru (K4, ikut legacy). Aturan lengkap ada di App\Libraries\Auth\PasswordPolicy dan
     * dicerminkan frontend (PASSWORD_RULES) — kalau nilai ini diubah, ubah juga PASSWORD_MIN_LENGTH di frontend.
     */
    public int $passwordMinLength = 8;

    // ------------------------------------------------------------------
    // A-07 Forgot / reset password
    // ------------------------------------------------------------------

    /**
     * Masa berlaku token reset (detik). [TBD] belum ditentukan di sumber — default 30 menit.
     */
    public int $resetTokenTtl = 1800;

    /**
     * Rate limit permintaan lupa password per username dalam satu jendela.
     */
    public int $forgotMaxPerWindow = 3;

    public int $forgotWindowMinutes = 60;

    /**
     * Kalau true (HANYA development), token ikut dikembalikan di response forgot-password agar alur bisa diuji
     * end-to-end tanpa kanal pengiriman. WAJIB false di production.
     */
    public bool $exposeResetTokenInResponse = false;

    /**
     * Driver pengiriman tautan reset (App\Interfaces\ResetTokenNotifierInterface, dipilih di Config\Services):
     * - 'log'  : development — tautan reset (berisi token) ditulis ke log lokal. DITOLAK di production.
     * - 'mock' : test — tautan disimpan di memori untuk di-assert. DITOLAK di production.
     * Kanal final = email (K3, keputusan user 25-09-2026); driver 'email' menyusul setelah akun SMTP tersedia.
     * Selama driver email belum ada, production menolak permintaan lupa password (ConfigException → 500) dan
     * frontend menyembunyikan halamannya (VITE_PASSWORD_RESET_ENABLED=false → "Hubungi Admin").
     */
    public string $resetTokenNotifier = 'log';

    /**
     * URL absolut halaman reset password di FRONTEND (backend tidak tahu URL frontend). Tautan yang dikirim:
     * {resetLinkBase}?token=<token>. WAJIB diisi URL production (https) lewat .env auth.resetLinkBase.
     */
    public string $resetLinkBase = 'http://localhost:5173/reset-password';

    // ------------------------------------------------------------------
    // A-03 Captcha Cloudflare Turnstile
    // ------------------------------------------------------------------

    /**
     * 'turnstile' = verifikasi ke Cloudflare (butuh secret key); 'mock' = untuk lokal/test, tanpa network.
     */
    public string $captchaDriver = 'turnstile';

    /**
     * Secret key Turnstile — WAJIB dari .env (auth.turnstileSecretKey), jangan hardcode.
     */
    public string $turnstileSecretKey = '';

    public string $turnstileVerifyUrl = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    public int $turnstileTimeout = 5;
}
