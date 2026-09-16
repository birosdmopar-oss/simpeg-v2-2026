<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;
use Throwable;

/**
 * BaseAuditableModel (F0-04) — ADR-011/ADR-012.
 *
 * Setiap model bisnis yang extends class ini otomatis menulis row ke audit_logs
 * (before/after JSON) pada event insert, update, dan delete lewat CI4 Model Events.
 * Konsekuensi: seluruh operasi tulis WAJIB lewat Model (bukan raw Query Builder), kalau tidak
 * event tidak ter-trigger dan audit harus ditulis manual lewat $this->writeAudit().
 *
 * Kegagalan menulis audit TIDAK membatalkan transaksi bisnis (fail-open + log error).
 * Ini asumsi dari Tech Spec F0-04 [P] yang masih menunggu konfirmasi Tech Lead.
 */
abstract class BaseAuditableModel extends Model
{
    /**
     * Matikan audit untuk model tertentu (mis. tabel log yang sangat sering ditulis).
     */
    protected bool $auditEnabled = true;

    /**
     * Snapshot row sebelum update/delete, di-key oleh primary key.
     *
     * @var array<int|string, array<string, mixed>>
     */
    private array $auditBefore = [];

    private ?AuditLogModel $auditLog = null;

    public function __construct(...$args)
    {
        parent::__construct(...$args);

        $this->beforeUpdate[] = 'auditCaptureBefore';
        $this->beforeDelete[] = 'auditCaptureBefore';
        $this->afterInsert[]  = 'auditAfterInsert';
        $this->afterUpdate[]  = 'auditAfterUpdate';
        $this->afterDelete[]  = 'auditAfterDelete';
    }

    // ------------------------------------------------------------------
    // Model event handlers (public agar bisa dipanggil trigger())
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $eventData
     *
     * @return array<string, mixed>
     */
    public function auditCaptureBefore(array $eventData): array
    {
        $this->auditBefore = [];

        if (! $this->auditEnabled) {
            return $eventData;
        }

        $ids = $this->normalizeIds($eventData['id'] ?? null);

        if ($ids === []) {
            // Update/delete berbasis where() tanpa id: state "before" tidak bisa ditentukan per-row.
            return $eventData;
        }

        foreach ($this->fetchRows($ids) as $row) {
            $this->auditBefore[$this->rowKey($row)] = $row;
        }

        return $eventData;
    }

    /**
     * @param array<string, mixed> $eventData
     *
     * @return array<string, mixed>
     */
    public function auditAfterInsert(array $eventData): array
    {
        if (! $this->auditEnabled || ($eventData['result'] ?? false) === false) {
            return $eventData;
        }

        $id = $eventData['id'] ?? null;

        if ($id === null || $id === 0 || $id === '') {
            return $eventData;
        }

        $rows  = $this->fetchRows([$id]);
        $after = $rows[0] ?? $this->toArray($eventData['data'] ?? []);

        $this->writeAudit(AuditLogModel::EVENT_CREATE, $id, null, $after);

        return $eventData;
    }

    /**
     * @param array<string, mixed> $eventData
     *
     * @return array<string, mixed>
     */
    public function auditAfterUpdate(array $eventData): array
    {
        if (! $this->auditEnabled || ($eventData['result'] ?? false) === false) {
            return $eventData;
        }

        $ids = $this->normalizeIds($eventData['id'] ?? null);

        if ($ids === []) {
            $ids = array_keys($this->auditBefore);
        }

        foreach ($this->fetchRows($ids) as $after) {
            $key = $this->rowKey($after);
            $this->writeAudit(AuditLogModel::EVENT_UPDATE, $key, $this->auditBefore[$key] ?? null, $after);
        }

        $this->auditBefore = [];

        return $eventData;
    }

    /**
     * @param array<string, mixed> $eventData
     *
     * @return array<string, mixed>
     */
    public function auditAfterDelete(array $eventData): array
    {
        if (! $this->auditEnabled || ($eventData['result'] ?? false) === false) {
            return $eventData;
        }

        $ids = array_keys($this->auditBefore);

        if ($ids === []) {
            $ids = $this->normalizeIds($eventData['id'] ?? null);
        }

        // Soft delete: row masih ada (deleted_at terisi) -> after = row; hard delete -> after = null.
        $remaining = [];

        foreach ($this->fetchRows($ids) as $row) {
            $remaining[$this->rowKey($row)] = $row;
        }

        foreach ($ids as $id) {
            $before = $this->auditBefore[$id] ?? null;
            $after  = $remaining[$id] ?? null;

            if ($before === null && $after === null) {
                continue;
            }

            $this->writeAudit(AuditLogModel::EVENT_DELETE, $id, $before, $after);
        }

        $this->auditBefore = [];

        return $eventData;
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Tulis audit secara manual — dipakai kalau operasi tulis terpaksa bypass Model Events
     * (mis. BaseSnapshotModel::syncToActiveSnapshot yang memakai raw query builder).
     *
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     */
    public function writeAudit(string $event, string|int $entityId, ?array $before, ?array $after, ?string $entity = null): void
    {
        try {
            $this->auditLogModel()->record(
                $entity ?? $this->table,
                $entityId,
                $event,
                $before,
                $after,
                $this->currentActorNip(),
            );
        } catch (Throwable $e) {
            // Fail-open: audit gagal tidak boleh menggagalkan transaksi bisnis utama.
            log_message('error', '[audit_logs] gagal menulis audit {entity}#{id} ({event}): {msg}', [
                'entity' => $entity ?? $this->table,
                'id'     => (string) $entityId,
                'event'  => $event,
                'msg'    => $e->getMessage(),
            ]);
        }
    }

    protected function currentActorNip(): ?string
    {
        try {
            return service('authContext')->nip();
        } catch (Throwable) {
            return null;
        }
    }

    protected function auditLogModel(): AuditLogModel
    {
        return $this->auditLog ??= new AuditLogModel($this->db);
    }

    /**
     * Ambil row mentah (termasuk yang soft-deleted) berdasarkan primary key.
     *
     * @param list<int|string> $ids
     *
     * @return list<array<string, mixed>>
     */
    protected function fetchRows(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = $this->db->table($this->table)
            ->whereIn($this->primaryKey, $ids)
            ->get()
            ->getResultArray();

        return $rows;
    }

    /**
     * @return list<int|string>
     */
    private function normalizeIds(mixed $ids): array
    {
        if ($ids === null || $ids === [] || $ids === '' || $ids === 0) {
            return [];
        }

        $list = is_array($ids) ? array_values($ids) : [$ids];

        return array_values(array_filter($list, static fn ($v) => $v !== null && $v !== ''));
    }

    /**
     * @param array<string, mixed> $row
     */
    private function rowKey(array $row): int|string
    {
        $key = $row[$this->primaryKey] ?? '';

        return is_numeric($key) ? (int) $key : (string) $key;
    }

    /**
     * @return array<string, mixed>
     */
    private function toArray(mixed $data): array
    {
        if (is_array($data)) {
            return $data;
        }

        if (is_object($data)) {
            return method_exists($data, 'toRawArray') ? $data->toRawArray() : get_object_vars($data);
        }

        return [];
    }
}
