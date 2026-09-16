<?php

declare(strict_types=1);

namespace Tests\Unit\Libraries;

use App\Libraries\CacheService;
use CodeIgniter\Cache\CacheFactory;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Cache as CacheConfig;

/**
 * F0-14 — set/get/invalidate pada driver file.
 *
 * @internal
 */
final class CacheServiceTest extends CIUnitTestCase
{
    private CacheService $cache;

    private string $storePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storePath = WRITEPATH . 'cache' . DIRECTORY_SEPARATOR . 'phpunit_' . bin2hex(random_bytes(4)) . DIRECTORY_SEPARATOR;
        mkdir($this->storePath, 0777, true);

        $config                    = new CacheConfig();
        $config->handler           = 'file';
        $config->prefix            = 'simpeg_test_';
        $config->file['storePath'] = $this->storePath;

        $this->cache = new CacheService(CacheFactory::getHandler($config), $config->prefix);
    }

    protected function tearDown(): void
    {
        $this->cache->clear();
        @rmdir($this->storePath);
        parent::tearDown();
    }

    public function testSetAndGet(): void
    {
        $this->assertNull($this->cache->get('dashboard_stat_dummy'));
        $this->assertFalse($this->cache->has('dashboard_stat_dummy'));

        $this->assertTrue($this->cache->set('dashboard_stat_dummy', ['total' => 42, 'per_unit' => ['U01' => 10]], 60));

        $this->assertTrue($this->cache->has('dashboard_stat_dummy'));
        $this->assertSame(['total' => 42, 'per_unit' => ['U01' => 10]], $this->cache->get('dashboard_stat_dummy'));
        $this->assertTrue(is_file($this->storePath . 'simpeg_test_dashboard_stat_dummy'), 'Cache harus tersimpan sebagai file');
    }

    public function testInvalidateRemovesKey(): void
    {
        $this->cache->set('dashboard_stat_dummy', 'x', 60);

        $this->assertTrue($this->cache->invalidate('dashboard_stat_dummy'));
        $this->assertNull($this->cache->get('dashboard_stat_dummy'));
        $this->assertFalse($this->cache->invalidate('dashboard_stat_dummy'), 'Invalidate key yang sudah tidak ada mengembalikan false');
    }

    public function testRememberComputesOnceThenServesFromCache(): void
    {
        $calls = 0;
        $fn    = static function () use (&$calls): int {
            $calls++;

            return 7;
        };

        $this->assertSame(7, $this->cache->remember('dashboard_remember', 60, $fn));
        $this->assertSame(7, $this->cache->remember('dashboard_remember', 60, $fn));
        $this->assertSame(1, $calls);

        $this->cache->invalidate('dashboard_remember');
        $this->assertSame(7, $this->cache->remember('dashboard_remember', 60, $fn));
        $this->assertSame(2, $calls);
    }

    public function testInvalidateMatchingPattern(): void
    {
        $this->cache->set('dashboard_a', 1, 60);
        $this->cache->set('dashboard_b', 2, 60);
        $this->cache->set('master_c', 3, 60);

        $this->assertSame(2, $this->cache->invalidateMatching('dashboard_*'));
        $this->assertNull($this->cache->get('dashboard_a'));
        $this->assertSame(3, $this->cache->get('master_c'));
    }
}
