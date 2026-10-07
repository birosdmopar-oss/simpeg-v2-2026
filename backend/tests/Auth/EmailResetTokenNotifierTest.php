<?php

declare(strict_types=1);

namespace Tests\Auth;

use App\Interfaces\ResetTokenNotifierInterface;
use App\Libraries\Auth\EmailResetTokenNotifier;
use App\Libraries\Auth\PasswordService;
use App\Libraries\Auth\PasswordVerifier;
use App\Libraries\Auth\ResetEmailPayload;
use App\Libraries\Auth\ResetPasswordService;
use App\Models\Auth\ForgotAttemptModel;
use App\Models\Auth\PenggunaModel;
use Closure;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Exceptions\ConfigException;
use CodeIgniter\Queue\Interfaces\QueueInterface;
use CodeIgniter\Queue\QueuePushResult;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Auth as AuthConfig;
use Config\Email as EmailConfig;
use Config\Encryption as EncryptionConfig;
use Tests\Support\AuthTestTrait;
use Tests\Support\Database\Seeds\AuthSeeder;
use Tests\Support\DatabaseTestCase;
use Tests\Support\ResetEmailTestTrait;

/**
 * CR-014 (ISSUE-006) — driver email tautan reset password, sisi request: guard konfigurasi email.* / encryption.key
 * (pesan hanya menyebut key), antrean `email` berisi data terenkripsi (tanpa token, tautan, maupun alamat email),
 * respons forgot-password identik untuk semua jenis akun, dan kegagalan antrean tidak mengubah respons.
 *
 * Tanpa jaringan: tidak ada koneksi SMTP di jalur request (driver hanya INSERT ke queue_jobs).
 *
 * @internal
 */
final class EmailResetTokenNotifierTest extends DatabaseTestCase
{
    use FeatureTestTrait;
    use AuthTestTrait;
    use ResetEmailTestTrait;

    protected $seed = AuthSeeder::class;

    private const NIP      = '199002152015022002';
    private const NIP_LAIN = '198501012010011001';
    private const UNKNOWN  = '000000000000000000';
    private const BASE     = 'https://simpeg.example.go.id/reset-password';
    private const EMAIL    = 'pegawai.uji@example.go.id';
    private const TOKEN    = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const EXPIRES  = '2026-09-30 07:30:00';

    private AuthConfig $config;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clearAuthState();

        $this->config                             = config(AuthConfig::class);
        $this->config->exposeResetTokenInResponse = false; // seperti production: token hanya lewat kanal
        $this->config->forgotMaxPerWindow         = 3;
        $this->config->resetLinkBase              = self::BASE;
        $this->config->captchaDriver              = 'mock';
        $this->config->resetTokenNotifier         = 'email';

        $this->configureEmailDriver();
    }

    protected function tearDown(): void
    {
        $this->clearAuthState();
        parent::tearDown();
    }

    private function service(?ResetTokenNotifierInterface $notifier = null, string $environment = 'testing'): ResetPasswordService
    {
        $pengguna = new PenggunaModel($this->db);
        $verifier = new PasswordVerifier($pengguna, $this->config);

        return new ResetPasswordService(
            $pengguna,
            new ForgotAttemptModel($this->db),
            $verifier,
            new PasswordService($pengguna, $verifier, service('jwt')),
            service('jwt'),
            $this->config,
            $this->db,
            notifier: $notifier,
            environment: $environment,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function user(string $nip): array
    {
        return (array) $this->db->table('pengguna')->where('nip', $nip)->get()->getRowArray();
    }

    private function queueCount(): int
    {
        return $this->db->table('queue_jobs')->where('queue', EmailResetTokenNotifier::QUEUE)->countAllResults();
    }

    /**
     * @param Closure(EmailConfig, EncryptionConfig): void $mutate
     */
    private function assertGuardRejects(Closure $mutate, string $key, string $environment = 'development'): void
    {
        $email      = $this->applyValidEmailConfig(new EmailConfig());
        $encryption = $this->encryptionConfig();
        $mutate($email, $encryption);

        try {
            new EmailResetTokenNotifier($email, $encryption, null, $environment);
            $this->fail("Konfigurasi dengan {$key} salah harus ditolak ({$environment})");
        } catch (ConfigException $e) {
            $this->assertStringContainsString($key, $e->getMessage());
            // Pesan hanya menyebut nama key, tidak pernah nilainya.
            $this->assertStringNotContainsString(self::$smtpPass, $e->getMessage());
            $this->assertStringNotContainsString(self::$phpunitEncryptionKey, $e->getMessage());
        }
    }

    public function testGuardRejectsEachMissingOrInvalidKeyWithoutLeakingValues(): void
    {
        $cases = [
            'email.protocol' => static function (EmailConfig $e): void {
                $e->protocol = 'mail';
            },
            'email.SMTPHost' => static function (EmailConfig $e): void {
                $e->SMTPHost = '  ';
            },
            'email.SMTPPort' => static function (EmailConfig $e): void {
                $e->SMTPPort = 0;
            },
            'email.fromEmail' => static function (EmailConfig $e): void {
                $e->fromEmail = 'bukan-email';
            },
            'email.SMTPTimeout' => static function (EmailConfig $e): void {
                $e->SMTPTimeout = 0;
            },
            'email.SMTPAuthMethod' => static function (EmailConfig $e): void {
                $e->SMTPAuthMethod = 'cram-md5';
            },
            'email.SMTPCrypto' => static function (EmailConfig $e): void {
                $e->SMTPCrypto = 'starttls';
            },
        ];

        foreach ($cases as $key => $mutate) {
            $this->assertGuardRejects($mutate, $key);
        }

        $this->assertGuardRejects(static function (EmailConfig $e): void {
            $e->fromEmail = '';
        }, 'email.fromEmail');
        $this->assertGuardRejects(static function (EmailConfig $e): void {
            $e->SMTPTimeout = 31;
        }, 'email.SMTPTimeout');
        $this->assertGuardRejects(static function (EmailConfig $e): void {
            $e->SMTPPort = 70000;
        }, 'email.SMTPPort');
        // Hanya user atau hanya password: hampir pasti salah ketik (CI4 diam-diam tidak melakukan AUTH).
        $this->assertGuardRejects(static function (EmailConfig $e): void {
            $e->SMTPPass = '';
        }, 'email.SMTPPass');
        $this->assertGuardRejects(static function (EmailConfig $e): void {
            $e->SMTPUser = '';
        }, 'email.SMTPUser');
        // Port 465 = TLS implisit di CI4; 'tls' di port itu mengirim STARTTLS lagi dan selalu gagal.
        $this->assertGuardRejects(static function (EmailConfig $e): void {
            $e->SMTPPort   = 465;
            $e->SMTPCrypto = 'tls';
        }, 'email.SMTPCrypto');
        $this->assertGuardRejects(static function (EmailConfig $e, EncryptionConfig $c): void {
            $c->key = '';
        }, 'encryption.key');
        $this->assertGuardRejects(static function (EmailConfig $e, EncryptionConfig $c): void {
            $c->key = 'pendek';
        }, 'encryption.key');

        // Relay per IP (tanpa AUTH) dan port uji lokal tanpa enkripsi boleh di luar production.
        $email             = $this->applyValidEmailConfig(new EmailConfig());
        $email->SMTPUser   = '';
        $email->SMTPPass   = '';
        $email->SMTPPort   = 1025;
        $email->SMTPCrypto = '';
        $this->assertInstanceOf(ResetTokenNotifierInterface::class, new EmailResetTokenNotifier($email, $this->encryptionConfig(), null, 'development'));
    }

    public function testProductionRequiresEncryptedTransportAndSenderName(): void
    {
        $this->assertGuardRejects(static function (EmailConfig $e): void {
            $e->SMTPPort   = 25;
            $e->SMTPCrypto = '';
        }, 'terenkripsi', 'production');
        $this->assertGuardRejects(static function (EmailConfig $e): void {
            $e->SMTPCrypto = '';
        }, 'terenkripsi', 'production');
        $this->assertGuardRejects(static function (EmailConfig $e): void {
            $e->SMTPPort   = 465;
            $e->SMTPCrypto = 'tls';
        }, 'email.SMTPCrypto', 'production');
        $this->assertGuardRejects(static function (EmailConfig $e): void {
            $e->fromName = ' ';
        }, 'email.fromName', 'production');

        foreach ([[587, 'tls'], [465, ''], [465, 'ssl']] as [$port, $crypto]) {
            $email             = $this->applyValidEmailConfig(new EmailConfig());
            $email->SMTPPort   = $port;
            $email->SMTPCrypto = $crypto;

            $this->assertInstanceOf(
                EmailResetTokenNotifier::class,
                new EmailResetTokenNotifier($email, $this->encryptionConfig(), null, 'production'),
                "{$port} + '{$crypto}' harus diterima di production",
            );
        }

        // Port 25 tanpa enkripsi masih boleh di development (mis. SMTP uji lokal).
        $email             = $this->applyValidEmailConfig(new EmailConfig());
        $email->SMTPPort   = 25;
        $email->SMTPCrypto = '';
        $this->assertInstanceOf(EmailResetTokenNotifier::class, new EmailResetTokenNotifier($email, $this->encryptionConfig(), null, 'development'));
    }

    public function testSendQueuesOneEncryptedJobWithoutTokenLinkOrEmailAddress(): void
    {
        $this->db->table('pengguna')->where('nip', self::NIP)->update(['email' => self::EMAIL]);
        $user = $this->user(self::NIP);
        $link = self::BASE . '#token=' . self::TOKEN;

        $notifier = new EmailResetTokenNotifier(config(EmailConfig::class), config(EncryptionConfig::class));
        $notifier->send($user, self::TOKEN, $link, self::EXPIRES);

        $rows = $this->db->table('queue_jobs')->get()->getResultArray();
        $this->assertCount(1, $rows);
        $this->assertSame(EmailResetTokenNotifier::QUEUE, $rows[0]['queue']);

        $raw = (string) $rows[0]['payload'];
        foreach ([self::TOKEN, $link, self::EMAIL, 'pegawai.uji', self::$smtpPass] as $secret) {
            $this->assertStringNotContainsString($secret, $raw, 'Isi antrean tidak boleh memuat token/tautan/alamat email');
        }

        $payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(EmailResetTokenNotifier::JOB, $payload['job']);
        $this->assertSame(['v', 'id_pengguna', 'sealed'], array_keys($payload['data']));
        $this->assertSame((int) $user['id_pengguna'], $payload['data']['id_pengguna']);

        // Hanya kunci yang sama yang bisa membuka; data yang diubah atau id luar yang ditukar ditolak.
        $this->assertSame(
            ['id_pengguna' => (int) $user['id_pengguna'], 'token' => self::TOKEN, 'link' => $link, 'expires_at' => self::EXPIRES],
            ResetEmailPayload::open(config(EncryptionConfig::class), $payload['data']),
        );
        $this->assertNull(ResetEmailPayload::open($this->encryptionConfig(str_repeat('k', 40)), $payload['data']));
        $this->assertNull(ResetEmailPayload::open(config(EncryptionConfig::class), ['id_pengguna' => (int) $user['id_pengguna'] + 1] + $payload['data']));
        $tampered           = $payload['data'];
        $tampered['sealed'] = substr($tampered['sealed'], 0, 20) . (($tampered['sealed'][20] === 'A') ? 'B' : 'A') . substr($tampered['sealed'], 21);
        $this->assertNull(ResetEmailPayload::open(config(EncryptionConfig::class), $tampered));

        $this->assertLogContains('info', EmailResetTokenNotifier::LOG_PREFIX . ' tautan reset dijadwalkan (job #');
        $this->assertNotLoggedAnywhere(self::TOKEN, 'Token reset');
    }

    /**
     * Username tak dikenal, akun nonaktif, akun aktif dengan email, dan akun aktif TANPA email: respons HTTP identik.
     * Akun aktif yang dikenal selalu di-enqueue (juga yang tanpa email — worker yang melewatinya, E4).
     */
    public function testForgotResponseIsIdenticalForEveryKindOfAccount(): void
    {
        $this->db->table('pengguna')->where('nip', self::NIP)->update(['email' => self::EMAIL]);
        $this->assertNull($this->user(self::NIP_LAIN)['email']);

        $responses = [];

        foreach ([self::NIP, self::NIP_LAIN, self::UNKNOWN, AuthSeeder::NIP_INACTIVE] as $username) {
            $result = $this->forgotPassword($username);
            $result->assertStatus(200);
            $responses[$username] = $this->json($result)['data'];
        }

        $this->assertInstanceOf(EmailResetTokenNotifier::class, service('resetTokenNotifier'));
        $this->assertCount(1, array_unique(array_map('serialize', $responses)), 'Respons forgot-password harus identik');
        $this->assertSame(['accepted', 'message'], array_keys($responses[self::NIP]));

        $this->assertSame(2, $this->queueCount(), 'Akun aktif dengan maupun tanpa email di-enqueue; lainnya tidak');
        $this->assertSame(4, $this->db->table('forgot_attempts')->countAllResults());
    }

    /**
     * Driver email yang salah konfigurasi di-resolve lewat service('resetTokenNotifier') SEBELUM username dicari:
     * gagal sama untuk username terdaftar maupun tidak, tanpa baris forgot_attempts dan tanpa job.
     */
    public function testMisconfiguredEmailDriverFailsForEveryUsernameBeforeLookup(): void
    {
        config(EmailConfig::class)->SMTPHost = '';

        $outcome = [];

        foreach ([self::NIP, self::UNKNOWN] as $username) {
            try {
                $this->service()->request($username, 'ok', null);
                $outcome[$username] = 'accepted';
            } catch (ConfigException $e) {
                $this->assertStringContainsString('email.SMTPHost', $e->getMessage());
                $this->assertStringContainsString('auth.resetTokenNotifier = email', $e->getMessage());
                $outcome[$username] = 'ConfigException';
            }
        }

        $this->assertSame([self::NIP => 'ConfigException', self::UNKNOWN => 'ConfigException'], $outcome);
        $this->assertSame(0, $this->db->table('forgot_attempts')->countAllResults());
        $this->assertSame(0, $this->queueCount());

        // Lewat endpoint: exception yang sama untuk keduanya (handler API → 500; FeatureTestTrait meneruskannya).
        foreach ([self::NIP, self::UNKNOWN] as $username) {
            try {
                $this->forgotPassword($username);
                $this->fail('forgot-password dengan driver email salah konfigurasi harus gagal');
            } catch (ConfigException $e) {
                $this->assertStringContainsString('email.SMTPHost', $e->getMessage());
            }
        }

        $this->assertSame(0, $this->db->table('forgot_attempts')->countAllResults());
    }

    public function testQueuePushFailureKeepsGenericResponseWithoutLeakingToken(): void
    {
        $captured = [];
        $failures = [
            static fn (): QueuePushResult => QueuePushResult::failure('Duplicate entry rahasia-db for key payload'),
            static fn (): QueuePushResult => throw new DatabaseException('Table t_queue_jobs rahasia-db tidak ada'),
        ];

        foreach ($failures as $i => $fail) {
            $queue = $this->createMock(QueueInterface::class);
            $queue->expects($this->once())->method('push')->willReturnCallback(
                static function (string $name, string $job, array $data) use (&$captured, $fail): QueuePushResult {
                    $captured[] = $data;

                    return $fail();
                },
            );

            $notifier = new EmailResetTokenNotifier(config(EmailConfig::class), config(EncryptionConfig::class), static fn (): QueueInterface => $queue);
            $result   = $this->service($notifier)->request(self::NIP, 'ok', null);

            $this->assertSame(['accepted' => true, 'token' => null, 'expires_at' => null], $result, "kegagalan #{$i}");
        }

        $this->assertSame(2, $this->db->table('forgot_attempts')->where('username', self::NIP)->where('token_hash IS NOT NULL')->countAllResults());
        $this->assertLogContains('error', 'gagal dikirim: Tautan reset gagal dimasukkan ke antrean email.');
        $this->assertLogContains('error', 'antrean email menolak job untuk id_pengguna=');
        $this->assertLogContains('error', DatabaseException::class);
        $this->assertNotLoggedAnywhere('rahasia-db', 'Pesan DB/antrean');

        foreach ($captured as $data) {
            $opened = ResetEmailPayload::open(config(EncryptionConfig::class), $data);
            $this->assertIsArray($opened);
            $this->assertNotLoggedAnywhere($opened['token'], 'Token reset');
        }
    }
}
