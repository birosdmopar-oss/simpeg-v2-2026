<?php

declare(strict_types=1);

namespace App\Libraries\Kepegawaian\Stub;

use App\Exceptions\BelumTersediaException;
use App\Interfaces\Kepegawaian\LkhServiceInterface;
use App\Libraries\Auth\AuthContext;

/**
 * Stub S0-A (MAKE-002); implementasi nyata WS-2 MAKE-013 (LkhService). Fail-closed (501).
 */
final class StubLkhService implements LkhServiceInterface
{
    public function daftar(AuthContext $auth, array $filter = []): array
    {
        throw new BelumTersediaException('lkh');
    }

    public function verifikasi(AuthContext $auth, array $filter = []): array
    {
        throw new BelumTersediaException('lkh');
    }

    public function opsi(AuthContext $auth, ?string $nip = null): array
    {
        throw new BelumTersediaException('lkh');
    }

    public function detail(AuthContext $auth, int $id): array
    {
        throw new BelumTersediaException('lkh');
    }

    public function tambah(AuthContext $auth, array $data): array
    {
        throw new BelumTersediaException('lkh');
    }

    public function ubah(AuthContext $auth, int $id, array $data): array
    {
        throw new BelumTersediaException('lkh');
    }

    public function hapus(AuthContext $auth, int $id): array
    {
        throw new BelumTersediaException('lkh');
    }

    public function proses(AuthContext $auth, int $id, string $aksi, ?string $catatan): array
    {
        throw new BelumTersediaException('lkh');
    }

    public function pdf(AuthContext $auth, int $id): array
    {
        throw new BelumTersediaException('lkh');
    }

    public function descriptorTab(AuthContext $auth, string $nip): ?array
    {
        return null;
    }
}
