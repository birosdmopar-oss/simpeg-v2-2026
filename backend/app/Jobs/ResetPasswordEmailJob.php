<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Libraries\Auth\EmailResetTokenNotifier;
use App\Libraries\Auth\ResetEmailConfigGuard;
use App\Libraries\Auth\ResetEmailPayload;
use App\Libraries\Auth\ResetPasswordMailer;
use App\Models\Auth\ForgotAttemptModel;
use App\Models\Auth\PenggunaModel;
use CodeIgniter\Exceptions\ConfigException;
use CodeIgniter\I18n\Time;
use CodeIgniter\Queue\BaseJob;
use CodeIgniter\Queue\Interfaces\JobInterface;
use Config\Auth as AuthConfig;
use Config\Email as EmailConfig;
use Config\Encryption as EncryptionConfig;
use SensitiveParameter;
use Throwable;

/**
 * Job `reset-password-email` di queue `email` (CR-014, ISSUE-006): kirim tautan reset password lewat SMTP.
 * Dijalankan worker `php spark queue:work email` (cron, README-deploy.md §1a); dimasukkan EmailResetTokenNotifier.
 *
 * Sebelum mengirim, SETIAP percobaan memeriksa ulang (E3): isi job bisa dibuka (encryption.key sama, tidak diubah),
 * token masih ada di forgot_attempts, belum dipakai/dibatalkan, belum kedaluwarsa, username baris token = username akun
 * sekarang (rename membatalkan token, CR-013), akun belum dihapus dan aktif, dan email akun valid. Syarat yang gagal →
 * tidak kirim dan TIDAK dicoba ulang (job selesai), dicatat di log tanpa token. Akun tanpa email valid → log warning
 * untuk admin (E4); respons forgot-password tetap sama dengan akun lain.
 *
 * Hanya kegagalan konfigurasi (guard) dan kegagalan SMTP yang dilempar: worker mencoba ulang tiap $retryAfter detik
 * sampai $tries percobaan (masih di dalam TTL token 30 menit), lalu job pindah ke queue_jobs_failed (isi tetap
 * terenkripsi, exception tersanitasi). `php spark queue:retry` aman karena pemeriksaan di atas diulang.
 */
class ResetPasswordEmailJob extends BaseJob implements JobInterface
{
    public const TRIES = 5;

    public const RESULT_SENT    = 'terkirim';
    public const RESULT_SKIPPED = 'dilewati';

    protected int $retryAfter = 60;

    protected int $tries = self::TRIES;

    public function process(): string
    {
        $encryption = config(EncryptionConfig::class);
        $emailCfg   = config(EmailConfig::class);

        $prefix = EmailResetTokenNotifier::LOG_PREFIX;

        try {
            ResetEmailConfigGuard::assert($emailCfg, $encryption, ENVIRONMENT);
        } catch (ConfigException $e) {
            // Worker salah konfigurasi: job dicoba ulang lalu masuk queue_jobs_failed; pesan hanya menyebut nama key.
            log_message('error', $prefix . ' worker tidak dapat mengirim: {msg}', ['msg' => $e->getMessage()]);

            throw $e;
        }

        $outerId = is_int($this->data['id_pengguna'] ?? null) ? $this->data['id_pengguna'] : 0;
        $payload = ResetEmailPayload::open($encryption, $this->data);

        if ($payload === null) {
            log_message('error', $prefix . ' isi job untuk id_pengguna={id} tidak dapat dibuka (encryption.key berbeda atau data rusak); tautan tidak dikirim.', [
                'id' => $outerId,
            ]);

            return self::RESULT_SKIPPED;
        }

        $id       = $payload['id_pengguna'];
        $pengguna = new PenggunaModel();
        $user     = $pengguna->find($id);

        if (! is_array($user) || (string) $user['status'] !== PenggunaModel::STATUS_ACTIVE) {
            return $this->skip($id, 'akun tidak ditemukan, sudah dihapus, atau nonaktif');
        }

        $reason = $this->tokenProblem($pengguna, $payload['token'], $id);

        if ($reason !== null) {
            return $this->skip($id, $reason);
        }

        $to = trim((string) $user['email']); // kolom NULL-able (akun tanpa email) → ''

        if ($to === '' || filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            log_message('warning', $prefix . ' akun id_pengguna={id} username={username} tidak memiliki email valid; tautan reset tidak dikirim.', [
                'id'       => $id,
                'username' => (string) $user['username'],
            ]);

            return self::RESULT_SKIPPED;
        }

        $mailer = new ResetPasswordMailer($emailCfg, config(AuthConfig::class));

        try {
            $mailer->send($to, (string) $user['name'], (string) $user['username'], $payload['link'], $payload['expires_at']);
        } catch (Throwable $e) {
            log_message('error', $prefix . ' tautan reset untuk id_pengguna={id} gagal dikirim (dicoba ulang sampai {tries} percobaan): {msg}', [
                'id'    => $id,
                'tries' => $this->getTries(),
                'msg'   => $e->getMessage(),
            ]);

            throw $e;
        }

        log_message('info', $prefix . ' tautan reset terkirim untuk id_pengguna={id}, username={username}, ke {to}', [
            'id'       => $id,
            'username' => (string) $user['username'],
            'to'       => self::maskEmail($to),
        ]);

        return self::RESULT_SENT;
    }

    /**
     * "budi@example.go.id" → "b***@example.go.id".
     */
    public static function maskEmail(string $email): string
    {
        $at = strrpos($email, '@');

        if ($at === false || $at === 0) {
            return '***';
        }

        return mb_substr($email, 0, 1) . '***' . substr($email, $at);
    }

    /**
     * Alasan token tidak boleh dikirim, atau null bila masih berlaku untuk akun ini. Pemilik token ditentukan sama
     * dengan ResetPasswordService::reset(): akun yang ditemukan lewat username baris token (collation kolom), sehingga
     * token yang terbit sebelum username diganti tidak terkirim ke akun lain.
     */
    private function tokenProblem(PenggunaModel $pengguna, #[SensitiveParameter] string $token, int $idPengguna): ?string
    {
        $row = (new ForgotAttemptModel())->findByTokenHash(hash('sha256', $token));

        if ($row === null) {
            return 'token tidak ditemukan';
        }

        if ($row['used_at'] !== null) {
            return 'token sudah dipakai atau dibatalkan';
        }

        if ($row['expires_at'] === null || strtotime((string) $row['expires_at']) <= Time::now()->getTimestamp()) {
            return 'token sudah kedaluwarsa';
        }

        $owner = $pengguna->findByUsername((string) $row['username']);

        if ($owner === null || (int) $owner['id_pengguna'] !== $idPengguna) {
            return 'username akun sudah berubah';
        }

        return null;
    }

    private function skip(int $id, string $reason): string
    {
        log_message('info', EmailResetTokenNotifier::LOG_PREFIX . ' tautan reset untuk id_pengguna={id} tidak dikirim: {reason}.', [
            'id'     => $id,
            'reason' => $reason,
        ]);

        return self::RESULT_SKIPPED;
    }
}
