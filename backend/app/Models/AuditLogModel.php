<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

/**
 * Model tabel audit_logs (F0-04). Model biasa (bukan auditable) untuk menghindari rekursi.
 * Dipakai otomatis oleh BaseAuditableModel dan manual oleh BaseSnapshotModel::syncToActiveSnapshot()
 * (sync memakai raw query builder yang mem-bypass Model Events — lihat 00-INDEX.md).
 */
class AuditLogModel extends Model
{
    public const EVENT_CREATE = 'create';
    public const EVENT_UPDATE = 'update';
    public const EVENT_DELETE = 'delete';

    protected $table         = 'audit_logs';
    protected $primaryKey    = 'id_log';
    protected $returnType    = 'array';
    protected $useTimestamps = false;
    protected $allowedFields = ['nip_actor', 'entity', 'entity_id', 'event', 'before_json', 'after_json', 'created_at'];

    /**
     * Tulis satu baris audit.
     *
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     */
    public function record(
        string $entity,
        string|int $entityId,
        string $event,
        ?array $before,
        ?array $after,
        ?string $nipActor = null,
    ): int {
        $flags = JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;

        $this->insert([
            'nip_actor'   => $nipActor,
            'entity'      => $entity,
            'entity_id'   => (string) $entityId,
            'event'       => $event,
            'before_json' => $before === null ? null : json_encode($before, $flags),
            'after_json'  => $after === null ? null : json_encode($after, $flags),
            'created_at'  => date('Y-m-d H:i:s'),
        ]);

        return (int) $this->getInsertID();
    }
}
