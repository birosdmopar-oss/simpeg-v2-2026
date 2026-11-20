<?php

declare(strict_types=1);

namespace Tests\Unit\Libraries;

use App\Libraries\CacheService;
use CodeIgniter\Cache\CacheFactory;
use CodeIgniter\Cache\Handlers\ApcuHandler;
use CodeIgniter\Cache\Handlers\DummyHandler;
use CodeIgniter\Cache\Handlers\FileHandler;
use CodeIgniter\Cache\Handlers\MemcachedHandler;
use CodeIgniter\Cache\Handlers\PredisHandler;
use CodeIgniter\Cache\Handlers\RedisHandler;
use CodeIgniter\Cache\Handlers\WincacheHandler;
use CodeIgniter\Exceptions\BadMethodCallException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\TestLogger;
use Config\Cache as CacheConfig;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;

/**
 * CR-169 — `CacheService::invalidateMatching()` per handler, tanpa server Redis/Memcached:
 * - prefiks hanya sekali (Redis memberi prefiks sendiri; File/APCu/Predis dari CacheService);
 * - handler yang melempar (Memcached/Wincache/galat lain) → 0 + log critical tanpa pola/key, tidak dilempar ulang.
 *
 * @internal
 */
final class CacheServiceHandlerTest extends CIUnitTestCase
{
    private const PREFIX = 'simpeg_test_';

    public function testRedisPolaBerprefiksSekaliDanMenghapusKeyYangCocok(): void
    {
        $redis = new class () {
            /** @var list<string> */
            public array $pola = [];

            /** @var array<string, int> */
            public array $isi = ['simpeg_test_dashboard_a' => 1, 'simpeg_test_dashboard_b' => 2, 'simpeg_test_master_c' => 3];

            /**
             * Tiruan `Redis::scan()` satu putaran (iterator → 0).
             *
             * @param-out int $iterator
             *
             * @return list<string>
             */
            public function scan(?int &$iterator, string $pattern): array
            {
                $this->pola[] = $pattern;
                $iterator     = 0;

                return array_values(array_filter(array_keys($this->isi), static fn (string $k): bool => fnmatch($pattern, $k)));
            }

            /**
             * @param list<string> $keys
             */
            public function del(array $keys): int
            {
                $n = 0;

                foreach ($keys as $k) {
                    if (isset($this->isi[$k])) {
                        unset($this->isi[$k]);
                        $n++;
                    }
                }

                return $n;
            }
        };

        // Subkelas kosong: deleteMatching() vendor yang diuji, tanpa initialize()/koneksi.
        $handler = new class ($this->config()) extends RedisHandler {};
        (new ReflectionProperty(RedisHandler::class, 'redis'))->setValue($handler, $redis);

        $this->assertSame(2, (new CacheService($handler, self::PREFIX))->invalidateMatching('dashboard_*'));
        $this->assertSame(['simpeg_test_dashboard_*'], $redis->pola, 'Prefiks hanya sekali (dulu simpeg_test_simpeg_test_…)');
        $this->assertSame(['simpeg_test_master_c' => 3], $redis->isi);
    }

    public function testPredisApcuFileMenerimaPolaBerprefiksDariCacheService(): void
    {
        foreach ([PredisHandler::class, ApcuHandler::class, FileHandler::class] as $kelas) {
            $handler = $this->perekam($kelas);

            $this->assertSame(5, (new CacheService($handler, self::PREFIX))->invalidateMatching('master_opt_*'), $kelas);
            $this->assertSame(['simpeg_test_master_opt_*'], (new ReflectionProperty($handler, 'pola'))->getValue($handler), $kelas);
        }
    }

    public function testDummySelaluNol(): void
    {
        $config          = $this->config();
        $config->handler = 'dummy';

        $this->assertInstanceOf(DummyHandler::class, $handler = CacheFactory::getHandler($config));
        $this->assertSame(0, (new CacheService($handler, self::PREFIX))->invalidateMatching('dashboard_*'));
    }

    public function testMemcachedDanWincacheTidakMelemparDanDicatatCritical(): void
    {
        foreach ([MemcachedHandler::class, WincacheHandler::class] as $kelas) {
            // Tanpa initialize(): deleteMatching() kedua handler melempar BadMethodCallException tanpa koneksi.
            $this->assertSame(0, (new CacheService(new $kelas($this->config()), self::PREFIX))->invalidateMatching('dashboard_stat_*'), $kelas);
            // Berkas:baris asal = baris throw di handler vendor (nomor baris tidak dikunci supaya tidak rapuh terhadap update CI4).
            $berkas = (string) (new ReflectionMethod($kelas, 'deleteMatching'))->getFileName();
            $this->assertTrue(TestLogger::didLog('critical', 'Cache: invalidasi pola gagal di handler ' . $kelas . ' (' . BadMethodCallException::class . ' di ' . $berkas . ':', false), $kelas);
        }
    }

    public function testGalatLainDariHandlerMenjadiNolTanpaKeyDiLog(): void
    {
        $handler = new class (new CacheConfig()) extends FileHandler {
            public function deleteMatching(string $pattern): int
            {
                throw new RuntimeException('gagal membaca ' . $pattern);
            }
        };
        $barisThrow = __LINE__ - 3; // baris `throw` di atas

        $this->assertSame(0, (new CacheService($handler, self::PREFIX))->invalidateMatching('rahasia_*'));
        $this->assertLogged('critical', 'Cache: invalidasi pola gagal di handler ' . $handler::class . ' (RuntimeException di ' . __FILE__ . ':' . $barisThrow . '); data cache basi sampai TTL.');
        $this->assertFalse(TestLogger::didLog('critical', 'rahasia_', false), 'Pola/key tidak boleh masuk log');
    }

    private function config(): CacheConfig
    {
        $config         = new CacheConfig();
        $config->prefix = self::PREFIX;

        return $config;
    }

    /**
     * Handler kelas `$kelas` yang `deleteMatching()`-nya hanya merekam pola dan menjawab 5 (tanpa ekstensi/server).
     *
     * @param class-string<ApcuHandler|FileHandler|PredisHandler> $kelas
     */
    private function perekam(string $kelas): ApcuHandler|FileHandler|PredisHandler
    {
        $config = $this->config();

        return match ($kelas) {
            PredisHandler::class => new class ($config) extends PredisHandler {
                /** @var list<string> */
                public array $pola = [];

                public function deleteMatching(string $pattern): int
                {
                    $this->pola[] = $pattern;

                    return 5;
                }
            },
            ApcuHandler::class => new class ($config) extends ApcuHandler {
                /** @var list<string> */
                public array $pola = [];

                public function deleteMatching(string $pattern): int
                {
                    $this->pola[] = $pattern;

                    return 5;
                }
            },
            default => new class ($config) extends FileHandler {
                /** @var list<string> */
                public array $pola = [];

                public function deleteMatching(string $pattern): int
                {
                    $this->pola[] = $pattern;

                    return 5;
                }
            },
        };
    }
}
