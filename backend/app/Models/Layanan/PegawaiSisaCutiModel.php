<?php

declare(strict_types=1);

namespace App\Models\Layanan;

use App\Models\BaseAuditableModel;

/**
 * Saldo cuti tahunan `pegawai_sisa_cuti` (DBV-022, PRD Fase 4 §6.3). Tulis lewat model ini agar audit before/after
 * otomatis (insert = create, ubah saldo = update, hapus fisik = delete). `created_at`/`updated_at` diisi DB.
 */
class PegawaiSisaCutiModel extends BaseAuditableModel
{
    protected $table          = 'pegawai_sisa_cuti';
    protected $primaryKey     = 'id_pegawai_sisa_cuti';
    protected $returnType     = 'array';
    protected $useSoftDeletes = false;
    protected $useTimestamps  = false;
    protected $allowedFields  = [
        'nip', 'tahun', 'sisa_cuti', 'sisa_cuti_n1', 'sisa_cuti_n2', 'sisa_cuti_ditangguhkan', 'utang_cuti', 'digunakan', 'updated_by',
        'created_at', 'updated_at',
    ];
}
