<?php

declare(strict_types=1);

namespace App\Libraries\MasterData;

use App\Exceptions\NotFoundException;
use Config\MasterData as MasterDataConfig;
use LogicException;

/**
 * Registry definisi master (dari Config\MasterData). Dipakai service, controller, dan routing.
 *
 * Konfigurasi diperiksa saat registry dibangun (CR-009): rujukan antar-master (induk, field ref + dependsOn), field
 * yang disebut orderScope/uniqueFields/filters, dan rantai induk yang melingkar. Salah konfigurasi = LogicException,
 * sehingga ketahuan di test pertama, bukan saat data sudah tertulis.
 */
class MasterRegistry
{
    /**
     * @var array<string, MasterDefinition>
     */
    private array $definitions = [];

    public function __construct(MasterDataConfig $config)
    {
        foreach ($config->entities as $key => $entity) {
            $this->definitions[$key] = MasterDefinition::fromConfig($key, $entity);
        }

        foreach ($this->definitions as $definition) {
            $this->assertConsistent($definition);
        }
    }

    public function has(string $key): bool
    {
        return isset($this->definitions[$key]);
    }

    /**
     * @throws NotFoundException master tidak terdaftar
     */
    public function get(string $key): MasterDefinition
    {
        return $this->definitions[$key] ?? throw new NotFoundException('Master data tidak ditemukan.');
    }

    /**
     * @return array<string, MasterDefinition>
     */
    public function all(): array
    {
        return $this->definitions;
    }

    /**
     * Master yang menjadi anak langsung dari $key (untuk validasi relasi).
     *
     * @return list<MasterDefinition>
     */
    public function childrenOf(string $key): array
    {
        return array_values(array_filter(
            $this->definitions,
            static fn (MasterDefinition $d): bool => $d->parentEntity === $key,
        ));
    }

    /**
     * Key seluruh leluhur $def (induk langsung dulu). Rantai melingkar sudah ditolak saat registry dibangun.
     *
     * @return list<string>
     */
    public function ancestorsOf(MasterDefinition $def): array
    {
        $keys  = [];
        $level = $def;

        while ($level->hasParent() && isset($this->definitions[(string) $level->parentEntity])) {
            $keys[] = (string) $level->parentEntity;
            $level  = $this->definitions[(string) $level->parentEntity];

            if (count($keys) > count($this->definitions)) {
                break;
            }
        }

        return $keys;
    }

    private function assertConsistent(MasterDefinition $def): void
    {
        $fail = static function (string $message) use ($def): never {
            throw new LogicException("Konfigurasi master {$def->key} tidak valid: {$message}");
        };

        if ($def->parentEntity !== null && ! $this->has($def->parentEntity)) {
            $fail("induk {$def->parentEntity} tidak terdaftar.");
        }

        // Rantai induk melingkar (a → b → a) membuat cek rantai aktif & options statusChain tidak berujung.
        $seen  = [$def->key];
        $level = $def;

        while ($level->hasParent()) {
            $next = (string) $level->parentEntity;

            if (in_array($next, $seen, true)) {
                $fail('rantai induk melingkar (' . implode(' → ', [...$seen, $next]) . ').');
            }

            // Induk leluhur yang tidak terdaftar dilaporkan saat definisinya sendiri diperiksa.
            if (! isset($this->definitions[$next])) {
                break;
            }

            $seen[] = $next;
            $level  = $this->definitions[$next];
        }

        if ($def->statusChain && ! $def->hasParent()) {
            $fail('statusChain hanya untuk master berinduk.');
        }

        foreach ($def->fields as $field) {
            if ($field->type !== MasterField::TYPE_REF) {
                continue;
            }

            if (! $this->has((string) $field->entity)) {
                $fail("field ref {$field->name} merujuk master {$field->entity} yang tidak terdaftar.");
            }

            if ($field->dependsOn === null) {
                continue;
            }

            $depends = $def->field($field->dependsOn);

            if ($depends === null || $depends->type !== MasterField::TYPE_REF) {
                $fail("dependsOn field {$field->name} harus field ref lain di master yang sama.");
            }

            // Entri rujukan disaring per nilai dependsOn lewat `{entity}/options?parent=`, jadi induk master rujukan
            // harus master yang dirujuk field dependsOn.
            if ($this->definitions[(string) $field->entity]->parentEntity !== $depends->entity) {
                $fail("dependsOn field {$field->name}: induk master {$field->entity} bukan {$depends->entity}.");
            }
        }

        $fieldNames = array_map(static fn (MasterField $f): string => $f->name, $def->fields);

        if ($def->orderScope !== [] && ! $def->hasOrder) {
            $fail('orderScope hanya untuk master yang memakai kolom order.');
        }

        foreach ($def->orderScope as $column) {
            // Wajib: nilai lingkup harus selalu ada di payload tambah (default DB tidak terlihat oleh penomoran urutan).
            if ($def->field($column)?->required !== true) {
                $fail("orderScope {$column} harus field wajib (required) master ini.");
            }
        }

        foreach ($def->uniqueFields as $column => $scope) {
            if (! in_array($column, $fieldNames, true)) {
                $fail("uniqueFields {$column} bukan field master ini.");
            }

            foreach ($scope as $scopeColumn) {
                if ($scopeColumn !== $def->parentField && ! in_array($scopeColumn, $fieldNames, true)) {
                    $fail("lingkup uniqueFields {$column} ({$scopeColumn}) bukan field/induk master ini.");
                }
            }
        }

        foreach ($def->filters as $column) {
            if (! in_array($column, $fieldNames, true) || in_array($column, MasterDefinition::RESERVED_QUERY, true)) {
                $fail("filter {$column} harus field master ini dan bukan parameter query engine.");
            }
        }
    }
}
