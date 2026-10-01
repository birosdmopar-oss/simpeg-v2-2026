<?php

declare(strict_types=1);

namespace App\Libraries\Auth;

use App\Interfaces\ResetTokenNotifierInterface;
use Closure;
use CodeIgniter\Exceptions\ConfigException;
use CodeIgniter\Queue\Interfaces\QueueInterface;
use Config\Email as EmailConfig;
use Config\Encryption as EncryptionConfig;
use RuntimeException;
use SensitiveParameter;
use Throwable;

/**
 * Driver email tautan reset password (auth.resetTokenNotifier = email; CR-014, ISSUE-006, K3) — kanal production.
 *
 * send() TIDAK menghubungi server SMTP: hanya memasukkan job `reset-password-email` ke queue `email` (ADR-013, INSERT
 * ~1 ms). Pengiriman SMTP dikerjakan worker `php spark queue:work email` (App\Jobs\ResetPasswordEmailJob), sehingga
 * waktu respons forgot-password tidak membedakan username terdaftar (handshake SMTP 1–10 detik) dan kegagalan SMTP
 * tidak pernah mengubah respons yang generik.
 *
 * Isi job terenkripsi (ResetEmailPayload): token/tautan tidak tersimpan polos di queue_jobs(_failed). Constructor
 * menjalankan ResetEmailConfigGuard (tanpa jaringan): salah konfigurasi → ConfigException saat driver di-resolve,
 * sebelum username dicari (gagal sama untuk semua username, tanpa baris forgot_attempts).
 */
class EmailResetTokenNotifier implements ResetTokenNotifierInterface
{
    public const QUEUE      = 'email';
    public const JOB        = 'reset-password-email';
    public const LOG_PREFIX = '[ResetPassword][email]';

    /**
     * @var Closure(): QueueInterface
     */
    private Closure $queueFactory;

    /**
     * @param (Closure(): QueueInterface)|null $queueFactory override antrean (test); default service('queue')
     *
     * @throws ConfigException
     */
    public function __construct(
        private EmailConfig $email,
        private EncryptionConfig $encryption,
        ?Closure $queueFactory = null,
        string $environment = ENVIRONMENT,
    ) {
        ResetEmailConfigGuard::assert($email, $encryption, $environment);

        $this->queueFactory = $queueFactory ?? static fn (): QueueInterface => service('queue');
    }

    public function send(
        array $user,
        #[SensitiveParameter]
        string $token,
        #[SensitiveParameter]
        string $resetLink,
        string $expiresAt,
    ): void {
        $idPengguna = (int) ($user['id_pengguna'] ?? 0);
        $data       = ResetEmailPayload::seal($this->encryption, $idPengguna, $token, $resetLink, $expiresAt);

        try {
            $result = ($this->queueFactory)()->push(self::QUEUE, self::JOB, $data);
            $error  = $result->getStatus() ? null : 'push ditolak';
        } catch (Throwable $e) {
            $result = null;
            $error  = $e::class;
        }

        if ($result === null || $error !== null) {
            // Pesan DB/antrean tidak diteruskan (bisa memuat detail skema); cukup jenis kegagalannya.
            log_message('error', self::LOG_PREFIX . ' antrean email menolak job untuk id_pengguna={id}: {err}', [
                'id'  => $idPengguna,
                'err' => (string) $error,
            ]);

            throw new RuntimeException('Tautan reset gagal dimasukkan ke antrean email.');
        }

        log_message('info', self::LOG_PREFIX . ' tautan reset dijadwalkan (job #{job}) untuk id_pengguna={id}', [
            'job' => (string) $result->getJobId(),
            'id'  => $idPengguna,
        ]);
    }
}
