<?php

declare(strict_types=1);

namespace App\Models\MasterData;

use App\Libraries\MasterData\MasterDefinition;
use App\Models\AuditLogModel;
use App\Models\BaseAuditableModel;
use CodeIgniter\Database\ConnectionInterface;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\I18n\Time;

/**
 * Model generik seluruh tabel master Modul G. Dikonfigurasi dari MasterDefinition (tabel, PK, kolom).
 * Turunan BaseAuditableModel → tambah/ubah tercatat otomatis di audit_logs (G-TC audit).
 *
 * Status mengikuti legacy (DBV-001): 1 = Aktif, 2 = Tidak Aktif, 10 = Dihapus. Master TIDAK PERNAH di-hard-delete
 * dari aplikasi (G-TC soft-delete only): "hapus" = status 10 (+ deleted_at bila kolomnya ada), dicatat sebagai
 * event audit 'delete' lewat softDelete().
 *
 * Kolom audit legacy (created_at, created_by, updated_at, updated_by) diisi otomatis sesuai
 * MasterDefinition::$auditColumns: timestamp dalam zona waktu aplikasi (UTC), *_by = id_pengguna aktor (null untuk
 * proses tanpa login). Tabel ber-created_by (FAQ, DBV-002) mengikuti legacy: insert mengisi created_by dan membiarkan
 * updated_by NULL, update mengisi updated_by. Tabel tanpa created_by (Batch 1) mengisi updated_by di insert & update.
 * Saudara yang hanya tergeser urutannya (shiftOrder) tidak di-stamp.
 *
 * Tulisan bisnis gagal = exception (insert()/update() di-override): di dalam transaksi CI4 query yang gagal hanya
 * mengembalikan false + menandai transStatus (tanpa exception, kecuali transException), sehingga tanpa ini
 * pelanggaran UNIQUE ikut "sukses" dan transaksi tetap di-commit. Tulisan audit_logs (AuditLogModel) sengaja tidak
 * ikut: audit tetap fail-open (F0-04).
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

        $hasCreatedBy = $definition->hasAudit(MasterDefinition::AUDIT_CREATED_BY);

        if ($hasCreatedBy) {
            $this->beforeInsert[] = 'stampCreatedBy';
        }

        if ($definition->hasAudit(MasterDefinition::AUDIT_UPDATED_BY)) {
            if (! $hasCreatedBy) {
                $this->beforeInsert[] = 'stampUpdatedBy';
            }

            $this->beforeUpdate[] = 'stampUpdatedBy';
        }
    }

    public function definition(): MasterDefinition
    {
        return $this->definition;
    }

    /**
     * @param array<string, mixed>|object|null $row
     *
     * @throws DatabaseException query gagal (mis. 1062 UNIQUE/PRIMARY), juga saat DBDebug = false
     */
    public function insert($row = null, bool $returnID = true)
    {
        $result = parent::insert($row, $returnID);

        if ($result === false) {
            throw $this->writeFailure();
        }

        return $result;
    }

    /**
     * @param array<int|string>|int|string|null $id
     * @param array<string, mixed>|object|null  $row
     *
     * @throws DatabaseException query gagal (mis. 1062 UNIQUE), juga saat DBDebug = false
     */
    public function update($id = null, $row = null): bool
    {
        if (parent::update($id, $row) === false) {
            throw $this->writeFailure();
        }

        return true;
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
     * Geser `order` satu baris SAUDARA karena entri lain dipindah, ditambah, atau dihapus. Baris ini tidak
     * diedit admin, jadi updated_at/updated_by-nya TIDAK diubah (CR-003; legacy tidak me-renumber saudara, dan tanggal
     * "Diperbarui" artikel FAQ tidak boleh bergeser hanya karena urutan). `updated_at = updated_at` mencegah
     * ON UPDATE CURRENT_TIMESTAMP kolom legacy ikut mengisi jam server. Perubahan tetap dicatat di audit_logs (event
     * 'update', before/after) — ditulis manual karena query ini tidak lewat Model Events.
     *
     * @throws DatabaseException query gagal, juga saat DBDebug = false
     */
    public function shiftOrder(string $id, int $order): void
    {
        $before  = $this->fetchRows([$id])[0] ?? null;
        $builder = $this->db->table($this->table)
            ->set(MasterDefinition::ORDER_FIELD, $order)
            ->where($this->primaryKey, $id);

        if ($this->definition->hasAudit(MasterDefinition::AUDIT_UPDATED_AT)) {
            $builder->set(MasterDefinition::AUDIT_UPDATED_AT, $this->db->escapeIdentifiers(MasterDefinition::AUDIT_UPDATED_AT), false);
        }

        if ($builder->update() === false) {
            throw $this->writeFailure();
        }

        $after = $this->fetchRows([$id])[0] ?? null;
        $this->writeAudit(AuditLogModel::EVENT_UPDATE, $id, $before, $after);
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
        return $this->stampActor($eventData, MasterDefinition::AUDIT_UPDATED_BY);
    }

    /**
     * Model event beforeInsert (tabel ber-created_by): isi created_by dengan id_pengguna aktor (kolom legacy INT).
     *
     * @param array<string, mixed> $eventData
     *
     * @return array<string, mixed>
     */
    public function stampCreatedBy(array $eventData): array
    {
        return $this->stampActor($eventData, MasterDefinition::AUDIT_CREATED_BY);
    }

    /**
     * @param array<string, mixed> $eventData
     *
     * @return array<string, mixed>
     */
    private function stampActor(array $eventData, string $column): array
    {
        if (isset($eventData['data']) && is_array($eventData['data'])) {
            $eventData['data'][$column] = $this->currentActorId();
        }

        return $eventData;
    }

    private function writeFailure(): DatabaseException
    {
        $error = $this->db->error();

        return new DatabaseException('Penulisan master data gagal: ' . $error['message'], (int) $error['code']);
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
