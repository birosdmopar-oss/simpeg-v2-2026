<?php

declare(strict_types=1);

namespace Tests\Support\Paritas\Kepegawaian;

use Closure;
use CodeIgniter\Cache\CacheInterface;
use CodeIgniter\Test\Mock\MockCache;

/**
 * Cache uji (CR-164): pembungkus MockCache yang mencatat setiap `save()` (key + TTL) dan setiap pola
 * `deleteMatching()`, agar TTL dan invalidasi bisa diperiksa tanpa driver file.
 */
final class CacheTtlSpy implements CacheInterface
{
    /** @var list<array{key: string, ttl: int}> */
    public array $simpan = [];

    /** @var list<string> */
    public array $polaHapus = [];

    private function __construct(private readonly MockCache $inti)
    {
    }

    /** Spy baru di atas MockCache kosong (handler tiruan bawaan CI4). */
    public static function baru(): self
    {
        return new self(new MockCache());
    }

    public function initialize(): void
    {
        $this->inti->initialize();
    }

    public function get(string $key): mixed
    {
        return $this->inti->get($key);
    }

    public function save(string $key, mixed $value, int $ttl = 60): bool
    {
        $this->simpan[] = ['key' => $key, 'ttl' => $ttl];

        return $this->inti->save($key, $value, $ttl);
    }

    public function remember(string $key, int $ttl, Closure $callback): mixed
    {
        $value = $this->get($key);

        if ($value !== null) {
            return $value;
        }

        $value = $callback();
        $this->save($key, $value, $ttl);

        return $value;
    }

    public function delete(string $key): bool
    {
        return $this->inti->delete($key);
    }

    public function deleteMatching(string $pattern): int
    {
        $this->polaHapus[] = $pattern;

        return $this->inti->deleteMatching($pattern);
    }

    public function increment(string $key, int $offset = 1): bool
    {
        return $this->inti->increment($key, $offset);
    }

    public function decrement(string $key, int $offset = 1): bool
    {
        return $this->inti->decrement($key, $offset);
    }

    public function clean(): bool
    {
        return $this->inti->clean();
    }

    /**
     * @return array<array-key, mixed>
     */
    public function getCacheInfo(): array
    {
        return $this->inti->getCacheInfo();
    }

    public function getMetaData(string $key): ?array
    {
        return $this->inti->getMetaData($key);
    }

    public function isSupported(): bool
    {
        return true;
    }
}
