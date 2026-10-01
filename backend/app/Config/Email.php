<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Konfigurasi email CI4 — dipakai driver email tautan reset password (CR-014, ISSUE-006; auth.resetTokenNotifier =
 * email) dan `php spark email:test`. Nilai nyata (host, akun, password SMTP, pengirim) HANYA diisi lewat .env server
 * dengan prefix `email.` (mis. email.SMTPHost) — jangan pernah di-commit. Default di bawah aman tanpa rahasia; driver
 * email menolak dipakai (ConfigException, App\Libraries\Auth\ResetEmailConfigGuard) sampai host dan pengirim diisi.
 *
 * Port & enkripsi (CI4 Email): 587 + SMTPCrypto 'tls' (STARTTLS), atau 465 + SMTPCrypto '' (TLS implisit; CI4 selalu
 * memakai tls:// di port 465 — 'tls' di port itu mengirim STARTTLS lagi dan gagal). Production wajib terenkripsi.
 * Verifikasi sertifikat server tetap aktif: MTA dengan sertifikat self-signed wajib diganti sertifikat valid, jangan
 * mematikan verifikasi.
 */
class Email extends BaseConfig
{
    public string $fromEmail  = '';
    public string $fromName   = 'SIMPEG';
    public string $recipients = '';

    /**
     * The "user agent"
     */
    public string $userAgent = 'CodeIgniter';

    /**
     * The mail sending protocol: mail, sendmail, smtp. Driver email reset password wajib 'smtp'.
     */
    public string $protocol = 'smtp';

    /**
     * The server path to Sendmail.
     */
    public string $mailPath = '/usr/sbin/sendmail';

    /**
     * SMTP Server Hostname — .env email.SMTPHost
     */
    public string $SMTPHost = '';

    /**
     * Which SMTP authentication method to use: login, plain
     */
    public string $SMTPAuthMethod = 'login';

    /**
     * SMTP Username — .env email.SMTPUser (kosong bersama SMTPPass = relay per IP tanpa AUTH)
     */
    public string $SMTPUser = '';

    /**
     * SMTP Password — HANYA .env email.SMTPPass di server; jangan di-commit, jangan ditulis ke log.
     */
    public string $SMTPPass = '';

    /**
     * SMTP Port: 587 (STARTTLS) atau 465 (TLS implisit).
     */
    public int $SMTPPort = 587;

    /**
     * SMTP Timeout (in seconds). Worker antrean yang menunggu; jalur request tidak pernah menghubungi SMTP.
     */
    public int $SMTPTimeout = 10;

    /**
     * Enable persistent SMTP connections
     */
    public bool $SMTPKeepAlive = false;

    /**
     * SMTP Encryption.
     *
     * @var string '', 'tls' or 'ssl'. 'tls' will issue a STARTTLS command
     *             to the server. 'ssl' means implicit SSL. Connection on port
     *             465 should set this to ''.
     */
    public string $SMTPCrypto = 'tls';

    /**
     * Enable word-wrap. Dimatikan: tautan reset yang panjang di bagian teks tidak boleh terpotong.
     */
    public bool $wordWrap = false;

    /**
     * Character count to wrap at
     */
    public int $wrapChars = 76;

    /**
     * Type of mail, either 'text' or 'html'
     */
    public string $mailType = 'html';

    /**
     * Character set (utf-8, iso-8859-1, etc.)
     */
    public string $charset = 'UTF-8';

    /**
     * Whether to validate the email address
     */
    public bool $validate = false;

    /**
     * Email Priority. 1 = highest. 5 = lowest. 3 = normal
     */
    public int $priority = 3;

    /**
     * Newline character. (Use “\r\n” to comply with RFC 822)
     */
    public string $CRLF = "\r\n";

    /**
     * Newline character. (Use “\r\n” to comply with RFC 822)
     */
    public string $newline = "\r\n";

    /**
     * Enable BCC Batch Mode.
     */
    public bool $BCCBatchMode = false;

    /**
     * Number of emails in each BCC batch
     */
    public int $BCCBatchSize = 200;

    /**
     * Enable notify message from server
     */
    public bool $DSN = false;
}
