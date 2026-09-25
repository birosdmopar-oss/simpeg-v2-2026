<?php

declare(strict_types=1);

namespace App\Libraries\MasterData;

use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Libraries\CacheService;
use App\Models\MasterData\MasterModel;
use Closure;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Exceptions\DatabaseException;
use Throwable;

/**
 * Engine CRUD master generik Modul G (G-02 s.d. G-10) — menegakkan seluruh G-TC di satu tempat:
 *
 *  1. Keunikan: kode (PK) unik; nama unik per induk (+ kolom uniqueScope), case-insensitive, termasuk entri
 *     non-aktif & dihapus → 422. Lapis kedua di DB: UNIQUE index (DBV-001 / ISSUE-009). Field lain yang ber-UNIQUE
 *     (opsi uniqueFields, CR-009) dicek dengan cara yang sama → 422 pada field itu.
 *  2. Soft-delete only: DELETE = status 10 'Dihapus' (audit event 'delete'); baris tidak pernah dihapus, sehingga
 *     relasi dari data pegawai/riwayat tetap utuh. FK ON DELETE RESTRICT menjadi lapis kedua di DB.
 *     Status mengikuti legacy: 1 Aktif, 2 Tidak Aktif, 10 Dihapus. Daftar default menyembunyikan status 10;
 *     entri yang dihapus bisa dipulihkan lewat setStatus (1/2).
 *  3. Toggle status langsung ter-reflect: cache dropdown (options) di-invalidate di setiap penulisan (termasuk
 *     dropdown turunan ber-statusChain, CR-009).
 *  4. Re-ordering (mode shift, bawaan): memindah 1 entri ke posisi N menggeser entri lain dalam lingkup urutan yang
 *     sama (induk + orderScope); urutan dinormalisasi 1..n agar dropdown selalu urut logis. Entri baru dengan `order`
 *     langsung di-insert di posisi finalnya. Saudara yang hanya tergeser tidak di-stamp updated_at/updated_by (hanya
 *     entri yang diedit), tetapi tetap teraudit. Mode manual (CR-009, mis. level pangkat): `order` disimpan apa
 *     adanya, entri lain tidak pernah digeser/dinomori ulang.
 *  5. RBAC ditegakkan di routing (role 1; options = UL_ALL, kecuali master ber-publicOptions false = role 1).
 *  6. Audit: seluruh tulis lewat MasterModel (BaseAuditableModel).
 *  7. Induk: entri baru / pindah induk hanya boleh di rantai yang SELURUH levelnya ada dan aktif (DBV-002 E6). Field
 *     ref (rujukan ke master lain, CR-009) diperiksa serupa: kanonik, ada, aktif (bila berubah), dan konsisten dengan
 *     field dependsOn-nya.
 *  8. Kolom turunan & sanitasi lewat hook per master (MasterHooks, DBV-002 E2), mis. isi artikel FAQ; kolom besar
 *     bisa dikecualikan dari daftar admin (listExclude, E4); kolom yang tidak dikelola tidak pernah dikirim
 *     (hiddenColumns, mis. `icon` topik FAQ).
 *
 * Mendukung dua bentuk kode sesuai DDL legacy: PK string yang diinput admin (kode wilayah CHAR(2/4/7/10), wajib
 * tepat N digit) dan PK AUTO_INCREMENT (agama, jenis_pegawai, jenis_status). Kolom tambahan legacy per master
 * (kd_area, kd_pos, status_pegawai, …) didefinisikan sebagai MasterField.
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
     * @param array<string, mixed> $filters search, status, parent, page, per_page, + kolom opsi `filters` (allowlist)
     *
     * @return array{items: list<array<string, mixed>>, total: int, page: int, per_page: int}
     */
    public function list(MasterDefinition $def, array $filters = []): array
    {
        $page    = max(1, (int) ($filters['page'] ?? 1));
        $perPage = min(self::PER_PAGE_MAX, max(1, (int) ($filters['per_page'] ?? self::PER_PAGE_DEFAULT)));

        $builder = $this->db->table($def->table);

        // Kolom besar (mis. LONGTEXT isi artikel) tidak dikirim di daftar; detail (get) tetap mengirim semua kolom.
        $columns = $def->listColumns();

        if ($columns !== null) {
            $builder->select($columns);
        }

        if ($def->hasStatus) {
            $status = (string) ($filters['status'] ?? '');

            if (in_array($status, [MasterModel::STATUS_ACTIVE, MasterModel::STATUS_INACTIVE, MasterModel::STATUS_DELETED], true)) {
                $builder->where(MasterDefinition::STATUS_FIELD, $status);
            } else {
                // Default seperti legacy (status != 10): entri yang dihapus tidak tampil kecuali difilter.
                $builder->where(MasterDefinition::STATUS_FIELD . ' !=', MasterModel::STATUS_DELETED);
            }
        }

        if ($def->parentField !== null && isset($filters['parent']) && $filters['parent'] !== '') {
            $builder->where($def->parentField, (string) $filters['parent']);
        }

        foreach ($this->filterValues($def, $filters) as $column => $value) {
            $builder->where($column, $value);
        }

        if (isset($filters['search']) && trim((string) $filters['search']) !== '') {
            $search = trim((string) $filters['search']);
            $builder->groupStart()->like($def->nameField, $search)->orLike($def->primaryKey, $search);

            foreach ($def->extraSearch as $column) {
                $builder->orLike($column, $search);
            }

            $builder->groupEnd();
        }

        $total = (clone $builder)->countAllResults();

        if ($def->parentField !== null) {
            $builder->orderBy($def->parentField, 'ASC');
        }

        // Lingkup urutan (mis. jenis diklat) dikelompokkan sebelum `order`, sama dengan penomoran urutannya.
        foreach ($def->orderScope as $column) {
            $builder->orderBy($column, 'ASC');
        }

        if ($def->hasOrder) {
            $builder->orderBy(MasterDefinition::ORDER_FIELD, 'ASC');
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = $builder
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
     * Pilihan dropdown untuk modul lain: HANYA entri aktif (master ber-statusChain: seluruh rantai induknya juga aktif),
     * urut lingkup urutan, `order`, lalu nama. Bisa disaring induk (`parent`) dan kolom allowlist opsi `filters`
     * (mis. `?cpns=1`; parameter lain diabaikan). Di-cache per master+induk+filter dan di-invalidate setiap kali master
     * tersebut (atau leluhurnya, untuk statusChain) ditulis (G-TC #3).
     *
     * @param array<string, mixed> $query parameter query options (kolom filter; kunci lain diabaikan)
     *
     * @return list<array{id: string, nama: string, parent: string|null}>
     */
    public function options(MasterDefinition $def, ?string $parent = null, array $query = []): array
    {
        $parent  = $parent === '' ? null : $parent;
        $filters = $this->filterValues($def, $query);
        $key     = $this->optionsCacheKey($def, $parent, $filters);

        /** @var list<array{id: string, nama: string, parent: string|null}> $options */
        $options = $this->cache->remember($key, self::OPTIONS_TTL, function () use ($def, $parent, $filters): array {
            // Hanya kolom yang dipetakan ke hasil: kolom besar (mis. LONGTEXT isi artikel) tidak ikut terbaca.
            $columns = [$def->primaryKey, $def->nameField];

            if ($def->parentField !== null) {
                $columns[] = $def->parentField;
            }

            $builder = $this->db->table($def->table)->select($columns);

            if ($def->statusChain) {
                $this->whereActiveChain($builder, $def);
            } elseif ($def->hasStatus) {
                $builder->where(MasterDefinition::STATUS_FIELD, MasterModel::STATUS_ACTIVE);
            }

            if ($def->parentField !== null && $parent !== null) {
                $builder->where($def->parentField, $parent);
            }

            foreach ($filters as $column => $value) {
                $builder->where($column, $value);
            }

            foreach ($def->orderScope as $column) {
                $builder->orderBy($column, 'ASC');
            }

            if ($def->hasOrder) {
                $builder->orderBy(MasterDefinition::ORDER_FIELD, 'ASC');
            }

            /** @var list<array<string, mixed>> $rows */
            $rows = $builder->orderBy($def->nameField, 'ASC')->get()->getResultArray();

            return array_map(static fn (array $row): array => [
                'id'     => (string) $row[$def->primaryKey],
                'nama'   => (string) $row[$def->nameField],
                'parent' => $def->parentField !== null ? (string) $row[$def->parentField] : null,
            ], $rows);
        });

        return $options;
    }

    /**
     * Batasi $builder (query atas tabel $def) ke entri yang tampil menurut rantai status (pola U3 FAQ, CR-009): status
     * entri itu 1 DAN seluruh leluhurnya status 1 (subquery bertingkat, tanpa JOIN). Dipakai options master
     * ber-statusChain; service khusus (mis. dropdown pendidikan per jenjang, DBV-004 E5) bisa memakainya untuk tampilan
     * yang sama. Kolom tidak diberi alias, jadi $builder tidak boleh JOIN tabel lain yang punya kolom status/induk.
     */
    public function whereActiveChain(BaseBuilder $builder, MasterDefinition $def): BaseBuilder
    {
        if ($def->hasStatus) {
            $builder->where(MasterDefinition::STATUS_FIELD, MasterModel::STATUS_ACTIVE);
        }

        if ($def->hasParent()) {
            $parentDef = $this->registry->get((string) $def->parentEntity);

            $builder->whereIn(
                (string) $def->parentField,
                fn (BaseBuilder $sub): BaseBuilder => $this->whereActiveChain($sub->select($parentDef->primaryKey)->from($parentDef->table), $parentDef),
            );
        }

        return $builder;
    }

    // ------------------------------------------------------------------
    // Write
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $data kode (kalau bukan auto increment), nama, induk, field tambahan, order?, status?
     *
     * @return array<string, mixed>
     */
    public function create(MasterDefinition $def, array $data): array
    {
        $id     = trim((string) ($data[$def->primaryKey] ?? ''));
        $name   = $this->normalizeName($data[$def->nameField] ?? '');
        $parent = $def->parentField !== null ? trim((string) ($data[$def->parentField] ?? '')) : null;

        if (! $def->autoIncrement) {
            if (in_array(strtolower($id), self::RESERVED_IDS, true)) {
                throw ValidationException::forField($def->primaryKey, 'Kode tidak boleh memakai kata yang dicadangkan sistem.');
            }

            if ($this->exists($def, $id)) {
                throw ValidationException::forField($def->primaryKey, "Kode {$id} sudah dipakai.");
            }
        }

        if ($def->hasParent()) {
            $this->assertParentUsable($def, (string) $parent);
        }

        $scope = $this->scopeValues($def, $data);
        $this->assertNameUnique($def, $name, $parent, $scope);

        // Baris dasar (kode, induk, nama, field tambahan ternormalisasi). order/status/hook ditambahkan di transaksi.
        $row = [$def->nameField => $name];

        if (! $def->autoIncrement) {
            $row[$def->primaryKey] = $id;
        }

        if ($def->parentField !== null) {
            $row[$def->parentField] = $parent;
        }

        foreach ($def->fields as $field) {
            if (array_key_exists($field->name, $data)) {
                $row[$field->name] = $field->normalize($data[$field->name]);
            }
        }

        $this->assertRefsUsable($def, $row, null);
        $this->assertFieldsUnique($def, $row, null);

        $newId     = $id;
        $requested = $this->requestedOrder($def, $data);

        // Closure biasa (bukan arrow fn) supaya $newId dari AUTO_INCREMENT ikut keluar lewat referensi.
        $this->translateDuplicate(function () use ($def, $row, $data, $requested, &$newId): void {
            $this->transactional(function () use ($def, $row, $data, $requested, &$newId): void {
                if ($def->hasOrder) {
                    $orderScope = $this->orderScopeValues($def, $row);

                    if ($def->isManualOrder()) {
                        // Mode manual: nilai urutan disimpan apa adanya (dibatasi tipe kolom, juga divalidasi controller).
                        if ($requested !== null) {
                            $this->assertOrderFits($def, $requested);
                        }

                        $row[MasterDefinition::ORDER_FIELD] = $requested ?? $this->nextOrder($def, $orderScope);
                    } else {
                        // `order` dikirim: posisi final dihitung SEBELUM insert (dijepit 1..jumlah saudara tampil + 1),
                        // jadi baris baru tidak di-update lagi sesudahnya — updated_by tetap NULL untuk tabel
                        // ber-created_by (E1) dan tanpa audit 'update' tambahan. Hanya saudara yang digeser (placeAt).
                        $row[MasterDefinition::ORDER_FIELD] = $requested === null
                            ? $this->nextOrder($def, $orderScope)
                            : $this->clampPosition($requested, count($this->scopeIds($def, $orderScope)));
                    }
                }

                if ($def->hasStatus) {
                    $row[MasterDefinition::STATUS_FIELD] = $this->normalizeStatus($data[MasterDefinition::STATUS_FIELD] ?? MasterModel::STATUS_ACTIVE);
                }

                $row = $this->applyHooks($def, $row, null);

                $model = $this->model($def);
                $model->insert($row);

                if ($def->autoIncrement) {
                    $newId = (string) $model->getInsertID();
                }

                if ($requested !== null && ! $def->isManualOrder()) {
                    $this->placeAt($def, $newId, $requested, false);
                }
            });
        }, function () use ($def, $id, $name, $parent, $scope, $row): void {
            if (! $def->autoIncrement && $this->exists($def, $id)) {
                throw ValidationException::forField($def->primaryKey, "Kode {$id} sudah dipakai.");
            }

            $this->assertNameUnique($def, $name, $parent, $scope);
            $this->assertFieldsUnique($def, $row, null);
        });

        $this->invalidate($def);

        return $this->get($def, $newId);
    }

    /**
     * Kode (PK) tidak bisa diubah — dipakai sebagai referensi oleh data lain.
     *
     * @param array<string, mixed> $data nama?, induk?, field tambahan?, order?, status?
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

        foreach ($def->fields as $field) {
            if (array_key_exists($field->name, $data)) {
                $value = $field->normalize($data[$field->name]);

                if ((string) $value !== (string) ($current[$field->name] ?? '')) {
                    $changes[$field->name] = $value;
                }
            }
        }

        $changedColumns = array_keys($changes);
        $scopeChanged   = array_intersect($def->uniqueScope, $changedColumns) !== [];

        if (isset($changes[$def->nameField]) || $parentChanged || $scopeChanged) {
            $this->assertNameUnique($def, $name, $parent, $this->scopeValues($def, $changes + $current), $id);
        }

        // Field ref & field ber-UNIQUE hanya diperiksa bila nilainya (atau dependsOn/lingkupnya) berubah (E6).
        $this->assertRefsUsable($def, $changes + $current, $changedColumns);
        $this->assertFieldsUnique($def, $changes + $current, $changedColumns, $id);

        $rawStatus = $data[MasterDefinition::STATUS_FIELD] ?? null;

        if ($def->hasStatus && is_scalar($rawStatus) && trim((string) $rawStatus) !== '') {
            $status = $this->normalizeStatus($data[MasterDefinition::STATUS_FIELD]);

            if ($status !== (string) $current[MasterDefinition::STATUS_FIELD]) {
                $changes = $this->statusChanges($def, $current, $status) + $changes;
            }
        }

        $requested = $this->requestedOrder($def, $data);

        if ($requested !== null && $def->hasStatus && (string) ($changes + $current)[MasterDefinition::STATUS_FIELD] === MasterModel::STATUS_DELETED) {
            throw ValidationException::forField(MasterDefinition::ORDER_FIELD, "{$def->label} yang dihapus tidak punya urutan tampil — pulihkan dulu.");
        }

        $scope = $this->scopeValues($def, $changes + $current);

        // Pindah lingkup urutan (induk atau kolom orderScope, mis. jenis diklat berganti).
        $oldOrderScope   = $this->orderScopeValues($def, $current);
        $newOrderScope   = $this->orderScopeValues($def, $changes + $current);
        $orderScopeMoved = $def->hasOrder && $newOrderScope !== $oldOrderScope;
        $final           = $changes + $current;

        $this->translateDuplicate(fn () => $this->transactional(function () use ($def, $id, $current, $changes, $requested, $oldOrderScope, $newOrderScope, $orderScopeMoved): void {
            if ($def->isManualOrder()) {
                // Mode manual: nilai urutan diganti langsung (di-stamp seperti ubah biasa), entri lain tidak digeser.
                if ($requested !== null && (string) $requested !== (string) $current[MasterDefinition::ORDER_FIELD]) {
                    $this->assertOrderFits($def, $requested);
                    $changes[MasterDefinition::ORDER_FIELD] = $requested;
                }
            } elseif ($orderScopeMoved) {
                // Pindah induk/lingkup: taruh di akhir lingkup baru, rapikan urutan lingkup lama.
                $changes[MasterDefinition::ORDER_FIELD] = $this->nextOrder($def, $newOrderScope);
            }

            $changes = $this->applyHooks($def, $changes, $current);

            if ($changes !== []) {
                $this->model($def)->update($id, $changes);
            }

            if ($def->isManualOrder()) {
                return;
            }

            if ($orderScopeMoved) {
                $this->renumber($def, $oldOrderScope);
            }

            if ($requested !== null) {
                $this->placeAt($def, $id, $requested, true);
            }
        }), function () use ($def, $name, $parent, $scope, $final, $id): void {
            $this->assertNameUnique($def, $name, $parent, $scope, $id);
            $this->assertFieldsUnique($def, $final, null, $id);
        });

        $this->invalidate($def);

        return $this->get($def, $id);
    }

    /**
     * Aktif (1) / Tidak Aktif (2) — toggle switch. Tidak aktif = hilang dari dropdown modul lain, data relasi tetap
     * utuh. Dipakai juga untuk memulihkan entri yang dihapus (status 10).
     *
     * @return array<string, mixed>
     */
    public function setStatus(MasterDefinition $def, string $id, string $status): array
    {
        $current = $this->findOrFail($def, $id);
        $this->assertHasStatus($def);
        $status = $this->normalizeStatus($status);

        if ((string) $current[MasterDefinition::STATUS_FIELD] !== $status) {
            $this->model($def)->update($id, $this->statusChanges($def, $current, $status));
            $this->invalidate($def);
        }

        return $this->get($def, $id);
    }

    /**
     * "Hapus" master = soft delete (status 10), TIDAK PERNAH hard delete (G-TC #2). Audit event 'delete'.
     *
     * @return array<string, mixed>
     */
    public function delete(MasterDefinition $def, string $id): array
    {
        $current = $this->findOrFail($def, $id);
        $this->assertHasStatus($def);

        if ((string) $current[MasterDefinition::STATUS_FIELD] !== MasterModel::STATUS_DELETED) {
            $this->transactional(function () use ($def, $id, $current): void {
                $this->model($def)->softDelete($id);

                // Entri yang dihapus keluar dari urutan tampil: rapatkan urutan sisanya 1..n (mode manual: nilai urutan
                // entri lain adalah data bisnis, tidak disentuh).
                if ($def->hasOrder && ! $def->isManualOrder()) {
                    $this->renumber($def, $this->orderScopeValues($def, $current));
                }
            });
            $this->invalidate($def);
        }

        return $this->get($def, $id);
    }

    /**
     * Pindahkan entri ke posisi $position (1-based) dalam lingkup urutannya; entri lain ikut bergeser (G-TC #4).
     * Mode manual: `order` entri itu diganti menjadi $position, entri lain tidak berubah.
     *
     * @return array<string, mixed>
     */
    public function reorder(MasterDefinition $def, string $id, int $position): array
    {
        $current = $this->findOrFail($def, $id);

        if (! $def->hasOrder) {
            throw new ValidationException("{$def->label} tidak memakai urutan tampil.");
        }

        if ($def->hasStatus && (string) $current[MasterDefinition::STATUS_FIELD] === MasterModel::STATUS_DELETED) {
            throw ValidationException::forField(MasterDefinition::ORDER_FIELD, "{$def->label} yang dihapus tidak punya urutan tampil — pulihkan dulu.");
        }

        if ($def->isManualOrder()) {
            if ($position < 1 || $position > $def->orderMax()) {
                throw ValidationException::forField(MasterDefinition::ORDER_FIELD, 'Urutan minimal 1 dan maksimal ' . number_format($def->orderMax(), 0, ',', '.') . '.');
            }

            if ((string) $current[MasterDefinition::ORDER_FIELD] !== (string) $position) {
                $this->model($def)->update($id, [MasterDefinition::ORDER_FIELD => $position]);
                $this->invalidate($def);
            }

            return $this->get($def, $id);
        }

        $this->transactional(function () use ($def, $id, $position): void {
            $this->placeAt($def, $id, $position, true);
        });

        $this->invalidate($def);

        return $this->get($def, $id);
    }

    // ------------------------------------------------------------------
    // Ordering
    // ------------------------------------------------------------------

    /**
     * Susun ulang urutan dalam satu lingkup urutan (induk + orderScope): entri $id ditaruh di posisi $position, sisanya
     * bergeser, lalu seluruhnya dinomori ulang 1..n. Hanya baris yang berubah yang ditulis (teraudit). Mode shift saja.
     *
     * @param bool $stamp true = $id sedang diedit admin (reorder/ubah), sehingga perubahan order-nya di-stamp
     *                    updated_at/updated_by seperti ubah biasa; false = entri baru yang sudah di-insert di posisinya
     */
    private function placeAt(MasterDefinition $def, string $id, int $position, bool $stamp): void
    {
        $row   = $this->findOrFail($def, $id);
        $scope = $this->orderScopeValues($def, $row);

        $ids = array_values(array_filter(
            $this->scopeIds($def, $scope),
            static fn (string $other): bool => $other !== $id,
        ));

        array_splice($ids, $this->clampPosition($position, count($ids)) - 1, 0, [$id]);

        $this->applyOrder($def, $scope, $ids, $stamp ? $id : null);
    }

    /**
     * @param array<string, string|null> $scope nilai kolom lingkup urutan
     */
    private function renumber(MasterDefinition $def, array $scope): void
    {
        $this->applyOrder($def, $scope, $this->scopeIds($def, $scope));
    }

    /**
     * Tulis urutan final. Baris $editedId (yang sedang diedit admin) ditulis lewat update Model biasa sehingga
     * di-stamp updated_at/updated_by. Saudara yang hanya tergeser ditulis lewat MasterModel::shiftOrder(): kolom audit
     * legacy-nya tidak berubah (legacy tidak me-renumber saudara; mis. tanggal "Diperbarui" artikel FAQ tidak boleh
     * bergeser hanya karena urutan), tetapi perubahan order tetap tercatat di audit_logs.
     *
     * @param array<string, string|null> $scope nilai kolom lingkup urutan
     * @param list<string>               $ids   urutan final
     */
    private function applyOrder(MasterDefinition $def, array $scope, array $ids, ?string $editedId = null): void
    {
        $this->assertOrderFits($def, count($ids));

        $current = $this->scopeOrders($def, $scope);
        $model   = $this->model($def);

        foreach ($ids as $index => $id) {
            $order = $index + 1;

            if (($current[$id] ?? null) === $order) {
                continue;
            }

            if ($id === $editedId) {
                $model->update($id, [MasterDefinition::ORDER_FIELD => $order]);
            } else {
                $model->shiftOrder($id, $order);
            }
        }
    }

    /**
     * Posisi 1-based yang dijepit ke 1..(jumlah saudara tampil + 1).
     */
    private function clampPosition(int $position, int $siblings): int
    {
        return max(1, min($position, $siblings + 1));
    }

    /**
     * `order` yang dikirim di payload tambah/ubah (null = tidak dikirim / kosong / master tanpa urutan).
     *
     * @param array<string, mixed> $data
     */
    private function requestedOrder(MasterDefinition $def, array $data): ?int
    {
        $order = $data[MasterDefinition::ORDER_FIELD] ?? null;

        return $def->hasOrder && $order !== null && $order !== '' ? (int) $order : null;
    }

    /**
     * Nilai kolom lingkup urutan (induk + orderScope) sebuah baris, sebagai string (NULL tetap NULL).
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, string|null>
     */
    private function orderScopeValues(MasterDefinition $def, array $row): array
    {
        $scope = [];

        foreach ($def->orderScopeColumns() as $column) {
            $value          = $row[$column] ?? null;
            $scope[$column] = $value === null ? null : (string) $value;
        }

        return $scope;
    }

    /**
     * Batasi query ke satu lingkup urutan (NULL → IS NULL).
     *
     * @param array<string, string|null> $scope
     */
    private function whereOrderScope(BaseBuilder $builder, array $scope): BaseBuilder
    {
        foreach ($scope as $column => $value) {
            $builder->where($column, $value);
        }

        return $builder;
    }

    /**
     * Urutan saat ini dalam satu lingkup urutan (termasuk non-aktif), terurut seperti tampilan.
     *
     * @param array<string, string|null> $scope
     *
     * @return array<string, int> id => order
     */
    private function scopeOrders(MasterDefinition $def, array $scope): array
    {
        $builder = $this->whereOrderScope($this->db->table($def->table)->select([$def->primaryKey, MasterDefinition::ORDER_FIELD]), $scope);

        // Posisi dihitung dari entri yang tampil di daftar default (status 10 disembunyikan), sama dengan FE.
        if ($def->hasStatus) {
            $builder->where(MasterDefinition::STATUS_FIELD . ' !=', MasterModel::STATUS_DELETED);
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

    /**
     * Id dalam urutan tampil sebagai string. array_keys() mengubah key numerik ('3171', '6') menjadi int, yang membuat
     * WHERE PK CHAR dibandingkan dengan angka (index PK tidak terpakai) dan entity_id audit tidak konsisten.
     *
     * @param array<string, string|null> $scope
     *
     * @return list<string>
     */
    private function scopeIds(MasterDefinition $def, array $scope): array
    {
        return array_map('strval', array_keys($this->scopeOrders($def, $scope)));
    }

    /**
     * Urutan berikutnya (MAX+1) dalam satu lingkup urutan, dijaga tidak melewati kapasitas kolom `order`.
     *
     * @param array<string, string|null> $scope
     */
    private function nextOrder(MasterDefinition $def, array $scope): int
    {
        $builder = $this->whereOrderScope($this->db->table($def->table)->selectMax(MasterDefinition::ORDER_FIELD, 'max_order'), $scope);

        if ($def->hasStatus) {
            $builder->where(MasterDefinition::STATUS_FIELD . ' !=', MasterModel::STATUS_DELETED);
        }

        $row  = $builder->get()->getRowArray();
        $next = (int) ($row['max_order'] ?? 0) + 1;

        $this->assertOrderFits($def, $next);

        return $next;
    }

    /**
     * Nilai urutan otomatis tidak boleh melewati batas tipe kolom `order` (orderColumnType): koneksi strict menolaknya
     * (1264 → 500) dan non-strict memotongnya diam-diam (TINYINT → 127, urutan kembar).
     */
    private function assertOrderFits(MasterDefinition $def, int $order): void
    {
        if ($order > $def->orderMax()) {
            throw ValidationException::forField(
                MasterDefinition::ORDER_FIELD,
                "Urutan {$def->label} sudah mencapai batas maksimal " . number_format($def->orderMax(), 0, ',', '.') . '.',
            );
        }
    }

    // ------------------------------------------------------------------
    // Validasi & helper
    // ------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function findOrFail(MasterDefinition $def, string $id): array
    {
        if (! $this->isCanonicalId($def, $id)) {
            throw new NotFoundException("{$def->label} {$id} tidak ditemukan.");
        }

        /** @var array<string, mixed>|null $row */
        $row = $this->db->table($def->table)->where($def->primaryKey, $id)->get()->getRowArray();

        if ($row === null) {
            throw new NotFoundException("{$def->label} {$id} tidak ditemukan.");
        }

        return $row;
    }

    /**
     * Bentuk kode yang sah: AUTO_INCREMENT = bilangan bulat positif tanpa nol di depan; kode wilayah = tepat N digit;
     * kode lain = huruf/angka/titik/strip/garis bawah. Selain itu dianggap tidak ada (404), bukan alias entri lain.
     */
    private function isCanonicalId(MasterDefinition $def, string $id): bool
    {
        $pattern = match (true) {
            $def->autoIncrement     => '/^[1-9][0-9]*\z/',
            $def->idDigits !== null => '/^[0-9]{' . $def->idDigits . '}\z/',
            default                 => '/^[A-Za-z0-9._-]+\z/',
        };

        return preg_match($pattern, $id) === 1;
    }

    private function exists(MasterDefinition $def, string $id): bool
    {
        return $this->db->table($def->table)->where($def->primaryKey, $id)->countAllResults() > 0;
    }

    private function assertHasStatus(MasterDefinition $def): void
    {
        if (! $def->hasStatus) {
            throw new ValidationException("{$def->label} tidak memakai kolom status.");
        }
    }

    /**
     * Induk wajib ada dan aktif — termasuk SELURUH leluhurnya (DBV-002 E6): entri baru / pindah induk tidak boleh
     * digantung di rantai yang salah satu levelnya non-aktif (mis. kecamatan di bawah kabupaten aktif yang
     * provinsinya Tidak Aktif, atau artikel FAQ di sub topik aktif yang topiknya Dihapus). Error dilaporkan pada
     * field induk langsung, dengan nama level yang non-aktif.
     */
    private function assertParentUsable(MasterDefinition $def, string $parentId): void
    {
        $field = $def->parentField;

        if ($field === null) {
            return;
        }

        // Id induk dari input wajib bentuk kanonik (CR-003): untuk PK INT, MySQL meng-cast '01'/'1abc'/'1.0' menjadi 1,
        // sehingga tanpa cek ini id tersebut lolos sebagai induk 1 lalu memicu "pindah induk" palsu (urutan bergeser)
        // atau gagal saat tulis. Level di atasnya dibaca dari DB, jadi sudah kanonik.
        if ($def->parentEntity !== null) {
            $parentDef = $this->registry->get($def->parentEntity);

            if (! $this->isCanonicalId($parentDef, $parentId)) {
                throw ValidationException::forField($field, "{$parentDef->label} tidak ditemukan.");
            }
        }

        $level = $def;
        $id    = $parentId;
        // Pengaman konfigurasi induk melingkar: rantai tidak mungkin lebih panjang dari jumlah master terdaftar.
        $guard = count($this->registry->all());

        while ($level->hasParent() && $guard-- > 0) {
            $parentDef = $this->registry->get((string) $level->parentEntity);

            /** @var array<string, mixed>|null $parent */
            $parent = $this->db->table($parentDef->table)->where($parentDef->primaryKey, $id)->get()->getRowArray();

            if ($parent === null) {
                throw ValidationException::forField($field, "{$parentDef->label} tidak ditemukan.");
            }

            if ($parentDef->hasStatus && (string) $parent[MasterDefinition::STATUS_FIELD] !== MasterModel::STATUS_ACTIVE) {
                throw ValidationException::forField($field, "{$parentDef->label} {$parent[$parentDef->nameField]} sedang non-aktif.");
            }

            if ($parentDef->parentField !== null) {
                $id = (string) $parent[$parentDef->parentField];
            }

            $level = $parentDef;
        }
    }

    /**
     * Field ref (rujukan ke master lain, CR-009): kode wajib bentuk kanonik master rujukan, ada, dan aktif; bila punya
     * dependsOn, entri rujukan wajib berada di bawah nilai field dependsOn (mis. kabupaten/kota milik provinsi yang
     * dipilih). Seperti induk (E6), hanya diperiksa bila nilainya berubah: data lama yang merujuk entri yang kini
     * non-aktif tetap bisa diubah kolom lainnya. Konsistensi dependsOn ikut diperiksa bila field dependsOn berubah.
     *
     * @param array<string, mixed> $values         nilai final (create: baris baru; update: perubahan + baris saat ini)
     * @param list<string>|null    $changedColumns kolom yang berubah (null = create, semua diperiksa)
     */
    private function assertRefsUsable(MasterDefinition $def, array $values, ?array $changedColumns): void
    {
        foreach ($def->refFields() as $field) {
            $selfChanged    = $changedColumns === null || in_array($field->name, $changedColumns, true);
            $dependsChanged = $field->dependsOn !== null && ($changedColumns === null || in_array($field->dependsOn, $changedColumns, true));

            if (! $selfChanged && ! $dependsChanged) {
                continue;
            }

            $value = $values[$field->name] ?? null;

            if ($value === null || $value === '') {
                continue;
            }

            $value  = (string) $value;
            $refDef = $this->registry->get((string) $field->entity);

            if (! $this->isCanonicalId($refDef, $value)) {
                throw ValidationException::forField($field->name, "{$field->label} tidak ditemukan.");
            }

            /** @var array<string, mixed>|null $ref */
            $ref = $this->db->table($refDef->table)->where($refDef->primaryKey, $value)->get()->getRowArray();

            if ($ref === null) {
                throw ValidationException::forField($field->name, "{$field->label} tidak ditemukan.");
            }

            if ($selfChanged && $refDef->hasStatus && (string) $ref[MasterDefinition::STATUS_FIELD] !== MasterModel::STATUS_ACTIVE) {
                throw ValidationException::forField($field->name, "{$field->label} {$ref[$refDef->nameField]} sedang non-aktif.");
            }

            if ($field->dependsOn === null || ! $field->checkDependsOn || $refDef->parentField === null) {
                continue;
            }

            $dependsField = $def->field($field->dependsOn);
            $expected     = $values[$field->dependsOn] ?? null;

            if ($expected === null || $expected === '') {
                throw ValidationException::forField($field->name, 'Pilih ' . ($dependsField->label ?? $field->dependsOn) . ' terlebih dahulu.');
            }

            if ((string) $ref[$refDef->parentField] !== (string) $expected) {
                throw ValidationException::forField(
                    $field->name,
                    "{$field->label} {$ref[$refDef->nameField]} tidak berada di bawah " . ($dependsField->label ?? $field->dependsOn) . ' yang dipilih.',
                );
            }
        }
    }

    /**
     * Nama unik per induk (+ kolom uniqueScope). Collation *_ci → perbandingan case-insensitive. Entri tidak aktif
     * dan yang dihapus ikut dicek (sama dengan UNIQUE index di DB): entri lama dipulihkan, bukan dibuat ganda.
     *
     * @param array<string, string|null> $scope nilai kolom uniqueScope
     */
    private function assertNameUnique(MasterDefinition $def, string $name, ?string $parent, array $scope = [], ?string $exceptId = null): void
    {
        $builder = $this->db->table($def->table)->where($def->nameField, $name);

        if ($def->parentField !== null) {
            $builder->where($def->parentField, $parent);
        }

        foreach ($scope as $column => $value) {
            $builder->where($column, $value);
        }

        if ($exceptId !== null) {
            $builder->where("{$def->primaryKey} !=", $exceptId);
        }

        /** @var array<string, mixed>|null $duplicate */
        $duplicate = $builder->get()->getRowArray();

        if ($duplicate === null) {
            return;
        }

        throw ValidationException::forField(
            $def->nameField,
            "{$def->nameLabel} \"{$name}\" sudah ada dengan kode {$duplicate[$def->primaryKey]}{$this->statusHint($def, $duplicate)}.",
        );
    }

    /**
     * Field ber-UNIQUE selain nama (opsi uniqueFields, CR-009), mis. `old_id` jenis konket: unik global atau per kolom
     * lingkup, case-insensitive (collation *_ci), termasuk entri non-aktif & dihapus — sama dengan UNIQUE index DB.
     * Nilai kosong/NULL tidak dicek (UNIQUE mengizinkan banyak NULL); lingkup yang salah satu kolomnya NULL juga tidak
     * (UNIQUE komposit tidak menegakkan baris ber-NULL). Update: hanya bila field/lingkupnya berubah.
     *
     * @param array<string, mixed> $values         nilai final (ternormalisasi)
     * @param list<string>|null    $changedColumns kolom yang berubah (null = periksa semua)
     */
    private function assertFieldsUnique(MasterDefinition $def, array $values, ?array $changedColumns, ?string $exceptId = null): void
    {
        foreach ($def->uniqueFields as $column => $scopeColumns) {
            if ($changedColumns !== null && array_intersect([$column, ...$scopeColumns], $changedColumns) === []) {
                continue;
            }

            $value = $values[$column] ?? null;

            if ($value === null || $value === '') {
                continue;
            }

            $builder = $this->db->table($def->table)->where($column, $value);

            foreach ($scopeColumns as $scopeColumn) {
                $scopeValue = $values[$scopeColumn] ?? null;

                if ($scopeValue === null || $scopeValue === '') {
                    continue 2;
                }

                $builder->where($scopeColumn, $scopeValue);
            }

            if ($exceptId !== null) {
                $builder->where("{$def->primaryKey} !=", $exceptId);
            }

            /** @var array<string, mixed>|null $duplicate */
            $duplicate = $builder->get()->getRowArray();

            if ($duplicate === null) {
                continue;
            }

            $label = $def->field($column)->label ?? $column;

            throw ValidationException::forField(
                $column,
                "{$label} \"{$value}\" sudah dipakai {$def->nameLabel} \"{$duplicate[$def->nameField]}\" (kode {$duplicate[$def->primaryKey]}){$this->statusHint($def, $duplicate)}.",
            );
        }
    }

    /**
     * Saran tindakan bila entri yang bentrok tidak aktif / sudah dihapus.
     *
     * @param array<string, mixed> $duplicate
     */
    private function statusHint(MasterDefinition $def, array $duplicate): string
    {
        return match ($def->hasStatus ? (string) $duplicate[MasterDefinition::STATUS_FIELD] : '') {
            MasterModel::STATUS_INACTIVE => ' (tidak aktif — aktifkan kembali entri tersebut)',
            MasterModel::STATUS_DELETED  => ' (sudah dihapus — pulihkan entri tersebut lewat filter status Dihapus)',
            default                      => '',
        };
    }

    private function normalizeName(mixed $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', (string) $value));
    }

    /**
     * Nilai kolom lingkup keunikan (uniqueScope), dinormalisasi seperti saat disimpan.
     *
     * @param array<string, mixed> $values
     *
     * @return array<string, string|null>
     */
    private function scopeValues(MasterDefinition $def, array $values): array
    {
        $scope = [];

        foreach ($def->uniqueScope as $column) {
            $field = $def->field($column);
            $value = $values[$column] ?? null;
            $value = $field !== null ? $field->normalize($value) : $value;

            $scope[$column] = $value === null ? null : (string) $value;
        }

        return $scope;
    }

    /**
     * Filter `?kolom=nilai` yang diizinkan (opsi `filters`, allowlist CR-009) dari parameter query options/daftar.
     * Kolom di luar allowlist diabaikan; nilai kosong = tanpa filter; nilai non-teks (mis. `?cpns[]=1`) atau yang tidak
     * sah untuk tipe field-nya (MasterField::acceptsFilterValue) → 422 pada kolom itu.
     *
     * @param array<string, mixed> $query
     *
     * @return array<string, string>
     */
    private function filterValues(MasterDefinition $def, array $query): array
    {
        $filters = [];

        foreach ($def->filters as $column) {
            $raw = $query[$column] ?? null;

            if ($raw === null || $raw === '') {
                continue;
            }

            $field = $def->field($column);
            $value = is_string($raw) || is_int($raw) ? trim((string) $raw) : null;

            if ($field === null || $value === null || $value === '' || ! $field->acceptsFilterValue($value)) {
                throw ValidationException::forField($column, 'Filter ' . ($field->label ?? $column) . ' tidak valid.');
            }

            $filters[$column] = $value;
        }

        ksort($filters);

        return $filters;
    }

    /**
     * Perubahan kolom saat status berganti. Memulihkan entri yang dihapus (10 → 1/2): kosongkan deleted_at dan
     * taruh kembali di akhir urutan lingkupnya (entri terhapus tidak ikut urutan tampil). Mode manual: nilai urutan
     * dipertahankan.
     *
     * @param array<string, mixed> $current
     *
     * @return array<string, mixed>
     */
    private function statusChanges(MasterDefinition $def, array $current, string $status): array
    {
        $changes = [MasterDefinition::STATUS_FIELD => $status];

        if ((string) $current[MasterDefinition::STATUS_FIELD] === MasterModel::STATUS_DELETED) {
            if ($def->hasAudit(MasterDefinition::AUDIT_DELETED_AT)) {
                $changes[MasterDefinition::AUDIT_DELETED_AT] = null;
            }

            if ($def->hasOrder && ! $def->isManualOrder()) {
                $changes[MasterDefinition::ORDER_FIELD] = $this->nextOrder($def, $this->orderScopeValues($def, $current));
            }
        }

        return $changes;
    }

    /**
     * UNIQUE/PRIMARY index DB (1062) adalah lapis kedua keunikan. Kalau dua permintaan balapan lolos cek aplikasi,
     * pelanggaran index (DatabaseException dari MasterModel, transaksi sudah di-rollback) diterjemahkan ulang ke 422
     * lewat $recheck (cek aplikasi diulang: kode, nama, dan field uniqueFields).
     */
    private function translateDuplicate(Closure $work, Closure $recheck): void
    {
        try {
            $work();
        } catch (DatabaseException $e) {
            if ($e->getCode() === 1062) {
                $recheck();
            }

            throw $e;
        }
    }

    /**
     * Status yang boleh diset lewat API: 1 Aktif / 2 Tidak Aktif. Status 10 hanya lewat delete().
     */
    private function normalizeStatus(mixed $value): string
    {
        return (string) $value === MasterModel::STATUS_INACTIVE ? MasterModel::STATUS_INACTIVE : MasterModel::STATUS_ACTIVE;
    }

    /**
     * Bentuk baris untuk respons admin: buang hiddenColumns (kolom yang tidak dikelola, mis. `icon` topik FAQ, D6) dan
     * tambah nama induk (untuk tampilan tabel) tanpa JOIN: satu query per halaman.
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array<string, mixed>>
     */
    private function present(MasterDefinition $def, array $rows): array
    {
        $rows = array_map(static fn (array $row): array => $def->withoutHiddenColumns($row), $rows);

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

    /**
     * Jalankan hook tulis master (MasterHooks) — setelah validasi, sebelum tulis, di dalam transaksi pemanggil.
     *
     * @param array<string, mixed>      $row
     * @param array<string, mixed>|null $existing
     *
     * @return array<string, mixed>
     */
    private function applyHooks(MasterDefinition $def, array $row, ?array $existing): array
    {
        $hooks = $def->hooks();

        return $hooks === null ? $row : $hooks->beforeWrite($row, $existing);
    }

    private function model(MasterDefinition $def): MasterModel
    {
        return $this->models[$def->key] ??= new MasterModel($def, $this->db);
    }

    /**
     * @param array<string, string> $filters filter allowlist (sudah divalidasi & terurut)
     */
    private function optionsCacheKey(MasterDefinition $def, ?string $parent, array $filters = []): string
    {
        $suffix = $parent === null ? 'all' : 'p_' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $parent);

        if ($filters !== []) {
            $suffix .= '_f' . md5((string) json_encode($filters));
        }

        return "master_opt_{$def->key}_{$suffix}";
    }

    /**
     * Invalidasi cache dropdown master ini, plus dropdown master ber-statusChain yang leluhurnya master ini (status
     * leluhur ikut menentukan isinya).
     */
    private function invalidate(MasterDefinition $def): void
    {
        $this->cache->invalidateMatching("master_opt_{$def->key}_*");

        foreach ($this->registry->all() as $other) {
            if ($other->statusChain && in_array($def->key, $this->registry->ancestorsOf($other), true)) {
                $this->cache->invalidateMatching("master_opt_{$other->key}_*");
            }
        }
    }

    /**
     * Tulisan bisnis yang gagal melempar DatabaseException dari MasterModel (insert/update di-override), sehingga
     * seluruh transaksi di-rollback — termasuk 1062 yang lalu diterjemahkan translateDuplicate() ke 422. Tulisan
     * audit tetap fail-open (F0-04): kegagalannya hanya menandai transStatus, yang di-reset agar transaksi berikutnya
     * di koneksi yang sama (transStart/transComplete) tidak ikut rollback.
     */
    private function transactional(Closure $work): void
    {
        $this->db->transBegin();

        try {
            $work();
        } catch (Throwable $e) {
            $this->db->transRollback();
            $this->db->resetTransStatus();

            throw $e;
        }

        $this->db->transCommit();
        $this->db->resetTransStatus();
    }
}
