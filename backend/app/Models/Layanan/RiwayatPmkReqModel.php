<?php

declare(strict_types=1);

namespace App\Models\Layanan;

/**
 * Tabel `riwayat_pmk_req` (DBV-022) — pengajuan Peninjauan Masa Kerja (C-09, PRD Fase 4 §6.9). Hapus fisik + audit [K].
 * Empat berkas di kolom [K] (`file_kontrak`, `file_paklaring`, `file_surat_phk`, `file_slip_gaji`, lewat KolomBerkas).
 * DEFAULT kolom `status` = 1 [K D1]; v2 selalu menulis 0 saat tambah.
 */
class RiwayatPmkReqModel extends LayananModel
{
    protected $table         = 'riwayat_pmk_req';
    protected $primaryKey    = 'id_riwayat_pmk_req';
    protected $returnType    = 'array';
    protected $useTimestamps = false;
    protected $allowedFields = [
        'nip', 'jenis_pmk', 'instansi', 'tgl_awal', 'tgl_akhir', 'mker_th', 'mker_bl', 'file_kontrak', 'file_paklaring',
        'file_surat_phk', 'file_slip_gaji', 'status', 'reason_note', 'show_notif', 'notif_date', 'created_by', 'updated_at',
        'updated_by', 'approved_at', 'approved_by',
    ];
}
