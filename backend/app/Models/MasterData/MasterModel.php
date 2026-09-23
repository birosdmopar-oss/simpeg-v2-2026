<?php

declare(strict_types=1);

namespace App\Models\MasterData;

use App\Libraries\MasterData\MasterDefinition;
use App\Models\AuditLogModel;
use App\Models\BaseAuditableModel;
use CodeIgniter\Database\ConnectionInterface;

/**
 * Model generik seluruh tabel master Modul G. Dikonfigurasi dari MasterDefinition (tabel, PK, kolom).
 * Turunan BaseAuditableModel → tambah/ubah tercatat otomatis di audit_logs (G-TC audit).
 *
 * Master TIDAK PERNAH di-hard-delete dari aplikasi (G-TC soft-delete only): "hapus" = status '0',
 * dicatat sebagai event audit 'delete' lewat softDelete().
 */
class MasterModel extends BaseAuditableModel
{
    public const STATUS_ACTIVE   = '1';
    public const STATUS_INACTIVE = '0';

    protected $returnType     = 'array';
    protected $useTimestamps  = false;
    protected $useSoftDeletes = false;

    public function __construct(private MasterDefinition $definition, ?ConnectionInterface $db = null)
    {
        $this->table         = $definition->table;
        $this->primaryKey    = $definition->primaryKey;
        $this->allowedFields = $definition->columns();
        // PK string yang diinput admin: matikan auto increment supaya CI4 mengirim kolom PK saat insert.
        $this->useAutoIncrement = $definition->autoIncrement;

        parent::__construct($db);
    }

    public function definition(): MasterDefinition
    {
        return $this->definition;
    }

    /**
     * Soft delete: status → '0' tanpa menghapus baris, audit dicatat sebagai event 'delete' (before/after).
     */
    public function softDelete(string $id): void
    {
        $before = $this->fetchRows([$id])[0] ?? null;

        $this->auditEnabled = false;

        try {
            $this->update($id, [MasterDefinition::STATUS_FIELD => self::STATUS_INACTIVE]);
        } finally {
            $this->auditEnabled = true;
        }

        $after = $this->fetchRows([$id])[0] ?? null;
        $this->writeAudit(AuditLogModel::EVENT_DELETE, $id, $before, $after);
    }
}
