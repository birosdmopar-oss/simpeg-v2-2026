<?php

declare(strict_types=1);

namespace App\Models\Kepegawaian;

use App\Models\BaseAuditableModel;

/**
 * Tabel `pegawai_plt` (B-02 DBV-012/DBV-019) — penugasan Plt pada jabatan lowong (B-07, MAKE-012). Status 1 Aktif,
 * 2 Tidak Aktif; hapus = hapus PERMANEN ikut legacy (audit "before" lewat model).
 */
class PegawaiPltModel extends BaseAuditableModel
{
    use PelakuAuditTrait;

    protected $table         = 'pegawai_plt';
    protected $primaryKey    = 'id_pegawai_plt';
    protected $returnType    = 'array';
    protected $useTimestamps = false;
    protected $allowedFields = [
        'jenis_jabatan', 'jenis_jabatan_koord', 'tgl_mulai', 'tgl_akhir', 'nip_plt', 'id_jabatan', 'id_jabatan_koord',
        'id_group_jabatan', 'id_sub_group_jabatan', 'id_unit', 'id_satker', 'id_group_jabatan_plt', 'id_sub_group_jabatan_plt',
        'id_unit_plt', 'id_satker_plt', 'keterangan', 'lampiran', 'status', 'updated_by',
        'created_at', 'updated_at',
    ];
}
