<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Jobs\ResetPasswordEmailJob;
use App\Libraries\Auth\EmailResetTokenNotifier;
use App\Libraries\Auth\ResetEmailPayload;
use CodeIgniter\Queue\Entities\QueueJob;
use CodeIgniter\Test\Mock\MockEmail;
use CodeIgniter\Test\TestLogger;
use Config\Email as EmailConfig;
use Config\Encryption as EncryptionConfig;
use Config\Queue as QueueConfig;

/**
 * Helper test driver email reset password (CR-014). Tanpa jaringan: host SMTP `smtp.example.invalid` tidak pernah
 * dihubungi — service('email') di PHPUnit adalah MockEmail (CIUnitTestCase::mockEmail).
 */
trait ResetEmailTestTrait
{
    /** Kunci enkripsi KHUSUS PHPUnit (pola jwt.secret di phpunit.dist.xml) — jangan dipakai di server mana pun. */
    protected static string $phpunitEncryptionKey = 'phpunit-only-encryption-key-do-not-use-0123456789abcdef';

    /** Password SMTP palsu: tidak boleh muncul di pesan error, log, atau antrean. */
    protected static string $smtpPass = 'rahasia-uji-xyz';

    /**
     * Isi $config dengan konfigurasi SMTP valid (tanpa jaringan).
     */
    protected function applyValidEmailConfig(EmailConfig $config): EmailConfig
    {
        $config->protocol       = 'smtp';
        $config->SMTPHost       = 'smtp.example.invalid';
        $config->SMTPPort       = 587;
        $config->SMTPCrypto     = 'tls';
        $config->SMTPUser       = 'simpeg-uji';
        $config->SMTPPass       = self::$smtpPass;
        $config->SMTPAuthMethod = 'login';
        $config->SMTPTimeout    = 5;
        $config->fromEmail      = 'simpeg@example.go.id';
        $config->fromName       = 'SIMPEG';

        return $config;
    }

    /**
     * Konfigurasi bersama (config()) untuk driver email: SMTP valid + encryption.key khusus test.
     */
    protected function configureEmailDriver(): void
    {
        $this->applyValidEmailConfig(config(EmailConfig::class));
        config(EncryptionConfig::class)->key = self::$phpunitEncryptionKey;
    }

    protected function encryptionConfig(?string $key = null): EncryptionConfig
    {
        $config      = new EncryptionConfig();
        $config->key = $key ?? self::$phpunitEncryptionKey;

        return $config;
    }

    protected function mockEmailService(): MockEmail
    {
        $email = service('email');
        $this->assertInstanceOf(MockEmail::class, $email, 'Test email wajib memakai MockEmail (tanpa jaringan)');

        return $email;
    }

    /**
     * Ambil job reset-password-email berikutnya dari queue `email` (meniru loop queue:work).
     */
    protected function popEmailJob(): QueueJob
    {
        $job = service('queue')->pop(EmailResetTokenNotifier::QUEUE, ['default']);
        $this->assertInstanceOf(QueueJob::class, $job, 'Job reset-password-email tidak ada di antrean');
        $this->assertSame(EmailResetTokenNotifier::JOB, $job->payload['job']);

        return $job;
    }

    /**
     * @return array{id_pengguna: int, token: string, link: string, expires_at: string}
     */
    protected function openJob(QueueJob $job): array
    {
        $opened = ResetEmailPayload::open(config(EncryptionConfig::class), $job->payload['data']);
        $this->assertIsArray($opened);

        return $opened;
    }

    protected function jobFor(QueueJob $job): ResetPasswordEmailJob
    {
        $class = config(QueueConfig::class)->resolveJobClass($job->payload['job']);
        $this->assertSame(ResetPasswordEmailJob::class, $class);

        return new ResetPasswordEmailJob($job->payload['data']);
    }

    /**
     * Teks $needle tidak tertulis di log level apa pun.
     */
    protected function assertNotLoggedAnywhere(string $needle, string $label): void
    {
        foreach (['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'] as $level) {
            $this->assertFalse(TestLogger::didLog($level, $needle, false), "{$label} tidak boleh tertulis di log {$level}");
        }
    }
}
