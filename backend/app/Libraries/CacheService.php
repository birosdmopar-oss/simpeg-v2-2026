<?php

declare(strict_types=1);

namespace App\Libraries;

use Closure;
use CodeIgniter\Cache\CacheInterface;
use Config\Cache as CacheConfig;

/**
 * CacheService (F0-14) — ADR-014.
 * Cache selektif di level Service (bukan HTTP) untuk data agregat/non-personal: statistik dashboard, master data.
 * Driver file (Config\Cache::$handler = 'file'). Invalidasi dipanggil dari hook opt-in Model Events (modul terkait).
 */
class CacheService
{
    public const DEFAULT_TTL = 3600;

    private string $prefix;

    /**
     * @param string|null $prefix prefix key (default: Config\Cache::$prefix) — handler file tidak
     *                            menambahkan prefix pada deleteMatching(), jadi ditambahkan di sini.
     */
    public function __construct(private CacheInterface $cache, ?string $prefix = null)
    {
        $this->prefix = $prefix ?? config(CacheConfig::class)->prefix;
    }

    public function get(string $key): mixed
    {
        return $this->cache->get($key);
    }

    public function has(string $key): bool
    {
        return $this->cache->get($key) !== null;
    }

    public function set(string $key, mixed $value, int $ttl = self::DEFAULT_TTL): bool
    {
        return $this->cache->save($key, $value, $ttl);
    }

    /**
     * Ambil dari cache; kalau kosong, hitung lewat $callback lalu simpan.
     */
    public function remember(string $key, int $ttl, Closure $callback): mixed
    {
        $value = $this->cache->get($key);

        if ($value !== null) {
            return $value;
        }

        $value = $callback();
        $this->cache->save($key, $value, $ttl);

        return $value;
    }

    public function invalidate(string $key): bool
    {
        return $this->cache->delete($key);
    }

    /**
     * Hapus semua key yang cocok pola glob, mis. 'dashboard_*'.
     */
    public function invalidateMatching(string $pattern): int
    {
        return $this->cache->deleteMatching($this->prefix . $pattern);
    }

    public function clear(): bool
    {
        return $this->cache->clean();
    }
}
