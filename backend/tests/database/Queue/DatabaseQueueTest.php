<?php

declare(strict_types=1);

namespace Tests\Database\Queue;

use App\Jobs\DummyJob;
use CodeIgniter\Queue\Entities\QueueJob;
use CodeIgniter\Queue\Enums\Status;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Queue as QueueConfig;

/**
 * F0-13 — job dummy ter-enqueue ke tabel queue_jobs (driver database) dan berhasil diproses.
 * Pemrosesan di sini meniru loop `php spark queue:work` (pop -> process -> done);
 * verifikasi end-to-end lewat CLI ada di README (queue:work --max-jobs).
 *
 * @internal
 */
final class DatabaseQueueTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = null;

    private string $marker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->marker = WRITEPATH . 'queue' . DIRECTORY_SEPARATOR . 'dummy_job.log';
        @unlink($this->marker);
    }

    protected function tearDown(): void
    {
        @unlink($this->marker);
        parent::tearDown();
    }

    public function testConfigUsesDatabaseDriverAndRegistersDummyJob(): void
    {
        $config = config(QueueConfig::class);

        $this->assertSame('database', $config->defaultHandler);
        $this->assertSame(DummyJob::class, $config->resolveJobClass('dummy'));
    }

    public function testDummyJobIsEnqueuedAndProcessed(): void
    {
        $queue = service('queue', false);

        $this->assertTrue($queue->push('default', 'dummy', ['message' => 'halo dari phpunit', 'n' => 1])->getStatus());
        $this->seeInDatabase('queue_jobs', ['queue' => 'default', 'status' => Status::PENDING->value]);

        // --- mirror `queue:work` loop ---
        $job = $queue->pop('default', ['default']);
        $this->assertInstanceOf(QueueJob::class, $job);
        $this->assertSame('dummy', $job->payload['job']);

        $class = config(QueueConfig::class)->resolveJobClass($job->payload['job']);
        $this->assertSame(DummyJob::class, $class);

        $result = (new $class($job->payload['data']))->process();
        $this->assertSame('halo dari phpunit', $result);

        $this->assertTrue($queue->done($job));
        $this->assertSame(0, $this->db->table('queue_jobs')->countAllResults(), 'Job selesai harus dihapus dari queue_jobs');
        $this->assertSame(0, $this->db->table('queue_jobs_failed')->countAllResults());

        $this->assertFileExists($this->marker);
        $this->assertStringContainsString('halo dari phpunit', (string) file_get_contents($this->marker));
        $this->assertLogContains('info', '[DummyJob]');
    }

    public function testPopReturnsNullWhenQueueEmpty(): void
    {
        $this->assertNull(service('queue', false)->pop('default', ['default']));
    }
}
