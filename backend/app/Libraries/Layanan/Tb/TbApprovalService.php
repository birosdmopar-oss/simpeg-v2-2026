<?php

declare(strict_types=1);

namespace App\Libraries\Layanan\Tb;

use App\Libraries\Layanan\PetugasLayananService;
use App\Libraries\Layanan\Studi\AturanStudi;
use App\Libraries\Layanan\StudiApprovalService;
use App\Models\Layanan\LayananModel;
use App\Models\Layanan\RiwayatTbModel;

/**
 * Proses PIC TB (PRD Fase 4 §6.7.3): PIC TB aktif (`petugas_layanan` jenis 3), status 0 → 1/2. Setujui: `no_sk`, `tgl_sk`,
 * `ds_tb`, `no_ds_tb` wajib, `tgl_ds_tb`/`perihal_ds_tb` opsional, `file_surat_tb` opsional di server (PDF 5 MB, wajib
 * di FE [K]) → `document_attachment` kode 47; efek final `id_jenis_status` (EfekTbTrait).
 */
class TbApprovalService extends StudiApprovalService
{
    use EfekTbTrait;

    protected function jenis(): string
    {
        return AturanStudi::JENIS_TB;
    }

    protected function rolePengaju(): array
    {
        return TbService::ROLE_PENGAJU;
    }

    protected function model(): LayananModel
    {
        return new RiwayatTbModel($this->koneksi());
    }

    protected function idJenisLayanan(): int
    {
        return PetugasLayananService::JENIS_TB;
    }

    protected function kolomSkOpsional(): array
    {
        return ['tgl_ds_tb', 'perihal_ds_tb'];
    }
}
