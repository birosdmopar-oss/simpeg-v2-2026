<?php

declare(strict_types=1);

namespace App\Libraries\Layanan\Ib;

use App\Constants\Role;
use App\Libraries\Auth\AuthContext;
use App\Libraries\Layanan\PetugasLayananService;
use App\Libraries\Layanan\Studi\AturanStudi;
use App\Libraries\Layanan\StudiPengajuanService;
use App\Models\Layanan\LayananModel;
use App\Models\Layanan\RiwayatIbModel;

/**
 * Izin Belajar C-08 — CRUD pengajuan (PRD Fase 4 §5.3, §6.8.1; "sama TB kecuali disebut"). Role 2/7 diri sendiri; role 1
 * dan 3 input admin (`nip`, 3 dalam lingkup) — bug legacy `add_ad` tanpa `$_FILES` tidak dibawa (IB-01). Status **selalu
 * 0** (role 1 yang mengirim `status` ≠ 0 → 422, Ib.php:128-171). Tiga berkas `file_sp`, `file_1`, `file_2` wajib semua
 * role termasuk role 1 [K `L_ib.php:705`]. Tanpa perpanjangan dan tanpa efek ke pegawai/presensi/tukin (IB-5). Role 6
 * ditolak route (DoD 071-3).
 */
class IbService extends StudiPengajuanService
{
    /** Role route IB (PRD §5.3; role 6 → 403, DoD 071-3) — dipakai RoutesLayananStudi.php & KontrakMatriksRoleTest. */
    public const ROLE_ROUTE = [Role::SUPER_ADMIN, Role::PEGAWAI, Role::ADMIN_SATKER, Role::PPPK];

    /** Role yang mengajukan untuk diri sendiri. */
    public const ROLE_PENGAJU = [Role::PEGAWAI, Role::PPPK];

    protected function jenis(): string
    {
        return AturanStudi::JENIS_IB;
    }

    protected function rolePengaju(): array
    {
        return self::ROLE_PENGAJU;
    }

    protected function model(): LayananModel
    {
        return new RiwayatIbModel($this->koneksi());
    }

    protected function idJenisLayanan(): int
    {
        return PetugasLayananService::JENIS_IB;
    }

    /**
     * Role 1 yang mengirim `status` diteruskan ke ValidasiStudi (IB: selain 0 → 422); role lain diabaikan (0).
     */
    protected function statusAwal(AuthContext $auth, array $data): ?int
    {
        if ($auth->role() !== Role::SUPER_ADMIN || ! array_key_exists('status', $data) || ! is_scalar($data['status']) || trim((string) $data['status']) === '') {
            return null;
        }

        $nilai = trim((string) $data['status']);

        return ctype_digit($nilai) ? (int) $nilai : -1;
    }

    protected function role1BebasBerkas(): bool
    {
        return false;
    }

    /**
     * IB tidak pernah final lewat tambah/ubah (status selalu 0); efek final hanya lewat IbApprovalService.
     */
    protected function efekFinalSetuju(AuthContext $auth, array $row): void
    {
        (new SuratIbService($this->koneksi(), $this->lampiran()))->buatDanSimpan($row);
    }
}
