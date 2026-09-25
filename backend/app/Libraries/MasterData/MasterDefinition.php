<?php

declare(strict_types=1);

namespace App\Libraries\MasterData;

use LogicException;

/**
 * Definisi satu master (immutable), dibangun dari entri Config\MasterData::$entities.
 */
final class MasterDefinition
{
    public const ORDER_FIELD  = 'order';
    public const STATUS_FIELD = 'status';

    /**
     * Kolom audit legacy yang dikenali engine (diisi otomatis oleh MasterModel bila ada di $auditColumns).
     * Tabel yang punya created_by (FAQ, DBV-002) mengikuti legacy: created_by diisi saat insert, updated_by hanya saat
     * update. Tabel tanpa created_by (Batch 1) mengisi updated_by saat insert maupun update.
     */
    public const AUDIT_CREATED_AT = 'created_at';
    public const AUDIT_CREATED_BY = 'created_by';
    public const AUDIT_UPDATED_AT = 'updated_at';
    public const AUDIT_UPDATED_BY = 'updated_by';
    public const AUDIT_DELETED_AT = 'deleted_at';

    /**
     * Instance hook (dibuat sekali saat pertama dipakai).
     */
    private ?MasterHooks $hooksInstance = null;

    /**
     * @param list<MasterField> $fields        kolom tambahan di luar kode/nama/induk/order/status
     * @param list<string>      $extraSearch   kolom tambahan yang ikut dicari (selain kode & nama)
     * @param int|null          $idDigits      kode wajib tepat N digit angka (kode wilayah legacy: 2/4/7/10)
     * @param list<string>      $uniqueScope   kolom tambahan pembentuk lingkup keunikan nama (selain induk),
     *                                         mis. jenis_status unik per status_pegawai
     * @param list<string>      $auditColumns  kolom audit legacy yang ada di tabel (lihat konstanta AUDIT_*)
     * @param string|null       $hooks         nama kelas hook tulis (implementasi MasterHooks: sanitasi/kolom turunan)
     * @param list<string>      $listExclude   kolom yang tidak dikirim di daftar admin (hemat payload, mis. LONGTEXT);
     *                                         detail satu entri tetap mengirimnya
     * @param bool              $publicOptions dropdown `{key}/options` terbuka untuk semua role login (UL_ALL); false =
     *                                         hanya role 1, untuk master yang dropdown-nya hanya dipakai form admin dan
     *                                         bisa membocorkan entri tersembunyi (mis. FAQ, U3)
     * @param list<string>      $hiddenColumns kolom tabel yang tidak dikelola engine dan tidak pernah dikirim di respons
     *                                         admin mana pun (daftar, detail, hasil tulis), mis. `icon` topik FAQ (D6)
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $controller,
        public readonly string $table,
        public readonly string $primaryKey,
        public readonly int $idMaxLength,
        public readonly string $nameField,
        public readonly string $nameLabel,
        public readonly int $nameMaxLength,
        public readonly ?string $parentField = null,
        public readonly ?string $parentEntity = null,
        public readonly bool $autoIncrement = false,
        public readonly bool $hasOrder = true,
        public readonly bool $hasStatus = true,
        public readonly array $fields = [],
        public readonly array $extraSearch = [],
        public readonly ?int $idDigits = null,
        public readonly array $uniqueScope = [],
        public readonly array $auditColumns = [],
        public readonly ?string $hooks = null,
        public readonly array $listExclude = [],
        public readonly bool $publicOptions = true,
        public readonly array $hiddenColumns = [],
    ) {
    }

    /**
     * @param array{
     *     label: string, controller: string, table: string, primaryKey: string, idMaxLength?: int,
     *     nameField: string, nameLabel: string, nameMaxLength: int,
     *     parent?: array{field: string, entity: string}|null,
     *     autoIncrement?: bool, hasOrder?: bool, hasStatus?: bool,
     *     fields?: array<string, array{label: string, type?: string, required?: bool, rules?: string, options?: array<string|int, string>, hint?: string, maxBytes?: int}>,
     *     extraSearch?: list<string>,
     *     idDigits?: int,
     *     uniqueScope?: list<string>,
     *     auditColumns?: list<string>,
     *     hooks?: class-string<MasterHooks>,
     *     listExclude?: list<string>,
     *     publicOptions?: bool,
     *     hiddenColumns?: list<string>
     * } $config
     */
    public static function fromConfig(string $key, array $config): self
    {
        $fields = [];

        foreach ($config['fields'] ?? [] as $name => $field) {
            $fields[] = MasterField::fromConfig($name, $field);
        }

        return new self(
            key: $key,
            label: $config['label'],
            controller: $config['controller'],
            table: $config['table'],
            primaryKey: $config['primaryKey'],
            idMaxLength: $config['idMaxLength'] ?? $config['idDigits'] ?? 11,
            nameField: $config['nameField'],
            nameLabel: $config['nameLabel'],
            nameMaxLength: $config['nameMaxLength'],
            parentField: $config['parent']['field'] ?? null,
            parentEntity: $config['parent']['entity'] ?? null,
            autoIncrement: $config['autoIncrement'] ?? false,
            hasOrder: $config['hasOrder'] ?? true,
            hasStatus: $config['hasStatus'] ?? true,
            fields: $fields,
            extraSearch: $config['extraSearch'] ?? [],
            idDigits: $config['idDigits'] ?? null,
            uniqueScope: $config['uniqueScope'] ?? [],
            auditColumns: $config['auditColumns'] ?? [],
            hooks: $config['hooks'] ?? null,
            listExclude: $config['listExclude'] ?? [],
            publicOptions: $config['publicOptions'] ?? true,
            hiddenColumns: $config['hiddenColumns'] ?? [],
        );
    }

    public function hasParent(): bool
    {
        return $this->parentField !== null && $this->parentEntity !== null;
    }

    public function hasAudit(string $column): bool
    {
        return in_array($column, $this->auditColumns, true);
    }

    public function field(string $name): ?MasterField
    {
        foreach ($this->fields as $field) {
            if ($field->name === $name) {
                return $field;
            }
        }

        return null;
    }

    /**
     * Hook tulis master ini (null bila tidak ada). Kelas yang tidak mengimplementasi MasterHooks = salah konfigurasi.
     */
    public function hooks(): ?MasterHooks
    {
        if ($this->hooks === null) {
            return null;
        }

        if ($this->hooksInstance === null) {
            $class = $this->hooks;

            if (! is_a($class, MasterHooks::class, true)) {
                throw new LogicException("Hook master {$this->key} ({$class}) harus mengimplementasi " . MasterHooks::class . '.');
            }

            $this->hooksInstance = new $class();
        }

        return $this->hooksInstance;
    }

    /**
     * Kolom yang dipilih di daftar admin bila ada listExclude/hiddenColumns (null = semua kolom, perilaku bawaan):
     * kolom definisi + kolom audit, dikurangi listExclude dan hiddenColumns.
     *
     * @return list<string>|null
     */
    public function listColumns(): ?array
    {
        if ($this->listExclude === [] && $this->hiddenColumns === []) {
            return null;
        }

        $columns = array_unique([...$this->columns(), ...$this->auditColumns]);

        return array_values(array_diff($columns, $this->listExclude, $this->hiddenColumns));
    }

    /**
     * Buang kolom hiddenColumns dari baris yang akan dikirim ke klien.
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    public function withoutHiddenColumns(array $row): array
    {
        return $this->hiddenColumns === [] ? $row : array_diff_key($row, array_flip($this->hiddenColumns));
    }

    /**
     * Kolom yang boleh ditulis lewat Model (allowedFields). created_at/created_by/updated_at/updated_by diisi
     * MasterModel dan kolom turunan diisi hook — keduanya tidak pernah diambil dari input.
     *
     * @return list<string>
     */
    public function columns(): array
    {
        $columns = [$this->primaryKey];

        if ($this->parentField !== null) {
            $columns[] = $this->parentField;
        }

        $columns[] = $this->nameField;

        foreach ($this->fields as $field) {
            $columns[] = $field->name;
        }

        foreach ($this->hooks()?->derivedColumns() ?? [] as $column) {
            $columns[] = $column;
        }

        if ($this->hasOrder) {
            $columns[] = self::ORDER_FIELD;
        }

        if ($this->hasStatus) {
            $columns[] = self::STATUS_FIELD;
        }

        if ($this->hasAudit(self::AUDIT_DELETED_AT)) {
            $columns[] = self::AUDIT_DELETED_AT;
        }

        return $columns;
    }

    /**
     * Metadata untuk frontend (form & tabel generik). snake_case end-to-end (ADR-023).
     *
     * @return array<string, mixed>
     */
    public function toMeta(): array
    {
        return [
            'key'             => $this->key,
            'label'           => $this->label,
            'primary_key'     => $this->primaryKey,
            'id_max_length'   => $this->idMaxLength,
            'id_digits'       => $this->idDigits,
            'auto_increment'  => $this->autoIncrement,
            'name_field'      => $this->nameField,
            'name_label'      => $this->nameLabel,
            'name_max_length' => $this->nameMaxLength,
            'has_order'       => $this->hasOrder,
            'has_status'      => $this->hasStatus,
            'parent'          => $this->hasParent() ? ['field' => $this->parentField, 'entity' => $this->parentEntity] : null,
            'fields'          => array_map(static fn (MasterField $f): array => $f->toMeta(), $this->fields),
        ];
    }
}
