<?php

declare(strict_types=1);

namespace App\Controllers\Api\MasterData;

/**
 * G-06 — Master Tanda Jasa. Legacy: hr/master/c_tj (Lm_tj.php), role 1. DBV-005/CR-012 ⏳.
 *
 * Endpoint generik master (lihat UmumController / README MasterData): `master/tanda-jasa`, dropdown
 * `master/tanda-jasa/options` (UL_ALL). ID 26/27/28 (Satyalancana Karya Satya) dan 44 `LAIN-LAIN` dipakai kode legacy
 * (tidak dikunci, B6); opsi Lain-lain di riwayat = B-17.
 */
class TandaJasaController extends BaseMasterController
{
    protected array $entities = ['tanda-jasa'];
}
