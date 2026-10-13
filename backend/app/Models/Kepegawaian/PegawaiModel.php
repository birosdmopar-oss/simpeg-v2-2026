<?php

declare(strict_types=1);

namespace App\Models\Kepegawaian;

use App\Models\BaseAuditableModel;

/**
 * Tabel `pegawai` (B-01 DBV-012, PK `nip`) untuk tulis biodata B-03/B-04/B-05 WS-2 (MAKE-010) dengan audit otomatis
 * create/update/delete. `updated_at` diisi service dalam UTC; `created_at` default DB. Koreksi NIP (B-06) tidak lewat
 * model ini (NipCascadeService: salin + arahkan ulang + hapus dalam satu transaksi, audit manual).
 */
class PegawaiModel extends BaseAuditableModel
{
    use PelakuAuditTrait;

    protected $table            = 'pegawai';
    protected $primaryKey       = 'nip';
    protected $useAutoIncrement = false;
    protected $returnType       = 'array';
    protected $useTimestamps    = false;
    protected $allowedFields    = [
        'nip', 'id_provinsi_lahir', 'id_kabupaten_kota_lahir', 'id_agama', 'id_jenis_pegawai', 'id_jenis_status', 'nip_lama',
        'nama', 'glr_awal', 'glr_akhir', 'tgl_lahir', 'provinsi_lahir', 'provinsi_lahir_lain', 'kabupaten_kota_lahir',
        'kabupaten_kota_lahir_lain', 'agama', 'jenis_kelamin', 'npwp', 'nik', 'bpjs_kes', 'bpjs_ket', 'no_taspen', 'no_hp',
        'jenis_kerabat', 'no_telp_kerabat', 'status_pernikahan', 'jenis_pegawai', 'jenis_status', 'tmt_status', 'foto',
        'keterangan', 'status', 'flag_update', 'id_pns_siasn', 'siasn_flag', 'siasn_lu', 'created_at', 'updated_at',
        'updated_by', 'deleted_at',
    ];
}
