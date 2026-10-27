<?php

declare(strict_types=1);

namespace App\Models\Layanan;

use App\Models\BaseAuditableModel;

/**
 * Riwayat cuti online `riwayat_cuti` (DBV-022, PRD Fase 4 §6.2). Hapus fisik (D6, tanpa status 10); audit otomatis.
 * Kolom `status*` tidak boleh ditulis dari input klien — service mengisinya dari state machine ({@see \App\Libraries\Layanan\Cuti\TransisiCuti}).
 * Path berkas (`file_bukti`, `file_sc_*`, `file_surat_cuti`) internal dan tidak dikirim ke klien.
 */
class RiwayatCutiModel extends BaseAuditableModel
{
    /** Kolom yang tidak pernah dikirim ke klien. */
    public const KOLOM_INTERNAL = ['file_bukti', 'file_sc_unsign', 'file_sc_signed_bef', 'file_surat_cuti'];

    protected $table          = 'riwayat_cuti';
    protected $primaryKey     = 'id_riwayat_cuti';
    protected $returnType     = 'array';
    protected $useSoftDeletes = false;
    protected $useTimestamps  = false;
    protected $allowedFields  = [
        'nip', 'nama', 'id_sub_group_jabatan', 'pangkat', 'gol_ruang', 'jabatan', 'satker', 'mker_th', 'mker_bl',
        'id_jenis_cuti', 'nama_jenis_cuti', 'tgl_mulai', 'tgl_akhir', 'lama_cuti', 'satuan_lama_cuti',
        'no_telp_saat_cuti', 'alamat_saat_cuti', 'file_bukti', 'sisa_cuti', 'sisa_cuti_n1', 'sisa_cuti_n2', 'utang_cuti',
        'keterangan', 'nip_atasan_langsung', 'nama_atasan_langsung', 'jabatan_atasan_langsung', 'id_jabatan_al',
        'status_al', 'reason_note_al', 'nip_yang_menyetujui', 'nama_yang_menyetujui', 'jabatan_yang_menyetujui',
        'id_jabatan_pyb', 'status_pyb', 'reason_note_pyb', 'file_sc_unsign', 'file_sc_signed_bef', 'file_surat_cuti',
        'keterangan_petugas_approval', 'ketua_tim', 'status', 'status_regen', 'created_at', 'updated_by',
        'approved_by', 'reason_note', 'rated', 'skip_al', 'skip_pyb', 'show_notif', 'notif_date', 'show_atasan',
        'show_pejabat', 'show_history_atasan',
    ];
}
