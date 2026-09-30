<?php

declare(strict_types=1);

namespace App\Commands;

use App\Libraries\Auth\ResetEmailConfigGuard;
use App\Libraries\Auth\ResetPasswordMailer;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Auth as AuthConfig;
use Config\Email as EmailConfig;
use Throwable;

/**
 * CR-014 — uji kirim SMTP dari server (smoke test runbook README-deploy.md §1a):
 *   php spark email:test alamat@instansi.go.id
 *
 * Menjalankan guard transport email lebih dulu (pesan hanya menyebut nama key .env), lalu mengirim email uji TANPA
 * token/tautan lewat konfigurasi yang sama dengan worker reset password. Yang dicetak hanya hasil dan ringkasan
 * balasan server SMTP — tidak pernah header, isi email, atau email.SMTPPass.
 */
class EmailTest extends BaseCommand
{
    protected $group       = 'Email';
    protected $name        = 'email:test';
    protected $description = 'Kirim email uji (tanpa token) lewat konfigurasi SMTP email.* untuk memastikan driver email reset password siap.';
    protected $usage       = 'email:test <alamat>';
    protected $arguments   = ['alamat' => 'Alamat email tujuan uji'];

    /**
     * @param list<string> $params
     */
    public function run(array $params): int
    {
        $to = trim($params[0] ?? '');

        if ($to === '' || filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            CLI::error('Alamat email tujuan tidak valid. Pakai: php spark email:test <alamat>');

            return EXIT_USER_INPUT;
        }

        $config = config(EmailConfig::class);

        try {
            ResetEmailConfigGuard::assertTransport($config, ENVIRONMENT);
            (new ResetPasswordMailer($config, config(AuthConfig::class)))->sendTest($to);
        } catch (Throwable $e) {
            CLI::error($e->getMessage());

            return EXIT_ERROR;
        }

        CLI::write(sprintf('Email uji terkirim ke %s lewat %s:%d. Periksa kotak masuk (dan folder spam).', $to, $config->SMTPHost, $config->SMTPPort), 'green');

        return EXIT_SUCCESS;
    }
}
