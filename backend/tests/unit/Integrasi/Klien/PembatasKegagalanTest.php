<?php

declare(strict_types=1);

namespace Tests\Unit\Integrasi\Klien;

use App\Libraries\Integrasi\Klien\PembatasKegagalan;
use CodeIgniter\Cache\CacheFactory;
use CodeIgniter\Cache\Handlers\FileHandler;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\Mock\MockCache;
use Config\Cache;

/**
 * CR-111 (temuan QA Lapis 1 Fase 6 T1) — nama item cache pembatas aman untuk nama berkas, dan throttle tetap bekerja
 * dengan handler cache berkas.
 *
 * @internal
 */
final class PembatasKegagalanTest extends CIUnitTestCase
{
    private string $folder = '';

    protected function tearDown(): void
    {
        if ($this->folder !== '' && is_dir($this->folder)) {
            foreach (glob($this->folder . '/*') ?: [] as $berkas) {
                @unlink($berkas);
            }
            @rmdir($this->folder);
        }

        parent::tearDown();
    }

    public function testNamaItemCacheHanyaKarakterAmanNamaBerkas(): void
    {
        $cache    = new MockCache();
        $pembatas = new PembatasKegagalan($cache);

        $pembatas->catatGagal('203.0.113.7|klien:qa/..\x', 60);
        $this->assertTrue($pembatas->sekali('203.0.113.7|klien:qa/..\x', 60));

        $nama = $cache->getCacheInfo();
        $this->assertCount(2, $nama);

        foreach ($nama as $n) {
            $this->assertMatchesRegularExpression('/^[a-z0-9_]+$/', (string) $n);
        }
    }

    public function testThrottleAktifDenganHandlerCacheBerkas(): void
    {
        $this->folder = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cr111_' . bin2hex(random_bytes(4));
        mkdir($this->folder);
        $config          = new Cache();
        $config->handler = 'file';
        $config->file    = ['storePath' => $this->folder . DIRECTORY_SEPARATOR, 'mode' => 0640];
        $config->prefix  = '';
        $handler         = CacheFactory::getHandler($config, 'file', 'dummy');
        $this->assertInstanceOf(FileHandler::class, $handler);
        $pembatas = new PembatasKegagalan($handler);
        $kunci    = md5('203.0.113.7');

        for ($i = 0; $i < 3; $i++) {
            $this->assertTrue($pembatas->terbuka($kunci, 3), "kegagalan ke-{$i} masih di bawah kapasitas");
            $pembatas->catatGagal($kunci, 60);
        }

        $this->assertFalse($pembatas->terbuka($kunci, 3), 'kapasitas habis → ditutup');
        $this->assertTrue($pembatas->terbuka(md5('198.51.100.1'), 3), 'kunci lain tidak terdampak');
        $this->assertTrue($pembatas->sekali($kunci, 60));
        $this->assertFalse($pembatas->sekali($kunci, 60), 'log throttle hanya sekali per jendela');
    }
}
