<?php

declare(strict_types=1);

namespace App\Models\MasterData;

use App\Models\AuditLogModel;
use App\Models\BaseAuditableModel;

/**
 * Tabel hari_libur (G-08). Turunan BaseAuditableModel → tambah/ubah tercatat di audit_logs.
 * "Hapus" = status '0' lewat softDelete() (audit event 'delete'), tidak pernah hard delete.
 */
class HariLiburModel extends BaseAuditableModel
{
    public const STATUS_ACTIVE   = '1';
    public const STATUS_INACTIVE = '0';

    /**
     * Kolom yang boleh diubah lewat endpoint update.
     *
     * @var list<string>
     */
    public const EDITABLE = ['id_jenis_libur', 'nama', 'tgl_mulai', 'tgl_akhir', 'keterangan', 'status'];

    protected $table         = 'hari_libur';
    protected $primaryKey    = 'id_hari_libur';
    protected $returnType    = 'array';
    protected $useTimestamps = false;
    protected $allowedFields = ['id_jenis_libur', 'nama', 'tgl_mulai', 'tgl_akhir', 'keterangan', 'status'];

    public function softDelete(string $id): void
    {
        $before = $this->fetchRows([$id])[0] ?? null;

        $this->auditEnabled = false;

        try {
            $this->update($id, ['status' => self::STATUS_INACTIVE]);
        } finally {
            $this->auditEnabled = true;
        }

        $after = $this->fetchRows([$id])[0] ?? null;
        $this->writeAudit(AuditLogModel::EVENT_DELETE, $id, $before, $after);
    }
}
