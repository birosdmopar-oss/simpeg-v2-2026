<?php

declare(strict_types=1);

namespace App\Models\Kepegawaian;

use App\Models\BaseAuditableModel;

/**
 * Tabel `pegawai_plh` (B-02 DBV-012/DBV-019) — penugasan Plh pada jabatan lowong (B-07, MAKE-012). Status 1 Aktif,
 * 2 Tidak Aktif, 10 Dihapus [V2] (legacy hapus keras).
 */
class PegawaiPlhModel extends BaseAuditableModel
{
    use PelakuAuditTrait;

    protected $table         = 'pegawai_plh';
    protected $primaryKey    = 'id_pegawai_plh';
    protected $returnType    = 'array';
    protected $useTimestamps = false;
    protected $allowedFields = [
        'jenis_jabatan', 'jenis_jabatan_koord', 'tgl_mulai', 'tgl_akhir', 'nip_plh', 'id_jabatan', 'id_jabatan_koord',
        'id_group_jabatan', 'id_sub_group_jabatan', 'id_unit', 'id_satker', 'id_group_jabatan_plh', 'id_sub_group_jabatan_plh',
        'id_unit_plh', 'id_satker_plh', 'keterangan', 'lampiran', 'status', 'updated_by',
    ];
}
