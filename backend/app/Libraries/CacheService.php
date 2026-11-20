<?php

declare(strict_types=1);

namespace App\Libraries;

use Closure;
use CodeIgniter\Cache\CacheInterface;
use CodeIgniter\Cache\Handlers\RedisHandler;
use Config\Cache as CacheConfig;
use Throwable;

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
     * @param string|null $prefix prefix key (default: Config\Cache::$prefix) — ditambahkan pada deleteMatching() hanya untuk
     *                            handler yang tidak memberinya sendiri (lihat invalidateMatching()).
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
     * Hapus semua key yang cocok pola glob, mis. 'dashboard_*'. Mengembalikan jumlah key yang terhapus.
     *
     * Prefiks key (CR-169, diverifikasi di CI4 4.7.4) ditambahkan di sini HANYA untuk handler yang tidak memberinya sendiri
     * pada `deleteMatching()`:
     * - `RedisHandler` sudah memberi prefiks (`validateKey($pattern, $this->prefix)`), jadi pola diteruskan apa adanya.
     *   Dulu prefiks ganda (`<prefix><prefix>…`) membuat invalidasi di Redis tidak menghapus apa pun.
     * - `FileHandler` (glob nama berkas = key berprefiks), `ApcuHandler` (`fnmatch` atas key tersimpan berprefiks), dan
     *   `PredisHandler` (prefiks klien Predis tidak diterapkan ke MATCH `SCAN`) butuh prefiks dari sini.
     *   Catatan Predis: prefiks klien diterapkan ke `DEL` atas key hasil `SCAN` yang sudah berprefiks, sehingga dengan prefiks
     *   tidak kosong `deleteMatching()` Predis tidak menghapus apa pun (keterbatasan handler; tidak bisa diperbaiki di sini).
     * - `DummyHandler` selalu 0.
     *
     * Handler tanpa `deleteMatching()` (Memcached, Wincache: `BadMethodCallException`) atau galat lain dari handler TIDAK
     * dilempar: pemanggil biasanya sudah meng-commit tulis DB, dan tulis yang tersimpan tidak boleh berakhir 500. Galat dicatat
     * `critical` (kelas handler + kelas exception saja, tanpa pola/key) dan hasilnya 0; akibatnya data cache yang seharusnya
     * dihapus tetap basi sampai TTL-nya habis.
     */
    public function invalidateMatching(string $pattern): int
    {
        $pola = $this->cache instanceof RedisHandler ? $pattern : $this->prefix . $pattern;

        try {
            return $this->cache->deleteMatching($pola);
        } catch (Throwable $e) {
            log_message('critical', 'Cache: invalidasi pola gagal di handler {handler} ({kelas}); data cache basi sampai TTL.', [
                'handler' => $this->cache::class,
                'kelas'   => $e::class,
            ]);

            return 0;
        }
    }

    public function clear(): bool
    {
        return $this->cache->clean();
    }
}
