<?php

declare(strict_types=1);

namespace App\Models\Kepegawaian;

use App\Models\BaseAuditableModel;

/**
 * Tabel `absen_ijin` (B-02 DBV-013) — Konfirmasi Ketidakhadiran / Konket (B-13, MAKE-013). Status W Menunggu, V
 * Disetujui, X Ditolak, 10 Dihapus [V2] (legacy hapus keras + arsip trigger `absen_ijin_beDel`).
 */
class KonketModel extends BaseAuditableModel
{
    use PelakuAuditTrait;

    protected $table         = 'absen_ijin';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = false;
    protected $allowedFields = [
        'nip', 'date_start', 'date_end', 'kategori', 'jenis_konket', 'jenis_dinas', 'affect_tukin', 'id_parent', 'alasan',
        'file_bukti', 'status', 'updated_by', 'approved_by', 'reason_note', 'show_notif', 'notif_date', 'show_ua_upt',
        'show_ua_deputi', 'show_ua_biro',
        'created_at', 'updated_at',
    ];
}
