<?php

declare(strict_types=1);

namespace App\Models\MasterData;

use App\Libraries\MasterData\MasterDefinition;
use App\Models\AuditLogModel;
use App\Models\BaseAuditableModel;
use CodeIgniter\Database\ConnectionInterface;
use CodeIgniter\I18n\Time;

/**
 * Model generik seluruh tabel master Modul G. Dikonfigurasi dari MasterDefinition (tabel, PK, kolom).
 * Turunan BaseAuditableModel → tambah/ubah tercatat otomatis di audit_logs (G-TC audit).
 *
 * Status mengikuti legacy (DBV-001): 1 = Aktif, 2 = Tidak Aktif, 10 = Dihapus. Master TIDAK PERNAH di-hard-delete
 * dari aplikasi (G-TC soft-delete only): "hapus" = status 10 (+ deleted_at bila kolomnya ada), dicatat sebagai
 * event audit 'delete' lewat softDelete().
 *
 * Kolom audit legacy (created_at, updated_at, updated_by) diisi otomatis sesuai MasterDefinition::$auditColumns:
 * timestamp dalam zona waktu aplikasi (UTC), updated_by = id_pengguna aktor (null untuk proses tanpa login).
 */
class MasterModel extends BaseAuditableModel
{
    public const STATUS_ACTIVE   = '1';
    public const STATUS_INACTIVE = '2';
    public const STATUS_DELETED  = '10';

    protected $returnType     = 'array';
    protected $useSoftDeletes = false;
    protected $dateFormat     = 'datetime';

    /**
     * @var array<string, int|null> nip => id_pengguna (cache per instance)
     */
    private array $actorIds = [];

    public function __construct(private MasterDefinition $definition, ?ConnectionInterface $db = null)
    {
        $this->table         = $definition->table;
        $this->primaryKey    = $definition->primaryKey;
        $this->allowedFields = $definition->columns();
        // PK string yang diinput admin: matikan auto increment supaya CI4 mengirim kolom PK saat insert.
        $this->useAutoIncrement = $definition->autoIncrement;

        $this->createdField  = $definition->hasAudit(MasterDefinition::AUDIT_CREATED_AT) ? MasterDefinition::AUDIT_CREATED_AT : '';
        $this->updatedField  = $definition->hasAudit(MasterDefinition::AUDIT_UPDATED_AT) ? MasterDefinition::AUDIT_UPDATED_AT : '';
        $this->useTimestamps = $this->createdField !== '' || $this->updatedField !== '';

        parent::__construct($db);

        if ($definition->hasAudit(MasterDefinition::AUDIT_UPDATED_BY)) {
            $this->beforeInsert[] = 'stampUpdatedBy';
            $this->beforeUpdate[] = 'stampUpdatedBy';
        }
    }

    public function definition(): MasterDefinition
    {
        return $this->definition;
    }

    /**
     * Soft delete: status → 10 (+ deleted_at) tanpa menghapus baris, audit dicatat sebagai event 'delete'.
     */
    public function softDelete(string $id): void
    {
        $before = $this->fetchRows([$id])[0] ?? null;
        $data   = [MasterDefinition::STATUS_FIELD => self::STATUS_DELETED];

        if ($this->definition->hasAudit(MasterDefinition::AUDIT_DELETED_AT)) {
            $data[MasterDefinition::AUDIT_DELETED_AT] = Time::now()->toDateTimeString();
        }

        $this->auditEnabled = false;

        try {
            $this->update($id, $data);
        } finally {
            $this->auditEnabled = true;
        }

        $after = $this->fetchRows([$id])[0] ?? null;
        $this->writeAudit(AuditLogModel::EVENT_DELETE, $id, $before, $after);
    }

    /**
     * Model event beforeInsert/beforeUpdate: isi updated_by dengan id_pengguna aktor (kolom legacy INT).
     *
     * @param array<string, mixed> $eventData
     *
     * @return array<string, mixed>
     */
    public function stampUpdatedBy(array $eventData): array
    {
        if (isset($eventData['data']) && is_array($eventData['data'])) {
            $eventData['data'][MasterDefinition::AUDIT_UPDATED_BY] = $this->currentActorId();
        }

        return $eventData;
    }

    private function currentActorId(): ?int
    {
        $nip = $this->currentActorNip();

        if ($nip === null || $nip === '') {
            return null;
        }

        if (! array_key_exists($nip, $this->actorIds)) {
            /** @var array<string, mixed>|null $row */
            $row = $this->db->table('pengguna')->select('id_pengguna')->where('nip', $nip)->get()->getRowArray();

            $this->actorIds[$nip] = $row === null ? null : (int) $row['id_pengguna'];
        }

        return $this->actorIds[$nip];
    }
}
