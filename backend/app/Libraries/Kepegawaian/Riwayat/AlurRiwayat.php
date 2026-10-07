<?php

declare(strict_types=1);

namespace App\Libraries\Kepegawaian\Riwayat;

/**
 * Alur pengisian satu jenis riwayat.
 */
enum AlurRiwayat: string
{
    /**
     * Pegawai (UL_PEGAWAI, role 2/6/7) mengajukan → status 0 Menunggu, admin memproses; admin input langsung status 1.
     */
    case SelfService = 'self-service';

    /**
     * Hanya admin yang input, langsung status 1 Disetujui.
     */
    case Admin = 'admin';

    /**
     * Halaman usulan mandiri (Karpeg/Karis-Karsu), bukan tab Detail Pegawai — tidak muncul di descriptor tab.
     */
    case Usulan = 'usulan';
}
