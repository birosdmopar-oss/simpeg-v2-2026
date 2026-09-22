<?php

declare(strict_types=1);

namespace App\Libraries\MasterData;

use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Libraries\CacheService;
use App\Models\MasterData\MasterModel;
use Closure;
use CodeIgniter\Database\BaseConnection;
use Throwable;

/**
 * Engine CRUD master generik Modul G (G-02 s.d. G-10) — menegakkan seluruh G-TC di satu tempat:
 *
 *  1. Keunikan: kode (PK) unik; nama unik per induk (case-insensitive, termasuk entri non-aktif) → 422.
 *  2. Soft-delete only: DELETE = status '0' (audit event 'delete'); baris tidak pernah dihapus, sehingga relasi
 *     dari data pegawai/riwayat tetap utuh. FK ON DELETE RESTRICT menjadi lapis kedua di DB.
 *  3. Toggle status langsung ter-reflect: cache dropdown (options) di-invalidate di setiap penulisan.
 *  4. Re-ordering: memindah 1 entri ke posisi N menggeser entri lain dalam induk yang sama; urutan dinormalisasi
 *     1..n agar dropdown selalu urut logis.
 *  5. RBAC ditegakkan di routing (role 1; options = UL_ALL).
 *  6. Audit: seluruh tulis lewat MasterModel (BaseAuditableModel).
 *
 * Semua penulisan dibungkus transaksi: pergeseran urutan + audit ikut rollback kalau gagal.
 */
class MasterService
{
    private const PER_PAGE_DEFAULT = 20;
    private const PER_PAGE_MAX     = 100;
    private const OPTIONS_TTL      = 3600;

    /**
     * Segmen URL yang dipakai routing di level yang sama dengan kode entri — tidak boleh jadi kode.
     */
    private const RESERVED_IDS = ['options', 'meta'];

    /**
     * @var array<string, MasterModel>
     */
    private array $models = [];

    private BaseConnection $db;

    public function __construct(
        private MasterRegistry $registry,
        private CacheService $cache,
        ?BaseConnection $db = null,
    ) {
        $this->db = $db ?? db_connect();
    }

    public function registry(): MasterRegistry
    {
        return $this->registry;
    }

    // ------------------------------------------------------------------
    // Read
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $filters search, status, parent, page, per_page
     *
     * @return array{items: list<array<string, mixed>>, total: int, page: int, per_page: int}
     */
    public function list(MasterDefinition $def, array $filters = []): array
    {
        $page    = max(1, (int) ($filters['page'] ?? 1));
        $perPage = min(self::PER_PAGE_MAX, max(1, (int) ($filters['per_page'] ?? self::PER_PAGE_DEFAULT)));

        $builder = $this->db->table($def->table);

        if (isset($filters['status']) && in_array((string) $filters['status'], ['0', '1'], true)) {
            $builder->where(MasterDefinition::STATUS_FIELD, (string) $filters['status']);
        }

        if ($def->parentField !== null && isset($filters['parent']) && $filters['parent'] !== '') {
            $builder->where($def->parentField, (string) $filters['parent']);
        }

        if (isset($filters['search']) && trim((string) $filters['search']) !== '') {
            $search = trim((string) $filters['search']);
            $builder->groupStart()->like($def->nameField, $search)->orLike($def->primaryKey, $search)->groupEnd();
        }

        $total = (clone $builder)->countAllResults();

        if ($def->parentField !== null) {
            $builder->orderBy($def->parentField, 'ASC');
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = $builder
            ->orderBy(MasterDefinition::ORDER_FIELD, 'ASC')
            ->orderBy($def->nameField, 'ASC')
            ->limit($perPage, ($page - 1) * $perPage)
            ->get()
            ->getResultArray();

        return [
            'items'    => $this->present($def, $rows),
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function get(MasterDefinition $def, string $id): array
    {
        return $this->present($def, [$this->findOrFail($def, $id)])[0];
    }

    /**
     * Pilihan dropdown untuk modul lain: HANYA entri aktif, urut `order` lalu nama. Di-cache per master+induk
     * dan di-invalidate setiap kali master tersebut ditulis (G-TC #3).
     *
     * @return list<array{id: string, nama: string, parent: string|null}>
     */
    public function options(MasterDefinition $def, ?string $parent = null): array
    {
        $parent = $parent === '' ? null : $parent;
        $key    = $this->optionsCacheKey($def, $parent);

        /** @var list<array{id: string, nama: string, parent: string|null}> $options */
        $options = $this->cache->remember($key, self::OPTIONS_TTL, function () use ($def, $parent): array {
            $builder = $this->db->table($def->table)->where(MasterDefinition::STATUS_FIELD, MasterModel::STATUS_ACTIVE);

            if ($def->parentField !== null && $parent !== null) {
                $builder->where($def->parentField, $parent);
            }

            /** @var list<array<string, mixed>> $rows */
            $rows = $builder
                ->orderBy(MasterDefinition::ORDER_FIELD, 'ASC')
                ->orderBy($def->nameField, 'ASC')
                ->get()
                ->getResultArray();

            return array_map(static fn (array $row): array => [
                'id'     => (string) $row[$def->primaryKey],
                'nama'   => (string) $row[$def->nameField],
                'parent' => $def->parentField !== null ? (string) $row[$def->parentField] : null,
            ], $rows);
        });

        return $options;
    }

    // ------------------------------------------------------------------
    // Write
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $data kode (PK), nama, induk (kalau ada), order? (posisi 1..n), status?
     *
     * @return array<string, mixed>
     */
    public function create(MasterDefinition $def, array $data): array
    {
        $id     = trim((string) ($data[$def->primaryKey] ?? ''));
        $name   = $this->normalizeName($data[$def->nameField] ?? '');
        $parent = $def->parentField !== null ? trim((string) ($data[$def->parentField] ?? '')) : null;

        if (in_array(strtolower($id), self::RESERVED_IDS, true)) {
            throw ValidationException::forField($def->primaryKey, 'Kode tidak boleh memakai kata yang dicadangkan sistem.');
        }

        if ($this->exists($def, $id)) {
            throw ValidationException::forField($def->primaryKey, "Kode {$id} sudah dipakai.");
        }

        if ($def->hasParent()) {
            $this->assertParentUsable($def, (string) $parent);
        }

        $this->assertNameUnique($def, $name, $parent);

        $this->transactional(function () use ($def, $id, $name, $parent, $data): void {
            $row = [
                $def->primaryKey               => $id,
                $def->nameField                => $name,
                MasterDefinition::ORDER_FIELD  => $this->nextOrder($def, $parent),
                MasterDefinition::STATUS_FIELD => $this->normalizeStatus($data[MasterDefinition::STATUS_FIELD] ?? MasterModel::STATUS_ACTIVE),
            ];

            if ($def->parentField !== null) {
                $row[$def->parentField] = $parent;
            }

            $this->model($def)->insert($row);

            if (isset($data[MasterDefinition::ORDER_FIELD]) && $data[MasterDefinition::ORDER_FIELD] !== '') {
                $this->placeAt($def, $id, (int) $data[MasterDefinition::ORDER_FIELD]);
            }
        });

        $this->invalidate($def);

        return $this->get($def, $id);
    }

    /**
     * Kode (PK) tidak bisa diubah — dipakai sebagai referensi oleh data lain.
     *
     * @param array<string, mixed> $data nama?, induk?, order?, status?
     *
     * @return array<string, mixed>
     */
    public function update(MasterDefinition $def, string $id, array $data): array
    {
        $current = $this->findOrFail($def, $id);
        $changes = [];

        $parent        = $def->parentField !== null ? (string) $current[$def->parentField] : null;
        $parentChanged = false;

        if ($def->parentField !== null && array_key_exists($def->parentField, $data)) {
            $newParent = trim((string) $data[$def->parentField]);

            if ($newParent !== $parent) {
                $this->assertParentUsable($def, $newParent);
                $parent                     = $newParent;
                $parentChanged              = true;
                $changes[$def->parentField] = $newParent;
            }
        }

        $name = (string) $current[$def->nameField];

        if (array_key_exists($def->nameField, $data)) {
            $name = $this->normalizeName($data[$def->nameField]);

            if ($name !== (string) $current[$def->nameField]) {
                $changes[$def->nameField] = $name;
            }
        }

        if (isset($changes[$def->nameField]) || $parentChanged) {
            $this->assertNameUnique($def, $name, $parent, $id);
        }

        if (array_key_exists(MasterDefinition::STATUS_FIELD, $data)) {
            $status = $this->normalizeStatus($data[MasterDefinition::STATUS_FIELD]);

            if ($status !== (string) $current[MasterDefinition::STATUS_FIELD]) {
                $changes[MasterDefinition::STATUS_FIELD] = $status;
            }
        }

        $this->transactional(function () use ($def, $id, $current, $changes, $parentChanged, $data): void {
            if ($parentChanged) {
                // Pindah induk: taruh di akhir induk baru, rapikan urutan induk lama.
                $changes[MasterDefinition::ORDER_FIELD] = $this->nextOrder($def, (string) $changes[$def->parentField]);
            }

            if ($changes !== []) {
                $this->model($def)->update($id, $changes);
            }

            if ($parentChanged && $def->parentField !== null) {
                $this->renumber($def, (string) $current[$def->parentField]);
            }

            if (isset($data[MasterDefinition::ORDER_FIELD]) && $data[MasterDefinition::ORDER_FIELD] !== '') {
                $this->placeAt($def, $id, (int) $data[MasterDefinition::ORDER_FIELD]);
            }
        });

        $this->invalidate($def);

        return $this->get($def, $id);
    }

    /**
     * Aktif/non-aktifkan (toggle switch). Non-aktif = hilang dari dropdown modul lain, data relasi tetap utuh.
     *
     * @return array<string, mixed>
     */
    public function setStatus(MasterDefinition $def, string $id, string $status): array
    {
        $current = $this->findOrFail($def, $id);
        $status  = $this->normalizeStatus($status);

        if ((string) $current[MasterDefinition::STATUS_FIELD] !== $status) {
            $this->model($def)->update($id, [MasterDefinition::STATUS_FIELD => $status]);
            $this->invalidate($def);
        }

        return $this->get($def, $id);
    }

    /**
     * "Hapus" master = soft delete (status '0'), TIDAK PERNAH hard delete (G-TC #2). Audit event 'delete'.
     *
     * @return array<string, mixed>
     */
    public function delete(MasterDefinition $def, string $id): array
    {
        $current = $this->findOrFail($def, $id);

        if ((string) $current[MasterDefinition::STATUS_FIELD] !== MasterModel::STATUS_INACTIVE) {
            $this->model($def)->softDelete($id);
            $this->invalidate($def);
        }

        return $this->get($def, $id);
    }

    /**
     * Pindahkan entri ke posisi $position (1-based) dalam induknya; entri lain ikut bergeser (G-TC #4).
     *
     * @return array<string, mixed>
     */
    public function reorder(MasterDefinition $def, string $id, int $position): array
    {
        $this->findOrFail($def, $id);

        $this->transactional(function () use ($def, $id, $position): void {
            $this->placeAt($def, $id, $position);
        });

        $this->invalidate($def);

        return $this->get($def, $id);
    }

    // ------------------------------------------------------------------
    // Ordering
    // ------------------------------------------------------------------

    /**
     * Susun ulang urutan dalam satu induk: entri $id ditaruh di posisi $position, sisanya bergeser,
     * lalu seluruhnya dinomori ulang 1..n. Hanya baris yang berubah yang ditulis (lewat Model → teraudit).
     */
    private function placeAt(MasterDefinition $def, string $id, int $position): void
    {
        $row    = $this->findOrFail($def, $id);
        $parent = $def->parentField !== null ? (string) $row[$def->parentField] : null;

        $ids = array_values(array_filter(
            array_keys($this->scopeOrders($def, $parent)),
            static fn (string $other): bool => $other !== $id,
        ));

        $position = max(1, min($position, count($ids) + 1));
        array_splice($ids, $position - 1, 0, [$id]);

        $this->applyOrder($def, $parent, $ids);
    }

    private function renumber(MasterDefinition $def, ?string $parent): void
    {
        $this->applyOrder($def, $parent, array_keys($this->scopeOrders($def, $parent)));
    }

    /**
     * @param list<string> $ids urutan final
     */
    private function applyOrder(MasterDefinition $def, ?string $parent, array $ids): void
    {
        $current = $this->scopeOrders($def, $parent);
        $model   = $this->model($def);

        foreach ($ids as $index => $id) {
            $order = $index + 1;

            if (($current[$id] ?? null) !== $order) {
                $model->update($id, [MasterDefinition::ORDER_FIELD => $order]);
            }
        }
    }

    /**
     * Urutan saat ini dalam satu induk (termasuk non-aktif), terurut seperti tampilan.
     *
     * @return array<string, int> id => order
     */
    private function scopeOrders(MasterDefinition $def, ?string $parent): array
    {
        $builder = $this->db->table($def->table)->select([$def->primaryKey, MasterDefinition::ORDER_FIELD]);

        if ($def->parentField !== null) {
            $builder->where($def->parentField, $parent);
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = $builder
            ->orderBy(MasterDefinition::ORDER_FIELD, 'ASC')
            ->orderBy($def->nameField, 'ASC')
            ->get()
            ->getResultArray();

        $orders = [];

        foreach ($rows as $row) {
            $orders[(string) $row[$def->primaryKey]] = (int) $row[MasterDefinition::ORDER_FIELD];
        }

        return $orders;
    }

    private function nextOrder(MasterDefinition $def, ?string $parent): int
    {
        $builder = $this->db->table($def->table)->selectMax(MasterDefinition::ORDER_FIELD, 'max_order');

        if ($def->parentField !== null) {
            $builder->where($def->parentField, $parent);
        }

        $row = $builder->get()->getRowArray();

        return (int) ($row['max_order'] ?? 0) + 1;
    }

    // ------------------------------------------------------------------
    // Validasi & helper
    // ------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function findOrFail(MasterDefinition $def, string $id): array
    {
        /** @var array<string, mixed>|null $row */
        $row = $this->db->table($def->table)->where($def->primaryKey, $id)->get()->getRowArray();

        if ($row === null) {
            throw new NotFoundException("{$def->label} {$id} tidak ditemukan.");
        }

        return $row;
    }

    private function exists(MasterDefinition $def, string $id): bool
    {
        return $this->db->table($def->table)->where($def->primaryKey, $id)->countAllResults() > 0;
    }

    /**
     * Induk wajib ada dan aktif (entri baru tidak boleh digantung di induk yang sudah non-aktif).
     */
    private function assertParentUsable(MasterDefinition $def, string $parentId): void
    {
        if ($def->parentField === null || $def->parentEntity === null) {
            return;
        }

        $parentDef = $this->registry->get($def->parentEntity);

        /** @var array<string, mixed>|null $parent */
        $parent = $this->db->table($parentDef->table)->where($parentDef->primaryKey, $parentId)->get()->getRowArray();

        if ($parent === null) {
            throw ValidationException::forField($def->parentField, "{$parentDef->label} tidak ditemukan.");
        }

        if ((string) $parent[MasterDefinition::STATUS_FIELD] !== MasterModel::STATUS_ACTIVE) {
            throw ValidationException::forField($def->parentField, "{$parentDef->label} {$parent[$parentDef->nameField]} sedang non-aktif.");
        }
    }

    /**
     * Nama unik per induk. Collation *_ci → perbandingan case-insensitive. Entri non-aktif ikut dicek:
     * master yang "dihapus" diaktifkan kembali, bukan dibuat ganda.
     */
    private function assertNameUnique(MasterDefinition $def, string $name, ?string $parent, ?string $exceptId = null): void
    {
        $builder = $this->db->table($def->table)->where($def->nameField, $name);

        if ($def->parentField !== null) {
            $builder->where($def->parentField, $parent);
        }

        if ($exceptId !== null) {
            $builder->where("{$def->primaryKey} !=", $exceptId);
        }

        /** @var array<string, mixed>|null $duplicate */
        $duplicate = $builder->get()->getRowArray();

        if ($duplicate === null) {
            return;
        }

        $hint = (string) $duplicate[MasterDefinition::STATUS_FIELD] === MasterModel::STATUS_INACTIVE
            ? ' (non-aktif — aktifkan kembali entri tersebut)'
            : '';

        throw ValidationException::forField(
            $def->nameField,
            "{$def->nameLabel} \"{$name}\" sudah ada dengan kode {$duplicate[$def->primaryKey]}{$hint}.",
        );
    }

    private function normalizeName(mixed $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', (string) $value));
    }

    private function normalizeStatus(mixed $value): string
    {
        return (string) $value === MasterModel::STATUS_INACTIVE ? MasterModel::STATUS_INACTIVE : MasterModel::STATUS_ACTIVE;
    }

    /**
     * Tambah nama induk (untuk tampilan tabel) tanpa JOIN: satu query per halaman.
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array<string, mixed>>
     */
    private function present(MasterDefinition $def, array $rows): array
    {
        if ($def->parentField === null || $def->parentEntity === null || $rows === []) {
            return $rows;
        }

        $parentDef = $this->registry->get($def->parentEntity);
        $parentIds = array_values(array_unique(array_map(static fn (array $r): string => (string) $r[$def->parentField], $rows)));

        /** @var list<array<string, mixed>> $parents */
        $parents = $this->db->table($parentDef->table)
            ->select([$parentDef->primaryKey, $parentDef->nameField])
            ->whereIn($parentDef->primaryKey, $parentIds)
            ->get()
            ->getResultArray();

        $names = [];

        foreach ($parents as $p) {
            $names[(string) $p[$parentDef->primaryKey]] = (string) $p[$parentDef->nameField];
        }

        return array_map(static function (array $row) use ($def, $names): array {
            $row['parent_nama'] = $names[(string) $row[$def->parentField]] ?? null;

            return $row;
        }, $rows);
    }

    private function model(MasterDefinition $def): MasterModel
    {
        return $this->models[$def->key] ??= new MasterModel($def, $this->db);
    }

    private function optionsCacheKey(MasterDefinition $def, ?string $parent): string
    {
        $suffix = $parent === null ? 'all' : 'p_' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $parent);

        return "master_opt_{$def->key}_{$suffix}";
    }

    private function invalidate(MasterDefinition $def): void
    {
        $this->cache->invalidateMatching("master_opt_{$def->key}_*");
    }

    private function transactional(Closure $work): void
    {
        $this->db->transBegin();

        try {
            $work();
        } catch (Throwable $e) {
            $this->db->transRollback();

            throw $e;
        }

        $this->db->transCommit();
    }
}
