<?php

declare(strict_types=1);

namespace Tests\Auth;

use App\Jobs\ResetPasswordEmailJob;
use App\Libraries\Auth\EmailResetTokenNotifier;
use App\Libraries\Auth\ResetEmailPayload;
use App\Libraries\Auth\ResetPasswordMailer;
use CodeIgniter\Events\Events;
use CodeIgniter\Exceptions\ConfigException;
use CodeIgniter\I18n\Time;
use CodeIgniter\Queue\Entities\QueueJob;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\Mock\MockEmail;
use Config\Auth as AuthConfig;
use Config\Email as EmailConfig;
use Config\Encryption as EncryptionConfig;
use Config\Queue as QueueConfig;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\Support\AuthTestTrait;
use Tests\Support\Database\Seeds\AuthSeeder;
use Tests\Support\DatabaseTestCase;
use Tests\Support\ResetEmailTestTrait;
use Throwable;

/**
 * CR-014 (ISSUE-006) — sisi worker driver email: job `reset-password-email` memeriksa ulang token/akun sebelum kirim,
 * isi email (penerima, subjek, tautan utuh, waktu WIB, escape), retry sampai gagal permanen tanpa token di
 * queue_jobs_failed, tanpa penerima terbawa antar-job, dan tanpa token di log.
 *
 * Pemrosesan meniru loop `php spark queue:work` (pop → process → done/later/failed), pola DatabaseQueueTest. SMTP
 * digantikan MockEmail (CIUnitTestCase::mockEmail) — host smtp.example.invalid tidak pernah dihubungi.
 *
 * @internal
 */
final class ResetPasswordEmailJobTest extends DatabaseTestCase
{
    use FeatureTestTrait;
    use AuthTestTrait;
    use ResetEmailTestTrait;

    protected $seed = AuthSeeder::class;

    private const NIP      = '199002152015022002';
    private const NIP_LAIN = '198501012010011001';
    private const BASE     = 'https://simpeg.example.go.id/reset-password';
    private const EMAIL    = 'budi.santoso@example.go.id';
    private const NEW      = 'PasswordReset789';

    protected function setUp(): void
    {
        parent::setUp();
        $this->clearAuthState();

        $config                             = config(AuthConfig::class);
        $config->exposeResetTokenInResponse = false;
        $config->forgotMaxPerWindow         = 3;
        $config->resetLinkBase              = self::BASE;
        $config->captchaDriver              = 'mock';
        $config->resetTokenNotifier         = 'email';

        $this->configureEmailDriver();
    }

    protected function tearDown(): void
    {
        Time::setTestNow();
        $this->clearAuthState();
        parent::tearDown();
    }

    private function setAkun(string $nip, array $data): void
    {
        $this->db->table('pengguna')->where('nip', $nip)->update($data);
    }

    /**
     * forgot-password lewat HTTP lalu ambil job yang dihasilkan.
     */
    private function forgotAndPop(string $nip): QueueJob
    {
        $this->forgotPassword($nip)->assertStatus(200);

        return $this->popEmailJob();
    }

    public function testEndToEndEmailCarriesFullLinkThatResetsPassword(): void
    {
        $this->setAkun(self::NIP, ['email' => self::EMAIL, 'name' => 'Budi Santoso']);
        $mock = $this->mockEmailService();

        $job    = $this->forgotAndPop(self::NIP);
        $opened = $this->openJob($job);

        $this->assertSame(ResetPasswordEmailJob::RESULT_SENT, $this->jobFor($job)->process());
        $this->assertTrue(service('queue')->done($job));
        $this->assertSame(0, $this->db->table('queue_jobs')->countAllResults());

        $archive = $mock->archive;
        $this->assertIsArray($archive);
        $this->assertSame([self::EMAIL], $archive['recipients']);
        $this->assertSame(ResetPasswordMailer::SUBJECT, $archive['subject']);
        $this->assertSame('Permohonan Reset Password SIMPEG', $archive['subject']);
        $this->assertSame('simpeg@example.go.id', $archive['fromEmail']);
        $this->assertSame('html', $archive['mailType']);
        $this->assertFalse($archive['wordWrap']);

        // Tautan UTUH (fragment #token=64 hex) ada di HTML dan teks; hash-nya = token_hash forgot_attempts.
        $this->assertMatchesRegularExpression('/^' . preg_quote(self::BASE, '/') . '#token=[0-9a-f]{64}$/', $opened['link']);
        $this->assertStringContainsString('href="' . $opened['link'] . '"', $archive['body']);
        $this->assertStringContainsString($opened['link'] . "\n", $archive['altMessage']);
        $row = (array) $this->db->table('forgot_attempts')->where('username', self::NIP)->get()->getRowArray();
        $this->assertSame(hash('sha256', $opened['token']), $row['token_hash']);

        foreach ([$archive['body'], $archive['altMessage']] as $part) {
            $this->assertStringContainsString('Yth. Budi Santoso,', $part);
            $this->assertStringContainsString(self::NIP, $part);
            $this->assertStringContainsString('berlaku 30 menit sejak permintaan, sampai ' . ResetPasswordMailer::formatWib((string) $row['expires_at']), $part);
            $this->assertStringContainsString('hanya dapat dipakai satu kali', $part);
            $this->assertStringContainsString('abaikan email ini', $part);
            $this->assertStringContainsString('Jangan teruskan email ini', $part);
            $this->assertStringNotContainsString('DEBUG-VIEW', $part);
        }

        $this->assertStringNotContainsString('<img', $archive['body'], 'Tanpa gambar/aset eksternal');

        // Token dari email benar-benar bisa dipakai reset.
        $this->withBodyFormat('json')->post('api/v1/auth/reset-password', [
            'token' => $opened['token'], 'new_password' => self::NEW, 'new_password_confirmation' => self::NEW,
        ])->assertStatus(200);

        $this->assertLogContains('info', EmailResetTokenNotifier::LOG_PREFIX . ' tautan reset terkirim untuk id_pengguna=');
        $this->assertLogContains('info', 'ke b***@example.go.id');
        $this->assertNotLoggedAnywhere($opened['token'], 'Token reset');
        $this->assertNotLoggedAnywhere(self::EMAIL, 'Alamat email utuh');

        // Archive event 'email' memuat SMTPPass dan isi email: tidak boleh ada listener.
        $this->assertSame([], Events::listeners('email'));
    }

    public function testExpiryIsShownInWibWithIndonesianMonthName(): void
    {
        $this->assertSame('30 September 2026 pukul 14.30 WIB', ResetPasswordMailer::formatWib('2026-09-30 07:30:00'));
        $this->assertSame('1 Januari 2027 pukul 03.00 WIB', ResetPasswordMailer::formatWib('2026-12-31 20:00:00'));

        $mock   = $this->mockEmailService();
        $mailer = new ResetPasswordMailer(config(EmailConfig::class), config(AuthConfig::class), static fn (): MockEmail => $mock);
        $link   = self::BASE . '#token=' . str_repeat('ab', 32);
        $mailer->send('pegawai@example.go.id', null, 'pegawai01', $link, '2026-09-30 07:30:00');

        $this->assertStringContainsString('sampai 30 September 2026 pukul 14.30 WIB', $mock->archive['body']);
        $this->assertStringContainsString('sampai 30 September 2026 pukul 14.30 WIB', $mock->archive['altMessage']);
        // Nama kosong → sapaan umum.
        $this->assertStringContainsString('Yth. Bapak/Ibu,', $mock->archive['body']);
    }

    public function testNameIsEscapedInHtml(): void
    {
        $this->setAkun(self::NIP, ['email' => self::EMAIL, 'name' => '<script>alert(1)</script> "Budi"']);
        $mock = $this->mockEmailService();

        $this->jobFor($this->forgotAndPop(self::NIP))->process();

        $this->assertStringNotContainsString('<script>', $mock->archive['body']);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt; &quot;Budi&quot;', $mock->archive['body']);
    }

    /**
     * Token sudah dipakai, kedaluwarsa, akun nonaktif/terhapus, atau username sudah diganti → tidak kirim, tanpa
     * exception (job selesai, tidak dicoba ulang).
     */
    #[Group('db-isolasi-penuh')]
    public function testSkipsWithoutSendingWhenTokenOrAccountNoLongerValid(): void
    {
        $this->setAkun(self::NIP, ['email' => self::EMAIL]);
        $mock = $this->mockEmailService();

        $scenarios = [
            'token sudah dipakai atau dibatalkan' => function (array $opened): void {
                $this->withBodyFormat('json')->post('api/v1/auth/reset-password', [
                    'token' => $opened['token'], 'new_password' => self::NEW, 'new_password_confirmation' => self::NEW,
                ])->assertStatus(200);
            },
            'token sudah kedaluwarsa' => static function (): void {
                Time::setTestNow(Time::now()->addSeconds(config(AuthConfig::class)->resetTokenTtl + 1));
            },
            'akun tidak ditemukan, sudah dihapus, atau nonaktif' => function (): void {
                $this->setAkun(self::NIP, ['status' => '0']);
            },
            'username akun sudah berubah' => function (): void {
                $this->setAkun(self::NIP, ['username' => 'nama-baru-uji']);
            },
        ];

        foreach ($scenarios as $reason => $arrange) {
            $job = $this->forgotAndPop(self::NIP);
            $arrange($this->openJob($job));

            $this->assertSame(ResetPasswordEmailJob::RESULT_SKIPPED, $this->jobFor($job)->process(), $reason);
            $this->assertNull($mock->archive, "Tidak boleh ada email terkirim: {$reason}");
            $this->assertLogContains('info', 'tidak dikirim: ' . $reason . '.');
            service('queue')->done($job);

            // Pulihkan keadaan untuk skenario berikutnya.
            Time::setTestNow();
            $this->setAkun(self::NIP, ['status' => '1']);
            $this->db->table('pengguna')->where('nip', self::NIP)->update(['username' => self::NIP]);
            $this->db->table('forgot_attempts')->truncate();
        }

        // Akun terhapus (soft delete).
        $job = $this->forgotAndPop(self::NIP);
        $this->setAkun(self::NIP, ['deleted_at' => date('Y-m-d H:i:s')]);
        $this->assertSame(ResetPasswordEmailJob::RESULT_SKIPPED, $this->jobFor($job)->process());
        $this->assertNull($mock->archive);
    }

    #[Group('db-isolasi-penuh')]
    public function testAccountWithoutValidEmailIsSkippedWithWarning(): void
    {
        $mock = $this->mockEmailService();

        foreach ([null, '', 'bukan-email'] as $email) {
            $this->setAkun(self::NIP, ['email' => $email]);
            $this->db->table('forgot_attempts')->truncate();

            $this->assertSame(ResetPasswordEmailJob::RESULT_SKIPPED, $this->jobFor($this->forgotAndPop(self::NIP))->process());
        }

        $this->assertNull($mock->archive);
        $this->assertLogContains('warning', 'username=' . self::NIP . ' tidak memiliki email valid; tautan reset tidak dikirim.');
    }

    public function testUnreadableJobDataIsSkippedWithError(): void
    {
        $this->setAkun(self::NIP, ['email' => self::EMAIL]);
        $mock = $this->mockEmailService();
        $data = $this->forgotAndPop(self::NIP)->payload['data'];

        $tampered           = $data;
        $tampered['sealed'] = strrev($data['sealed']);
        $swapped            = ['id_pengguna' => $data['id_pengguna'] + 1] + $data;

        foreach ([$tampered, $swapped, ['v' => 2] + $data, []] as $bad) {
            $this->assertSame(ResetPasswordEmailJob::RESULT_SKIPPED, (new ResetPasswordEmailJob($bad))->process());
        }

        // Kunci berbeda (rotasi tanpa previousKeys).
        config(EncryptionConfig::class)->key = str_repeat('x', 48);
        $this->assertSame(ResetPasswordEmailJob::RESULT_SKIPPED, (new ResetPasswordEmailJob($data))->process());

        $this->assertNull($mock->archive);
        $this->assertLogContains('error', 'tidak dapat dibuka (encryption.key berbeda atau data rusak)');
    }

    /**
     * Worker salah konfigurasi (mis. .env worker tanpa encryption.key) → ConfigException (job dicoba ulang lalu
     * masuk queue_jobs_failed), dicatat di log tanpa nilai rahasia, tanpa kirim.
     */
    public function testMisconfiguredWorkerThrowsAndLogsKeyOnly(): void
    {
        $this->setAkun(self::NIP, ['email' => self::EMAIL]);
        $mock = $this->mockEmailService();
        $job  = $this->jobFor($this->forgotAndPop(self::NIP));

        config(EncryptionConfig::class)->key = '';

        try {
            $job->process();
            $this->fail('Worker tanpa encryption.key harus gagal');
        } catch (ConfigException $e) {
            $this->assertStringContainsString('encryption.key', $e->getMessage());
        }

        $this->assertNull($mock->archive);
        $this->assertLogContains('error', 'worker tidak dapat mengirim: ');
        $this->assertNotLoggedAnywhere(self::$smtpPass, 'Password SMTP');
    }

    /**
     * SMTP gagal → exception tersanitasi; worker mencoba ulang sampai $tries lalu job pindah ke queue_jobs_failed
     * tanpa token/tautan/alamat/SMTPPass.
     */
    public function testSmtpFailureRetriesThenFailsWithoutLeakingSecrets(): void
    {
        $this->setAkun(self::NIP, ['email' => self::EMAIL]);
        $mock              = $this->mockEmailService();
        $mock->returnValue = false;

        $this->forgotPassword(self::NIP)->assertStatus(200);
        $stored = json_decode((string) $this->db->table('queue_jobs')->get()->getRowArray()['payload'], true, 512, JSON_THROW_ON_ERROR);
        $opened = ResetEmailPayload::open(config(EncryptionConfig::class), $stored['data']);
        $this->assertIsArray($opened);
        $queue    = service('queue');
        $config   = config(QueueConfig::class);
        $messages = [];

        // --- mirror `queue:work email` (QueueWork::handleWork) ---
        for ($i = 0; $i < 10; $i++) {
            $work = $queue->pop(EmailResetTokenNotifier::QUEUE, ['default']);

            if ($work === null) {
                break;
            }

            $class = $config->resolveJobClass($work->payload['job']);
            $job   = new $class($work->payload['data']);

            try {
                $job->process();
                $queue->done($work);
            } catch (Throwable $e) {
                $messages[] = $e->getMessage();
                $this->assertInstanceOf(RuntimeException::class, $e);

                if (++$work->attempts < $job->getTries()) {
                    $queue->later($work, 0);
                } else {
                    $queue->failed($work, $e, $config->keepFailedJobs);
                }
            }
        }

        $this->assertCount(ResetPasswordEmailJob::TRIES, $messages);
        $this->assertSame(0, $this->db->table('queue_jobs')->countAllResults());
        $failed = $this->db->table('queue_jobs_failed')->get()->getResultArray();
        $this->assertCount(1, $failed);

        $secrets = [$opened['token'], $opened['link'], self::EMAIL, self::$smtpPass];

        foreach (array_merge($messages, [(string) $failed[0]['payload'], (string) $failed[0]['exception']]) as $text) {
            foreach ($secrets as $secret) {
                $this->assertStringNotContainsString($secret, $text);
            }
        }

        $this->assertStringStartsWith('Pengiriman email reset gagal: ', $messages[0]);
        $this->assertStringContainsString('Pengiriman email reset gagal', (string) $failed[0]['exception']);
        $this->assertLogContains('error', 'gagal dikirim (dicoba ulang sampai 5 percobaan)');
        $this->assertNotLoggedAnywhere($opened['token'], 'Token reset');
        $this->assertNull($mock->archive);

        // Instance email bersama dibersihkan walau kirim gagal (CI4 hanya clear() saat sukses).
        $this->assertSame([], $this->getPrivateProperty($mock, 'recipients'));
        $this->assertSame('', $this->getPrivateProperty($mock, 'body'));
    }

    /**
     * Job A gagal kirim, job B sesudahnya hanya dikirim ke penerima B (tanpa penerima A terbawa).
     */
    public function testFailedSendDoesNotCarryRecipientsIntoNextJob(): void
    {
        $this->setAkun(self::NIP, ['email' => self::EMAIL]);
        $this->setAkun(self::NIP_LAIN, ['email' => 'admin.uji@example.go.id']);
        $mock = $this->mockEmailService();

        $jobA = $this->forgotAndPop(self::NIP);
        $jobB = $this->forgotAndPop(self::NIP_LAIN);

        $mock->returnValue = false;

        try {
            $this->jobFor($jobA)->process();
            $this->fail('Kirim gagal harus melempar exception');
        } catch (RuntimeException $e) {
            $this->assertStringStartsWith('Pengiriman email reset gagal: ', $e->getMessage());
        }

        $mock->returnValue = true;
        $this->assertSame(ResetPasswordEmailJob::RESULT_SENT, $this->jobFor($jobB)->process());
        $this->assertSame(['admin.uji@example.go.id'], $mock->archive['recipients']);
        $this->assertStringNotContainsString(self::EMAIL, (string) $mock->archive['headers']['To']);
    }
}
