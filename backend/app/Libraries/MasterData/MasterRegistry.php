<?php

declare(strict_types=1);

namespace App\Libraries\MasterData;

use App\Exceptions\NotFoundException;
use Config\MasterData as MasterDataConfig;

/**
 * Registry definisi master (dari Config\MasterData). Dipakai service, controller, dan routing.
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
}
