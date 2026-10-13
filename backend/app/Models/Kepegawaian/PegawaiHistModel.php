<?php

declare(strict_types=1);

namespace App\Models\Kepegawaian;

use App\Models\BaseAuditableModel;

/**
 * Tabel `pegawai_hist` (B-01 DBV-012) — pengajuan perubahan biodata (B-03 jalur approval, B-04 proses), satu pengajuan
 * aktif (`flag_update` 1) per NIP. `flag_update`: 0 tanpa pengajuan, 1 diajukan, 2 ditolak, 3 disetujui (COMMENT DDL);
 * default DDL 0, jadi service menulis 1 secara eksplisit (dok DBV-012 3.1.2).
 */
class PegawaiHistModel extends BaseAuditableModel
{
    use PelakuAuditTrait;

    public const FLAG_TANPA     = 0;
    public const FLAG_DIAJUKAN  = 1;
    public const FLAG_DITOLAK   = 2;
    public const FLAG_DISETUJUI = 3;

    protected $table         = 'pegawai_hist';
    protected $primaryKey    = 'id_pegawai_hist';
    protected $returnType    = 'array';
    protected $useTimestamps = false;
    protected $allowedFields = [
        'nip', 'id_provinsi_lahir', 'id_kabupaten_kota_lahir', 'id_agama', 'id_jenis_pegawai', 'id_jenis_status', 'nip_lama',
        'nama', 'glr_awal', 'glr_akhir', 'tgl_lahir', 'provinsi_lahir', 'provinsi_lahir_lain', 'kabupaten_kota_lahir',
        'kabupaten_kota_lahir_lain', 'agama', 'jenis_kelamin', 'npwp', 'nik', 'bpjs_kes', 'bpjs_ket', 'no_taspen', 'no_hp',
        'jenis_kerabat', 'no_telp_kerabat', 'status_pernikahan', 'jenis_pegawai', 'jenis_status', 'tmt_status', 'foto',
        'keterangan', 'email', 'status', 'flag_update', 'id_pns_siasn', 'siasn_flag', 'siasn_lu', 'created_at',
        'updated_at', 'updated_by', 'approved_by', 'reason_note', 'show_notif', 'notif_date', 'show_ua_upt',
        'show_ua_deputi', 'show_ua_biro',
    ];
}
