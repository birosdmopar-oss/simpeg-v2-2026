<?php

declare(strict_types=1);

namespace Config;

use App\Jobs\DummyJob;
use App\Jobs\ResetPasswordEmailJob;
use CodeIgniter\Queue\Config\Queue as BaseQueue;
use CodeIgniter\Queue\Handlers\DatabaseHandler;

/**
 * CI4 native Queue, driver database (ADR-013). Redis sengaja tidak dipakai.
 * Konsumen utama: push notification (Fase 4/7/8). Fase 0 hanya menyediakan job dummy.
 *
 * Queue `email` (CR-014, ISSUE-006): job `reset-password-email` (tautan reset password, isi terenkripsi) dari
 * EmailResetTokenNotifier. Worker: `php spark queue:work email --stop-when-empty --max-time 55` lewat cron tiap menit
 * (README-deploy.md §1a). Tanpa worker, email reset tidak pernah terkirim.
 */
class Queue extends BaseQueue
{
    public string $defaultHandler = 'database';

    /**
     * @var array<string, class-string<\CodeIgniter\Queue\Interfaces\QueueInterface>>
     */
    public array $handlers = [
        'database' => DatabaseHandler::class,
    ];

    public array $database = [
        'dbGroup'    => 'default',
        'getShared'  => true,
        'skipLocked' => true,
    ];

    public bool $keepFailedJobs = true;

    /**
     * @var array<string, class-string<\CodeIgniter\Queue\Interfaces\JobInterface>>
     */
    public array $jobHandlers = [
        'dummy'                => DummyJob::class,
        'reset-password-email' => ResetPasswordEmailJob::class,
    ];
}
