<?php

declare(strict_types=1);

namespace App\Models;

use App\Interfaces\SyncsToSnapshot;
use RuntimeException;

/**
 * BaseSnapshotModel (F0-05) — ADR-006.
 *
 * Model riwayat_* cukup mendeklarasikan snapshotTable(), snapshotKey(), snapshotFields().
 * Logic sync ditulis sekali di sini. Sync TIDAK di-hook ke event apa pun:
 * insert/update/delete riwayat tidak menyentuh tabel snapshot sampai syncToActiveSnapshot() dipanggil.
 *
 * Karena sync memakai raw query builder (bypass Model Events), audit_logs ditulis manual
 * lewat writeAudit() milik BaseAuditableModel (lihat 00-INDEX.md, aturan lintas fase).
 */
abstract class BaseSnapshotModel extends BaseAuditableModel implements SyncsToSnapshot
{
    abstract public function snapshotTable(): string;

    abstract public function snapshotKey(): string;

    /**
     * @return array<int|string, string>
     */
    abstract public function snapshotFields(): array;

    public function syncToActiveSnapshot(int|string $riwayatId): bool
    {
        $rows = $this->fetchRows([$riwayatId]);

        if ($rows === []) {
            throw new RuntimeException(sprintf('Row %s#%s tidak ditemukan untuk snapshot sync.', $this->table, (string) $riwayatId));
        }

        $riwayat  = $rows[0];
        $key      = $this->snapshotKey();
        $keyValue = $riwayat[$key] ?? null;

        if ($keyValue === null || $keyValue === '') {
            throw new RuntimeException(sprintf("Kolom snapshotKey '%s' kosong pada %s#%s.", $key, $this->table, (string) $riwayatId));
        }

        $data = [$key => $keyValue];

        foreach ($this->snapshotFields() as $from => $to) {
            $source = is_int($from) ? $to : $from;

            if (array_key_exists($source, $riwayat)) {
                $data[$to] = $riwayat[$source];
            }
        }

        $table = $this->snapshotTable();

        /** @var array<string, mixed>|null $before */
        $before = $this->db->table($table)->where($key, $keyValue)->get()->getRowArray();

        $this->db->transStart();

        if ($before === null) {
            $this->db->table($table)->insert($data);
            $event = AuditLogModel::EVENT_CREATE;
        } else {
            $this->db->table($table)->where($key, $keyValue)->update($data);
            $event = AuditLogModel::EVENT_UPDATE;
        }

        $this->db->transComplete();

        if ($this->db->transStatus() === false) {
            return false;
        }

        /** @var array<string, mixed>|null $after */
        $after = $this->db->table($table)->where($key, $keyValue)->get()->getRowArray();

        // Audit manual (raw builder tidak memicu Model Events).
        $this->writeAudit($event, (string) $keyValue, $before, $after, $table);

        return true;
    }
}
