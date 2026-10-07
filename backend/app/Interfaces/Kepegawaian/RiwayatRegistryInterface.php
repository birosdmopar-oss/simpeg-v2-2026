<?php

declare(strict_types=1);

namespace App\Interfaces\Kepegawaian;

use App\Libraries\Auth\AuthContext;
use App\Libraries\Kepegawaian\Riwayat\RiwayatDefinisi;

/**
 * Registry Definisi riwayat (kontrak beku S0-A MAKE-002; pemilik WS-1).
 */
interface RiwayatRegistryInterface
{
    /**
     * Semua Definisi terdaftar, urut urutanTab() lalu slug.
     *
     * @return list<RiwayatDefinisi>
     */
    public function semua(): array;

    /**
     * Definisi untuk slug `{jenis}`; null bila tidak terdaftar (→ 404).
     */
    public function definisi(string $jenis): ?RiwayatDefinisi;

    /**
     * Descriptor tab Detail Pegawai untuk pemanggil atas pegawai $nip (bentuk beku, README kontrak):
     * `{jenis, label, can_view, can_create, can_edit, can_delete, can_process}`, dihitung dari izin Definisi × role
     * pemanggil × PegawaiScope. Hanya jenis ber-alur bukan usulan dan yang can_view-nya true.
     *
     * @return list<array{jenis: string, label: string, can_view: bool, can_create: bool, can_edit: bool, can_delete: bool, can_process: bool}>
     */
    public function descriptorUntuk(AuthContext $auth, string $nip): array;
}
