<?php

declare(strict_types=1);

namespace App\Interfaces\Kepegawaian;

/**
 * Penyimpanan berkas lampiran (kontrak beku S0-A MAKE-002; implementasi nyata `LocalStorageAdapter` milik WS-2).
 * $path relatif terhadap akar penyimpanan adapter; adapter menolak path yang keluar dari akar.
 */
interface StorageAdapterInterface
{
    public function simpan(string $path, string $isi): void;

    /**
     * Hapus berkas; tidak error bila berkas sudah tidak ada.
     */
    public function hapus(string $path): void;

    public function ada(string $path): bool;

    /**
     * @throws \RuntimeException bila berkas tidak ada
     */
    public function baca(string $path): string;
}
