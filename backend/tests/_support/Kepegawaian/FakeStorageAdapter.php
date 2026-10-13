<?php

declare(strict_types=1);

namespace Tests\Support\Kepegawaian;

use App\Interfaces\Kepegawaian\StorageAdapterInterface;
use RuntimeException;

/**
 * Fake StorageAdapter di memori untuk test (S0-A MAKE-002) — TIDAK pernah dipakai kode produksi.
 */
final class FakeStorageAdapter implements StorageAdapterInterface
{
    /**
     * @var array<string, string> path => isi
     */
    public array $berkas = [];

    public function simpan(string $path, string $isi): void
    {
        $this->berkas[$path] = $isi;
    }

    public function hapus(string $path): void
    {
        unset($this->berkas[$path]);
    }

    public function ada(string $path): bool
    {
        return isset($this->berkas[$path]);
    }

    public function baca(string $path): string
    {
        if (! isset($this->berkas[$path])) {
            throw new RuntimeException("Berkas {$path} tidak ada.");
        }

        return $this->berkas[$path];
    }
}
