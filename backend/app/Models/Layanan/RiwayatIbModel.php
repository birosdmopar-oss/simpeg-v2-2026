<?php

declare(strict_types=1);

namespace App\Models\Layanan;

/**
 * Tabel `riwayat_ib` (DBV-022) — pengajuan Izin Belajar (C-08, PRD Fase 4 §6.8). Hapus fisik + audit [K]. Kolom `file_*`,
 * `status_admin_*`, `rs_*`, `sp_admin_deputi`, `show_ua_*`, `rated` tidak ditulis v2 (D14, DQ-20). Tanpa efek ke pegawai.
 */
class RiwayatIbModel extends LayananModel
{
    protected $table         = 'riwayat_ib';
    protected $primaryKey    = 'id_riwayat_ib';
    protected $returnType    = 'array';
    protected $useTimestamps = false;
    protected $allowedFields = [
        'nip', 'id_jenjang_pendidikan', 'nama', 'gol_ruang', 'pangkat', 'jabatan', 'unit', 'satker',
        'pendidikan_dasar_singkat', 'tgl_mulai', 'tgl_akhir', 'lama_tahun', 'jenjang_pendidikan_singkat',
        'institusi_pendidikan', 'jurusan_pendidikan', 'dalam_negeri', 'lokasi_pendidikan', 'lembaga_yang_mendanai',
        'up', 'tembusan', 'keterangan', 'ds_ib', 'no_ds_ib', 'tgl_ds_ib', 'perihal_ds_ib', 'no_sk', 'tgl_sk', 'status',
        'updated_at', 'updated_by', 'approved_by', 'reason_note', 'show_notif', 'notif_date', 'show_pl',
    ];
}
