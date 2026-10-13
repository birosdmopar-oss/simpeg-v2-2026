<?php

declare(strict_types=1);

namespace App\Models\Kepegawaian;

use App\Models\BaseAuditableModel;

/**
 * Tabel `riwayat_lckh` (B-02 DBV-013) — Laporan Kerja Harian (B-12b, MAKE-013). Status 0 Menunggu, 1 Disetujui,
 * 2 Ditolak, 3 Revisi, 10 Dihapus [V2] (legacy hapus keras + arsip trigger `riwayat_lckh_beDel`).
 */
class LkhModel extends BaseAuditableModel
{
    use PelakuAuditTrait;

    protected $table         = 'riwayat_lckh';
    protected $primaryKey    = 'id_riwayat_lckh';
    protected $returnType    = 'array';
    protected $useTimestamps = false;
    protected $allowedFields = [
        'id_unit', 'id_satker', 'nip', 'tgl_laporan', 'nama', 'unit', 'satker', 'nip_atasan', 'nama_atasan', 'kegiatan',
        'output', 'jumlah_diselesaikan', 'jam_mulai', 'jam_selesai', 'catatan', 'status', 'updated_by', 'approved_by',
        'show_notif', 'show_atasan', 'show_history_atasan',
    ];
}
