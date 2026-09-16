<?php

declare(strict_types=1);

namespace App\Jobs;

use CodeIgniter\Queue\BaseJob;
use CodeIgniter\Queue\Interfaces\JobInterface;

/**
 * Job dummy (F0-13) untuk membuktikan queue database berjalan lewat `php spark queue:work`.
 * Menulis penanda ke log dan file writable/queue/dummy_job.log.
 */
class DummyJob extends BaseJob implements JobInterface
{
    protected int $retryAfter = 60;

    protected int $tries = 1;

    public function process(): string
    {
        $message = (string) ($this->data['message'] ?? 'DummyJob processed');
        $line    = sprintf('[%s] %s %s', date('Y-m-d H:i:s'), $message, json_encode($this->data, JSON_UNESCAPED_UNICODE));

        log_message('info', '[DummyJob] {line}', ['line' => $line]);

        $dir = WRITEPATH . 'queue';

        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        file_put_contents($dir . DIRECTORY_SEPARATOR . 'dummy_job.log', $line . PHP_EOL, FILE_APPEND);

        return $message;
    }
}
