<?php

declare(strict_types=1);

namespace Tests\Support\Libraries;

use App\Libraries\CacheService;
use Closure;
use CodeIgniter\Test\Mock\MockCache;

/**
 * CacheService spy untuk unit test: remember() hanya mencatat kunci yang dipakai dan mengembalikan [] tanpa menjalankan
 * callback (tanpa query DB). Dipakai MasterOptionsCacheKeyTest (CR-009).
 */
class RecordingCacheService extends CacheService
{
    /**
     * @var list<string>
     */
    public array $keys = [];

    public function __construct()
    {
        parent::__construct(new MockCache(), '');
    }

    public function remember(string $key, int $ttl, Closure $callback): mixed
    {
        $this->keys[] = $key;

        return [];
    }

    public function lastKey(): ?string
    {
        return $this->keys === [] ? null : $this->keys[array_key_last($this->keys)];
    }
}
