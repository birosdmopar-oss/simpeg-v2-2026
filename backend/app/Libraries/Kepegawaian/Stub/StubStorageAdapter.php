<?php

declare(strict_types=1);

namespace App\Libraries\Kepegawaian\Stub;

use App\Interfaces\Kepegawaian\StorageAdapterInterface;

/**
 * Stub fail-closed S0-A (MAKE-002) sampai `LocalStorageAdapter` WS-2 (MAKE-009): setiap pemanggilan → 501, tidak ada
 * berkas yang ditulis/dibaca.
 */
final class StubStorageAdapter implements StorageAdapterInterface
{
    private const FITUR = 'penyimpanan lampiran';

    public function simpan(string $path, string $isi): void
    {
        throw new BelumTersediaException(self::FITUR);
    }

    public function hapus(string $path): void
    {
        throw new BelumTersediaException(self::FITUR);
    }

    public function ada(string $path): bool
    {
        throw new BelumTersediaException(self::FITUR);
    }

    public function baca(string $path): string
    {
        throw new BelumTersediaException(self::FITUR);
    }
}
