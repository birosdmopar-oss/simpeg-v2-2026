<?php

declare(strict_types=1);

namespace App\Libraries\MasterData;

/**
 * Definisi satu master (immutable), dibangun dari entri Config\MasterData::$entities.
 */
final class MasterDefinition
{
    public const ORDER_FIELD  = 'order';
    public const STATUS_FIELD = 'status';

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
    ) {
    }

    /**
     * @param array{
     *     label: string, controller: string, table: string, primaryKey: string, idMaxLength: int,
     *     nameField: string, nameLabel: string, nameMaxLength: int,
     *     parent: array{field: string, entity: string}|null
     * } $config
     */
    public static function fromConfig(string $key, array $config): self
    {
        return new self(
            key: $key,
            label: $config['label'],
            controller: $config['controller'],
            table: $config['table'],
            primaryKey: $config['primaryKey'],
            idMaxLength: $config['idMaxLength'],
            nameField: $config['nameField'],
            nameLabel: $config['nameLabel'],
            nameMaxLength: $config['nameMaxLength'],
            parentField: $config['parent']['field'] ?? null,
            parentEntity: $config['parent']['entity'] ?? null,
        );
    }

    public function hasParent(): bool
    {
        return $this->parentField !== null && $this->parentEntity !== null;
    }

    /**
     * Kolom yang boleh ditulis lewat Model (allowedFields).
     *
     * @return list<string>
     */
    public function columns(): array
    {
        $columns = [$this->primaryKey];

        if ($this->parentField !== null) {
            $columns[] = $this->parentField;
        }

        return [...$columns, $this->nameField, self::ORDER_FIELD, self::STATUS_FIELD];
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
            'name_field'      => $this->nameField,
            'name_label'      => $this->nameLabel,
            'name_max_length' => $this->nameMaxLength,
            'parent'          => $this->hasParent() ? ['field' => $this->parentField, 'entity' => $this->parentEntity] : null,
        ];
    }
}
