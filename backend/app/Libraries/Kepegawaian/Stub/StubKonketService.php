<?php

declare(strict_types=1);

namespace App\Libraries\Kepegawaian\Stub;

use App\Exceptions\BelumTersediaException;
use App\Interfaces\Kepegawaian\KonketServiceInterface;
use App\Libraries\Auth\AuthContext;
use CodeIgniter\HTTP\Files\UploadedFile;

/**
 * Stub S0-A (MAKE-002); implementasi nyata WS-2 MAKE-013 (KonketService). Fail-closed (501).
 */
final class StubKonketService implements KonketServiceInterface
{
    public function daftar(AuthContext $auth, array $filter = []): array
    {
        throw new BelumTersediaException('konket');
    }

    public function opsi(AuthContext $auth): array
    {
        throw new BelumTersediaException('konket');
    }

    public function detail(AuthContext $auth, int $id): array
    {
        throw new BelumTersediaException('konket');
    }

    public function tambah(AuthContext $auth, array $data, ?UploadedFile $berkas): array
    {
        throw new BelumTersediaException('konket');
    }

    public function ubah(AuthContext $auth, int $id, array $data, ?UploadedFile $berkas): array
    {
        throw new BelumTersediaException('konket');
    }

    public function hapus(AuthContext $auth, int $id): array
    {
        throw new BelumTersediaException('konket');
    }

    public function proses(AuthContext $auth, int $id, string $aksi, ?string $alasan): array
    {
        throw new BelumTersediaException('konket');
    }

    public function berkas(AuthContext $auth, int $id): array
    {
        throw new BelumTersediaException('konket');
    }
}
