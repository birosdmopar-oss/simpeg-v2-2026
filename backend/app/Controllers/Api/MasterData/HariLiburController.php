<?php

declare(strict_types=1);

namespace App\Controllers\Api\MasterData;

/**
 * G-08 — Hari Libur (DBV-003/CR-010). Legacy: hr/presensi/holiday (Presensi.php, L_presensi.php).
 *
 * Master `jenis-libur` = endpoint master generik (role 1; dropdown `master/jenis-libur/options` UL_ALL).
 */
class HariLiburController extends BaseMasterController
{
    protected array $entities = ['jenis-libur'];
}
