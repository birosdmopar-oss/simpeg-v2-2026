<?php

declare(strict_types=1);

namespace App\Libraries\Layanan\Ib;

use App\Libraries\Auth\AuthContext;
use App\Libraries\Layanan\PetugasLayananService;
use App\Libraries\Layanan\Studi\AturanStudi;
use App\Libraries\Layanan\StudiApprovalService;
use App\Models\Layanan\LayananModel;
use App\Models\Layanan\RiwayatIbModel;

/**
 * Proses PIC IB (PRD Fase 4 §6.8.1, §6.8.2): PIC IB aktif (`petugas_layanan` jenis 4), status 0 → 1/2. Setujui: `ds_ib`,
 * `no_ds_ib`, `no_sk`, `tgl_sk` wajib, `tgl_ds_ib`/`perihal_ds_ib` opsional, `show_pl = 1`, `approved_by`; efek final =
 * Surat IB PDF dibuat otomatis → `document_attachment` kode 50 (SuratIbService). Tolak → 2 + `reason_note` wajib. Tanpa
 * efek ke `pegawai.id_jenis_status`, presensi, tukin (IB-5 [K]).
 */
class IbApprovalService extends StudiApprovalService
{
    protected function jenis(): string
    {
        return AturanStudi::JENIS_IB;
    }

    protected function rolePengaju(): array
    {
        return IbService::ROLE_PENGAJU;
    }

    protected function model(): LayananModel
    {
        return new RiwayatIbModel($this->koneksi());
    }

    protected function idJenisLayanan(): int
    {
        return PetugasLayananService::JENIS_IB;
    }

    protected function kolomSkOpsional(): array
    {
        return ['tgl_ds_ib', 'perihal_ds_ib'];
    }

    protected function efekFinalSetuju(AuthContext $auth, array $row): void
    {
        (new SuratIbService($this->koneksi(), $this->lampiran()))->buatDanSimpan($row);
    }
}
