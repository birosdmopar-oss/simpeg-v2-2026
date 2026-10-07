<?php

declare(strict_types=1);

namespace App\Libraries\Kepegawaian\Riwayat;

/**
 * Aksi riwayat yang izinnya diatur per role di Definisi (RiwayatDefinisi::izin()). Nilai = key izin.
 */
enum AksiRiwayat: string
{
    case Lihat  = 'lihat';
    case Tambah = 'tambah';
    case Ubah   = 'ubah';
    case Hapus  = 'hapus';
    case Proses = 'proses';

    /**
     * Key descriptor tab (§2.3.5 SPRINT0_1_backend.md) untuk aksi ini.
     */
    public function kunciDescriptor(): string
    {
        return match ($this) {
            self::Lihat  => 'can_view',
            self::Tambah => 'can_create',
            self::Ubah   => 'can_edit',
            self::Hapus  => 'can_delete',
            self::Proses => 'can_process',
        };
    }

    /**
     * Aksi yang mengubah data (butuh PegawaiScopeInterface::bolehUbah()).
     */
    public function mengubah(): bool
    {
        return $this !== self::Lihat;
    }
}
