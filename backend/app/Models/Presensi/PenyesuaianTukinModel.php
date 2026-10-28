<?php

declare(strict_types=1);

namespace App\Models\Presensi;

use App\Models\AuditLogModel;
use App\Models\BaseAuditableModel;

/**
 * Penyesuaian Tukin per NIP `penyesuaian_tukin` (DBV-024, PRD 5B §7.3.10, F5B-14). Tulis lewat model ini agar audit
 * before/after otomatis (insert = create, ubah = update); hapus = status 10 lewat {@see self::hapusStatus()} dengan audit
 * event `delete` (pola `MasterModel::softDelete`). Stempel waktu UTC diisi service.
 */
class PenyesuaianTukinModel extends BaseAuditableModel
{
    public const STATUS_AKTIF = 1;

    public const STATUS_TIDAK_AKTIF = 2;

    public const STATUS_DIHAPUS = 10;

    protected $table          = 'penyesuaian_tukin';
    protected $primaryKey     = 'id_penyesuaian_tukin';
    protected $returnType     = 'array';
    protected $useSoftDeletes = false;
    protected $useTimestamps  = false;
    protected $allowedFields  = [
        'nip', 'bulan_mulai', 'bulan_akhir', 'persen', 'alasan', 'status', 'created_at', 'updated_at', 'created_by', 'updated_by',
    ];

    /**
     * Status → 10 (+ `updated_at`/`updated_by`), audit dicatat sebagai event `delete` dengan baris sebelum & sesudah.
     */
    public function hapusStatus(int $id, ?int $oleh, string $sekarang): void
    {
        $before = $this->fetchRows([$id])[0] ?? null;

        $this->auditEnabled = false;

        try {
            $this->update($id, ['status' => self::STATUS_DIHAPUS, 'updated_at' => $sekarang, 'updated_by' => $oleh]);
        } finally {
            $this->auditEnabled = true;
        }

        $this->writeAudit(AuditLogModel::EVENT_DELETE, $id, $before, $this->fetchRows([$id])[0] ?? null);
    }
}
