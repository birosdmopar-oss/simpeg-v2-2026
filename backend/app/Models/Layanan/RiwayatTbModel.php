<?php

declare(strict_types=1);

namespace App\Models\Layanan;

/**
 * Tabel `riwayat_tb` (DBV-022) — pengajuan Tugas Belajar (C-07, PRD Fase 4 §6.7). Hapus fisik + audit [K]. Kolom
 * `file_*`, `status_admin_*`, `rs_*`, `sp_admin_deputi`, `show_ua_*`, `rated` tidak ditulis v2 (D14 satu langkah, DQ-20) —
 * tidak ada di `$allowedFields`. Extends BaseAuditableModel (bukan BaseSnapshotModel): TB tidak punya tabel snapshot;
 * efek final = `pegawai.id_jenis_status` lewat PegawaiModel beraudit (§6.7.3).
 */
class RiwayatTbModel extends LayananModel
{
    protected $table         = 'riwayat_tb';
    protected $primaryKey    = 'id_riwayat_tb';
    protected $returnType    = 'array';
    protected $useTimestamps = false;
    protected $allowedFields = [
        'nip', 'id_jenjang_pendidikan', 'nama', 'gol_ruang', 'pangkat', 'jabatan', 'unit', 'satker',
        'pendidikan_dasar_singkat', 'tgl_mulai', 'tgl_akhir', 'tgl_mulai_perpan', 'tgl_akhir_perpan', 'lama_tahun',
        'jenjang_pendidikan_singkat', 'institusi_pendidikan', 'jurusan_pendidikan', 'dalam_negeri',
        'lokasi_pendidikan', 'lembaga_yang_mendanai', 'up', 'tembusan', 'keterangan', 'ds_tb', 'no_ds_tb', 'tgl_ds_tb',
        'perihal_ds_tb', 'no_sk', 'tgl_sk', 'status', 'updated_at', 'updated_by', 'approved_by', 'reason_note',
        'show_notif', 'notif_date', 'show_pl',
    ];
}
