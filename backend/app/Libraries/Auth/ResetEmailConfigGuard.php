<?php

declare(strict_types=1);

namespace App\Libraries\Auth;

use CodeIgniter\Exceptions\ConfigException;
use Config\Email as EmailConfig;
use Config\Encryption as EncryptionConfig;

/**
 * Guard konfigurasi driver email reset password (CR-014, ISSUE-006; pola guard CR-008/CR-018).
 *
 * Dipanggil di constructor EmailResetTokenNotifier (jalur request forgot-password, SEBELUM username dicari, sehingga
 * salah konfigurasi gagal sama untuk semua username), di awal ResetPasswordEmailJob (worker), dan oleh
 * `php spark email:test`. Tidak membuka koneksi jaringan.
 *
 * Pesan ConfigException HANYA menyebut nama key (.env), tidak pernah nilainya — terutama email.SMTPPass dan
 * encryption.key.
 */
final class ResetEmailConfigGuard
{
    private const PREFIX = 'Driver email reset password (auth.resetTokenNotifier = email) tidak dapat dipakai: ';

    /**
     * Transport SMTP + kunci enkripsi isi antrean.
     *
     * @throws ConfigException
     */
    public static function assert(EmailConfig $email, EncryptionConfig $encryption, string $environment): void
    {
        self::assertTransport($email, $environment);
        self::assertEncryption($encryption);
    }

    /**
     * Aturan transport SMTP. Semua environment: protocol smtp, host, port, pengirim, pasangan user/pass, timeout,
     * metode AUTH, dan kombinasi port/crypto yang memang bisa tersambung di CI4. Production: transport wajib
     * terenkripsi dan nama pengirim wajib diisi.
     *
     * @throws ConfigException
     */
    public static function assertTransport(EmailConfig $email, string $environment): void
    {
        if ($email->protocol !== 'smtp') {
            throw self::fail("email.protocol wajib 'smtp'.");
        }

        if (trim($email->SMTPHost) === '') {
            throw self::fail('email.SMTPHost wajib diisi.');
        }

        if ($email->SMTPPort < 1 || $email->SMTPPort > 65535) {
            throw self::fail('email.SMTPPort wajib 1..65535.');
        }

        if (filter_var($email->fromEmail, FILTER_VALIDATE_EMAIL) === false) {
            throw self::fail('email.fromEmail wajib berisi alamat email yang valid.');
        }

        // CI4 hanya melakukan AUTH bila keduanya terisi; salah satu saja hampir pasti salah ketik di .env.
        if (($email->SMTPUser === '') !== ($email->SMTPPass === '')) {
            throw self::fail('email.SMTPUser dan email.SMTPPass wajib diisi keduanya (AUTH) atau dikosongkan keduanya (relay per IP).');
        }

        if (! in_array(strtolower($email->SMTPAuthMethod), ['login', 'plain'], true)) {
            throw self::fail("email.SMTPAuthMethod wajib 'login' atau 'plain'.");
        }

        if ($email->SMTPTimeout < 1 || $email->SMTPTimeout > 30) {
            throw self::fail('email.SMTPTimeout wajib 1..30 detik.');
        }

        if (! in_array($email->SMTPCrypto, ['', 'tls', 'ssl'], true)) {
            throw self::fail("email.SMTPCrypto wajib '', 'tls', atau 'ssl'.");
        }

        // CI4 Email: port 465 selalu TLS implisit (tls://); SMTPCrypto 'tls' di port itu mengirim STARTTLS lagi dan
        // koneksi gagal. Kombinasi yang benar: 587 + tls (STARTTLS) atau 465 + '' / ssl (TLS implisit).
        if ($email->SMTPPort === 465 && $email->SMTPCrypto === 'tls') {
            throw self::fail("email.SMTPPort 465 sudah TLS implisit; isi email.SMTPCrypto dengan '' (atau pakai port 587 + tls).");
        }

        if ($environment !== 'production') {
            return;
        }

        $encrypted = in_array($email->SMTPCrypto, ['tls', 'ssl'], true) || $email->SMTPPort === 465;

        if (! $encrypted) {
            throw self::fail("production wajib transport terenkripsi: email.SMTPCrypto 'tls' (587) atau email.SMTPPort 465 (TLS implisit).");
        }

        if (trim($email->fromName) === '') {
            throw self::fail('email.fromName wajib diisi di production.');
        }
    }

    /**
     * Isi antrean dienkripsi dengan CI4 Encryption (encryption.key); kunci minimal 32 byte setelah prefix
     * hex2bin:/base64: diurai (Config\Encryption sudah mengurainya saat dibangun).
     *
     * @throws ConfigException
     */
    public static function assertEncryption(EncryptionConfig $encryption): void
    {
        if (strlen($encryption->key) < 32) {
            throw self::fail('encryption.key wajib diisi (minimal 32 byte; buat dengan php spark key:generate --show).');
        }
    }

    private static function fail(string $reason): ConfigException
    {
        return new ConfigException(self::PREFIX . $reason);
    }
}
