<?php

declare(strict_types=1);

namespace App\Libraries\Layanan;

use CodeIgniter\Database\BaseConnection;

/**
 * PIC layanan (`petugas_layanan`, PRD Fase 4 §3 "PIC", §6.10.2): approver TB/IB/PMK (D14, D16), ditunjuk role 1.
 * Dipakai bersama C-07/C-08/C-09 (M5) dan C-10 (M6, CRUD + dashboard). PIC = baris `status = 1` (aktif) untuk
 * `id_jenis_layanan` itu; tanpa batas unit (D14 [V2], perbaikan TB-01). Anomali Karpeg/Kariskarsu tanpa filter status
 * tidak disentuh di Fase 4 (§6.10.2).
 */
class PetugasLayananService
{
    /** `jenis_layanan` (seed DBV-022): 3 Tugas Belajar, 4 Izin Belajar, 5 Peninjauan Masa Kerja. */
    public const JENIS_TB = 3;

    public const JENIS_IB = 4;

    public const JENIS_PMK = 5;

    /** `petugas_layanan.status` aktif [K] (domain DBV-022: 0 Tidak Aktif, 1 Aktif, 10 dihapus). */
    public const STATUS_AKTIF = 1;

    public function __construct(private readonly ?BaseConnection $db = null)
    {
    }

    public function adalahPic(?string $nip, int $idJenisLayanan): bool
    {
        if ($nip === null || $nip === '') {
            return false;
        }

        return $this->db()->table('petugas_layanan')
            ->where('id_jenis_layanan', $idJenisLayanan)
            ->where('nip', $nip)
            ->where('status', self::STATUS_AKTIF)
            ->countAllResults() > 0;
    }

    /**
     * NIP semua PIC aktif satu jenis layanan (penerima notifikasi pengajuan baru, §6.6.2), urut NIP.
     *
     * @return list<string>
     */
    public function nipPicAktif(int $idJenisLayanan): array
    {
        $rows = $this->db()->table('petugas_layanan')->select('nip')
            ->where('id_jenis_layanan', $idJenisLayanan)
            ->where('status', self::STATUS_AKTIF)
            ->orderBy('nip')->get()->getResultArray();

        return array_values(array_unique(array_map(static fn (array $r): string => (string) $r['nip'], $rows)));
    }

    private function db(): BaseConnection
    {
        return $this->db ?? db_connect();
    }
}
