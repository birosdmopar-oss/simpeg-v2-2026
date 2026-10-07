<?php

declare(strict_types=1);

namespace App\Libraries\Kepegawaian\Stub;

use App\Exceptions\BelumTersediaException;
use App\Interfaces\Kepegawaian\RiwayatServiceInterface;
use App\Libraries\Auth\AuthContext;

/**
 * Stub fail-closed S0-A (MAKE-002) sampai RiwayatEngine WS-1 M1 (MAKE-004): setiap pemanggilan → 501.
 */
final class StubRiwayatService implements RiwayatServiceInterface
{
    private const FITUR = 'riwayat pegawai';

    public function daftar(AuthContext $auth, string $nip, string $jenis): array
    {
        throw new BelumTersediaException(self::FITUR);
    }

    public function detail(AuthContext $auth, string $nip, string $jenis, int $id): array
    {
        throw new BelumTersediaException(self::FITUR);
    }

    public function tambah(AuthContext $auth, string $nip, string $jenis, array $data, array $berkas = []): array
    {
        throw new BelumTersediaException(self::FITUR);
    }

    public function ubah(AuthContext $auth, string $nip, string $jenis, int $id, array $data, array $berkas = []): array
    {
        throw new BelumTersediaException(self::FITUR);
    }

    public function hapus(AuthContext $auth, string $nip, string $jenis, int $id): void
    {
        throw new BelumTersediaException(self::FITUR);
    }

    public function proses(AuthContext $auth, string $nip, string $jenis, int $id, string $aksi, ?string $reasonNote): array
    {
        throw new BelumTersediaException(self::FITUR);
    }
}
