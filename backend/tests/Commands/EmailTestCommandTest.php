<?php

declare(strict_types=1);

namespace Tests\Commands;

use App\Libraries\Auth\ResetPasswordMailer;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\StreamFilterTrait;
use Config\Email as EmailConfig;
use Tests\Support\ResetEmailTestTrait;

/**
 * CR-014 — `php spark email:test <alamat>`: smoke test SMTP dari server (runbook README-deploy.md §1a). Guard transport
 * dijalankan dulu; email uji tanpa token; keluaran tidak pernah memuat email.SMTPPass. Tanpa jaringan (MockEmail).
 *
 * @internal
 */
final class EmailTestCommandTest extends CIUnitTestCase
{
    use StreamFilterTrait;
    use ResetEmailTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureEmailDriver();
        $this->resetStreamFilterBuffer();
    }

    public function testSendsTestEmailWithoutTokenOrSecrets(): void
    {
        $mock = $this->mockEmailService();

        command('email:test uji@example.go.id');
        $output = $this->getStreamFilterBuffer();

        $this->assertStringContainsString('Email uji terkirim ke uji@example.go.id lewat smtp.example.invalid:587', $output);
        $this->assertStringNotContainsString(self::$smtpPass, $output);

        $this->assertSame(['uji@example.go.id'], $mock->archive['recipients']);
        $this->assertSame(ResetPasswordMailer::TEST_SUBJECT, $mock->archive['subject']);
        $this->assertStringNotContainsString('token', $mock->archive['body']);
        $this->assertStringNotContainsString('token', $mock->archive['altMessage']);
    }

    public function testRejectsInvalidRecipientAndMisconfiguredTransport(): void
    {
        $mock = $this->mockEmailService();

        command('email:test bukan-email');
        $this->assertStringContainsString('Alamat email tujuan tidak valid', $this->getStreamFilterBuffer());

        $this->resetStreamFilterBuffer();
        config(EmailConfig::class)->SMTPHost = '';
        command('email:test uji@example.go.id');
        $output = $this->getStreamFilterBuffer();

        $this->assertStringContainsString('email.SMTPHost wajib diisi', $output);
        $this->assertStringNotContainsString(self::$smtpPass, $output);
        $this->assertNull($mock->archive);
    }

    public function testSmtpFailureIsReportedWithoutSecrets(): void
    {
        $mock              = $this->mockEmailService();
        $mock->returnValue = false;

        command('email:test uji@example.go.id');
        $output = $this->getStreamFilterBuffer();

        $this->assertStringContainsString('Pengiriman email reset gagal', $output);
        $this->assertStringNotContainsString(self::$smtpPass, $output);
    }
}
