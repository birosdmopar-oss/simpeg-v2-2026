<?php

declare(strict_types=1);

namespace Tests\Support\Controllers;

use App\Controllers\Api\MasterData\BaseMasterController;

/**
 * Controller master UJI — KHUSUS test engine (CR-009). Rutenya hanya ada saat test memasang config tiruan lewat
 * Tests\Support\MasterUjiTestTrait::useMasterUji(); tidak pernah terdaftar di Config\MasterData.
 */
class MasterUjiController extends BaseMasterController
{
    protected array $entities = ['uji-level', 'uji-diklat', 'uji-bidang', 'uji-jurusan', 'uji-kantor', 'uji-dusun'];
}
