<?php

declare(strict_types=1);

namespace App\Libraries\Auth;

use Closure;
use CodeIgniter\Email\Email;
use Config\App as AppConfig;
use Config\Auth as AuthConfig;
use Config\Email as EmailConfig;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use SensitiveParameter;
use Throwable;

/**
 * Menyusun dan mengirim email tautan reset password lewat SMTP (CI4 Email; CR-014, keputusan E8). Dipanggil worker
 * (ResetPasswordEmailJob), tidak pernah di jalur request.
 *
 * - HTML sederhana (CSS inline, tanpa gambar eksternal) + alternatif teks; semua nilai di-escape di view.
 * - Instance Email di-initialize ulang dari Config\Email yang sudah lolos ResetEmailConfigGuard, lalu SELALU
 *   di-clear(true) di finally: CI4 hanya membersihkan penerima/isi saat kirim SUKSES, sehingga instance bersama yang
 *   gagal kirim bisa membawa penerima lama ke job berikutnya.
 * - Kegagalan → RuntimeException berisi ringkasan balasan server SMTP saja (tanpa header/isi, tanpa tautan/token,
 *   alamat email diganti "[alamat]"); pesan ini ikut tersimpan di queue_jobs_failed.exception.
 * - Jangan memasang listener Events::on('email'): archive event itu memuat SMTPPass dan isi email (tautan reset).
 */
final class ResetPasswordMailer
{
    public const SUBJECT      = 'Permohonan Reset Password SIMPEG';
    public const TEST_SUBJECT = 'Uji Kirim Email SIMPEG';

    private const BULAN = [
        1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
        'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember',
    ];

    /**
     * @var Closure(): Email
     */
    private Closure $emailFactory;

    /**
     * @param (Closure(): Email)|null $emailFactory override transport (test); default service('email') — di PHPUnit
     *                                             otomatis MockEmail (CIUnitTestCase::mockEmail), tanpa jaringan
     */
    public function __construct(private EmailConfig $config, private AuthConfig $auth, ?Closure $emailFactory = null)
    {
        $this->emailFactory = $emailFactory ?? static fn (): Email => service('email');
    }

    /**
     * @param string $expiresAt batas berlaku token (Y-m-d H:i:s, zona waktu aplikasi app.appTimezone)
     *
     * @throws RuntimeException pengiriman gagal (pesan tersanitasi)
     */
    public function send(
        string $to,
        ?string $nama,
        string $username,
        #[SensitiveParameter]
        string $link,
        string $expiresAt,
    ): void {
        $data = [
            'nama'          => $nama !== null && trim($nama) !== '' ? trim($nama) : 'Bapak/Ibu',
            'username'      => $username,
            'link'          => $link,
            'ttlMenit'      => max(1, (int) ceil($this->auth->resetTokenTtl / 60)),
            'berlakuSampai' => self::formatWib($expiresAt),
        ];

        $html = view('emails/reset_password_html', $data, ['saveData' => false, 'debug' => false]);
        $text = view('emails/reset_password_text', $data, ['saveData' => false, 'debug' => false]);

        $token   = str_contains($link, '#token=') ? substr($link, (int) strpos($link, '#token=') + 7) : '';
        $secrets = array_values(array_filter([$link, $token, rawurldecode($token)], static fn (string $s): bool => $s !== ''));

        $this->deliver($to, self::SUBJECT, $html, $text, $secrets);
    }

    /**
     * Email uji tanpa token (php spark email:test) — memastikan akun SMTP, port, dan sertifikat server benar.
     *
     * @throws RuntimeException pengiriman gagal (pesan tersanitasi)
     */
    public function sendTest(string $to): void
    {
        $html = '<p>Email uji dari SIMPEG. Jika email ini sampai, konfigurasi SMTP untuk tautan reset password sudah benar.</p>'
            . '<p>Email ini tidak memuat tautan apa pun; mohon tidak membalas.</p>';
        $text = "Email uji dari SIMPEG. Jika email ini sampai, konfigurasi SMTP untuk tautan reset password sudah benar.\n\n"
            . 'Email ini tidak memuat tautan apa pun; mohon tidak membalas.';

        $this->deliver($to, self::TEST_SUBJECT, $html, $text, []);
    }

    /**
     * "30 September 2026 pukul 14.30 WIB" dari waktu zona aplikasi (nama bulan manual, tidak bergantung locale intl).
     */
    public static function formatWib(string $expiresAt): string
    {
        $app = new DateTimeImmutable($expiresAt, new DateTimeZone(config(AppConfig::class)->appTimezone));
        $wib = $app->setTimezone(new DateTimeZone('Asia/Jakarta'));

        return sprintf('%d %s %s pukul %s WIB', (int) $wib->format('j'), self::BULAN[(int) $wib->format('n')], $wib->format('Y'), $wib->format('H.i'));
    }

    /**
     * @param list<string> $secrets teks yang wajib disensor dari pesan kegagalan (tautan, token)
     */
    private function deliver(
        string $to,
        string $subject,
        #[SensitiveParameter]
        string $html,
        #[SensitiveParameter]
        string $text,
        #[SensitiveParameter]
        array $secrets,
    ): void {
        $email = ($this->emailFactory)();
        $error = null;

        try {
            $email->initialize($this->config);
            $email->setMailType('html');
            $email->setWordWrap(false); // URL panjang di bagian teks tidak boleh terpotong
            $email->setFrom($this->config->fromEmail, $this->config->fromName);
            $email->setTo($to);
            $email->setSubject($subject);
            $email->setMessage($html);
            $email->setAltMessage($text);

            if (! $email->send(false)) {
                // Dibaca sebelum clear(): clear() ikut menghapus pesan debug server SMTP.
                $error = self::summary($email->printDebugger([]), $secrets);
            }
        } catch (Throwable $e) {
            $error = $e::class . ': ' . self::summary($e->getMessage(), $secrets);
        } finally {
            $email->clear(true);
        }

        if ($error !== null) {
            throw new RuntimeException('Pengiriman email reset gagal: ' . $error);
        }
    }

    /**
     * Ringkasan balasan server SMTP untuk pesan exception: tanpa tag HTML, tanpa tautan/token, alamat email diganti
     * "[alamat]", maksimal 300 karakter.
     *
     * @param list<string> $secrets
     */
    private static function summary(string $debug, #[SensitiveParameter] array $secrets): string
    {
        $plain = html_entity_decode(strip_tags(str_replace('<br>', ' ', $debug)), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        foreach ($secrets as $secret) {
            $plain = str_replace($secret, '[disensor]', $plain);
        }

        $plain = (string) preg_replace('/[^\s<>()\[\]"\',;:]+@[^\s<>()\[\]"\',;:]+/u', '[alamat]', $plain);
        $plain = trim((string) preg_replace('/\s+/u', ' ', $plain));

        return $plain === '' ? 'tanpa keterangan dari server SMTP' : mb_strimwidth($plain, 0, 300, '...', 'UTF-8');
    }
}
