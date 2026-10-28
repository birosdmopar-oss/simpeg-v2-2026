<?php

declare(strict_types=1);

namespace App\Models\Layanan;

use App\Models\BaseAuditableModel;

/**
 * Aturan petugas approval `pa_layanan` (DBV-022, PRD Fase 4 §6.4.8). Whitelist kolom di bawah = satu-satunya yang
 * boleh ditulis (bukan mass-assignment `$postData` legacy). Hapus fisik + audit.
 */
class PaLayananModel extends BaseAuditableModel
{
    protected $table          = 'pa_layanan';
    protected $primaryKey     = 'id_pa_layanan';
    protected $returnType     = 'array';
    protected $useSoftDeletes = false;
    protected $useTimestamps  = false;
    protected $allowedFields  = [
        'id_jenis_pa', 'id_jenis_pegawai', 'id_group_jabatan_pemohon', 'id_sub_group_jabatan_pemohon', 'gol_ruang_pemohon',
        'id_unit_pemohon', 'id_group_jabatan_petugas', 'id_sub_group_jabatan_petugas', 'id_unit_petugas', 'id_satker_petugas',
        'id_jabatan', 'id_jabatan_koord', 'nip', 'status', 'updated_by',
    ];
}
