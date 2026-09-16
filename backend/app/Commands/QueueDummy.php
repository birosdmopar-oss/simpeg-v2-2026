<?php

declare(strict_types=1);

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * F0-13 — enqueue job dummy untuk verifikasi manual queue database:
 *   php spark queue:dummy "pesan"
 *   php spark queue:work default --max-jobs 1 --stop-when-empty
 */
class QueueDummy extends BaseCommand
{
    protected $group       = 'Queue';
    protected $name        = 'queue:dummy';
    protected $description = 'Enqueue App\Jobs\DummyJob ke queue "default" (verifikasi F0-13).';
    protected $usage       = 'queue:dummy [message]';
    protected $arguments   = ['message' => 'Pesan yang akan dibawa job (opsional)'];

    /**
     * @param list<string> $params
     */
    public function run(array $params): int
    {
        $message = $params[0] ?? 'DummyJob dari CLI ' . date('H:i:s');

        $result = service('queue')->push('default', 'dummy', ['message' => $message, 'source' => 'spark queue:dummy']);

        if (! $result->getStatus()) {
            CLI::error('Gagal enqueue: ' . ($result->getError() ?? 'unknown'));

            return EXIT_ERROR;
        }

        CLI::write(sprintf('Job dummy ter-enqueue (id=%s). Jalankan: php spark queue:work default --max-jobs 1 --stop-when-empty', (string) $result->getJobId()), 'green');

        return EXIT_SUCCESS;
    }
}
