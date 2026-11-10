<?php

declare(strict_types=1);

namespace App\Libraries\Notifikasi\Pusat;

use App\Interfaces\Notifikasi\SumberNotifikasiInterface;
use App\Libraries\Auth\AuthContext;

/**
 * Membungkus {@see SumberNotifikasiInterface} (sumber modul lain, mis. berita F-07) menjadi {@see SumberDaftar}: id lokal
 * sumber menjadi id gabungan `<kode>:<id>`. Sumber yang belum tersedia (NoOp) tidak ikut daftar.
 */
final class AdapterSumberEksternal implements SumberDaftar
{
    public function __construct(private readonly SumberNotifikasiInterface $sumber)
    {
    }

    public function kunci(AuthContext $auth): array
    {
        return $this->sumber->tersedia() ? [$this->sumber->kode()] : [];
    }

    public function jumlah(AuthContext $auth, bool $hanyaBelumDibaca): int
    {
        return $this->sumber->tersedia() ? $this->sumber->jumlah($auth, $hanyaBelumDibaca) : 0;
    }

    public function daftar(AuthContext $auth, bool $hanyaBelumDibaca, int $batas): array
    {
        if (! $this->sumber->tersedia()) {
            return [];
        }

        $kode = $this->sumber->kode();

        return array_map(static fn (array $i): array => ['id' => $kode . ':' . (int) $i['id'], 'sumber' => $kode] + $i, $this->sumber->daftar($auth, $hanyaBelumDibaca, $batas));
    }

    public function tandaiDibaca(AuthContext $auth, string $kunci, array $ids): int
    {
        return $this->sumber->tersedia() && $kunci === $this->sumber->kode() ? $this->sumber->tandaiDibaca($auth, $ids) : 0;
    }

    public function hapus(AuthContext $auth, string $kunci, int $id): bool
    {
        return $this->sumber->tersedia() && $kunci === $this->sumber->kode() && $this->sumber->hapus($auth, $id);
    }
}
