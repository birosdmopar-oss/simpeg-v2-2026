<?php

declare(strict_types=1);

namespace Tests\Support\Kepegawaian\Definisi;

use App\Constants\Role;
use App\Libraries\Kepegawaian\Riwayat\AlurRiwayat;
use App\Libraries\Kepegawaian\Riwayat\RiwayatDefinisi;

/**
 * Definisi CONTOH ber-alur usulan untuk test S0-A (MAKE-002): halaman usulan mandiri, tidak muncul di descriptor tab.
 */
final class ContohKarpeg extends RiwayatDefinisi
{
    public function jenis(): string
    {
        return 'karpeg';
    }

    public function label(): string
    {
        return 'Kartu Pegawai';
    }

    public function tabel(): string
    {
        return 'riwayat_karpeg';
    }

    public function primaryKey(): string
    {
        return 'id_riwayat_karpeg';
    }

    public function fields(): array
    {
        return [];
    }

    public function izin(): array
    {
        return ['lihat' => [Role::SUPER_ADMIN, Role::PEGAWAI], 'tambah' => [Role::PEGAWAI]];
    }

    public function alur(): AlurRiwayat
    {
        return AlurRiwayat::Usulan;
    }
}
