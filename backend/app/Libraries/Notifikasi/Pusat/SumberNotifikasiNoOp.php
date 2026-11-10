<?php

declare(strict_types=1);

namespace App\Libraries\Notifikasi\Pusat;

use App\Interfaces\Notifikasi\SumberNotifikasiInterface;
use App\Libraries\Auth\AuthContext;

/**
 * Binding sementara sumber notifikasi eksternal (mis. berita F-07) sampai modulnya ada di `main` (PRD Fase 7 v2.0 §7).
 */
final class SumberNotifikasiNoOp implements SumberNotifikasiInterface
{
    public function __construct(private readonly string $kode = 'berita')
    {
    }

    public function kode(): string
    {
        return $this->kode;
    }

    public function tersedia(): bool
    {
        return false;
    }

    public function jumlah(AuthContext $auth, bool $hanyaBelumDibaca): int
    {
        return 0;
    }

    public function daftar(AuthContext $auth, bool $hanyaBelumDibaca, int $batas): array
    {
        return [];
    }

    public function tandaiDibaca(AuthContext $auth, array $ids): int
    {
        return 0;
    }

    public function hapus(AuthContext $auth, int $id): bool
    {
        return false;
    }

    public function jumlahMenunggu(AuthContext $auth): int
    {
        return 0;
    }

    public function daftarMenunggu(AuthContext $auth, int $page, int $perPage): array
    {
        return ['items' => [], 'total' => 0, 'url_fe' => null];
    }

    public function histori(AuthContext $auth, string $bulan, int $page, int $perPage): array
    {
        return ['items' => [], 'total' => 0];
    }

    public function jumlahLonceng(AuthContext $auth): ?int
    {
        return null;
    }
}
