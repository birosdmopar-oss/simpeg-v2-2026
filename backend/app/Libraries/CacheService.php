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
     * Prefiks key (CR-169) ditambahkan di sini HANYA untuk handler yang tidak memberinya sendiri pada `deleteMatching()`:
     * - Diverifikasi di vendor CI4 4.7.4:
     *   - `RedisHandler` sudah memberi prefiks (`validateKey($pattern, $this->prefix)`), jadi pola diteruskan apa adanya.
     *     Dulu prefiks ganda (`<prefix><prefix>…`) membuat invalidasi di Redis tidak menghapus apa pun.
     *   - `FileHandler` (glob nama berkas = key berprefiks) dan `ApcuHandler` (`fnmatch` atas key tersimpan berprefiks) butuh
     *     prefiks dari sini.
     *   - `DummyHandler` selalu 0.
     * - `PredisHandler` juga diberi prefiks dari sini, BERDASARKAN SUMBER predis/predis v2.x (paket tidak terpasang di vendor,
     *   jadi bukan hasil verifikasi vendor): prefiks klien Predis tidak diterapkan ke MATCH `SCAN`, tetapi diterapkan ke `DEL`
     *   atas key hasil `SCAN` yang sudah berprefiks, sehingga dengan prefiks tidak kosong `deleteMatching()` Predis tidak
     *   menghapus apa pun (keterbatasan handler). Bergantung versi Predis: bila versi yang dipasang memberi prefiks pada MATCH,
     *   perlakuan prefiks untuk Predis di sini harus ditinjau ulang.
     *
     * Handler tanpa `deleteMatching()` (Memcached, Wincache: `BadMethodCallException`) atau galat lain dari handler TIDAK
     * dilempar: pemanggil biasanya sudah meng-commit tulis DB, dan tulis yang tersimpan tidak boleh berakhir 500. Galat dicatat
     * `critical` (kelas handler, kelas exception, berkas:baris asal; TANPA pesan exception karena bisa memuat key/pola) dan
     * hasilnya 0; akibatnya data cache yang seharusnya dihapus tetap basi sampai TTL-nya habis.
     */
    public function invalidateMatching(string $pattern): int
    {
        $pola = $this->cache instanceof RedisHandler ? $pattern : $this->prefix . $pattern;

        try {
            return $this->cache->deleteMatching($pola);
        } catch (Throwable $e) {
            log_message('critical', 'Cache: invalidasi pola gagal di handler {handler} ({kelas} di {berkas}:{baris}); data cache basi sampai TTL.', [
                'handler' => $this->cache::class,
                'kelas'   => $e::class,
                'berkas'  => $e->getFile(),
                'baris'   => $e->getLine(),
            ]);

            return 0;
        }
    }

    public function clear(): bool
    {
        return $this->cache->clean();
    }
}
