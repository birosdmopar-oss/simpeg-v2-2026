<?php

declare(strict_types=1);

namespace App\Libraries\Layanan\Tb;

use App\Libraries\Auth\AuthContext;
use App\Libraries\Layanan\Studi\RentangTanggal;

/**
 * Efek final TB (PRD Fase 4 §6.7.3, D15) — dipakai TbService (input role 1 status 1, ubah 0 → 1) dan TbApprovalService
 * (setujui); kebalikannya saat hapus ada di TbService::sebelumHapus(). Dipakai turunan LayananStudi.
 *
 * - Setujui: bila hari ini dalam `tgl_mulai..tgl_akhir` → `pegawai.id_jenis_status = 2` (beraudit) [K `L_tb.php:1169-1180`].
 *   Tanpa cron: TB yang mulai di masa depan tidak mengubah status [K]; konsumen Fase 5 memakai `bebasPresensiPenuh`.
 * - Hapus TB status 1: `id_jenis_status` kembali 1 bila hari ini dalam rentang, pegawai aktif, dan masih 2
 *   [K `L_tb.php:746-760`]; semua perpanjangan + berkasnya dihapus eksplisit dulu (FK RESTRICT, perbaikan TB-14).
 * - [V2] Rentang dibandingkan per tanggal inklusif (legacy membandingkan dengan jam sekarang sehingga hari terakhir TB
 *   tidak ikut).
 */
trait EfekTbTrait
{
    /** `jenis_status` [K]: 1 Aktif, 2 Tugas Belajar. */
    protected const JENIS_STATUS_AKTIF = 1;

    protected const JENIS_STATUS_TB = 2;

    /**
     * @param array<string, mixed> $row
     */
    protected function efekFinalSetuju(AuthContext $auth, array $row): void
    {
        if ($this->hariIniDalamTb($row)) {
            $this->ubahJenisStatus($auth, (string) $row['nip'], self::JENIS_STATUS_TB);
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    protected function hariIniDalamTb(array $row): bool
    {
        $hariIni = $this->hariIni();

        return RentangTanggal::beririsan((string) $row['tgl_mulai'], (string) $row['tgl_akhir'], $hariIni, $hariIni);
    }
}
