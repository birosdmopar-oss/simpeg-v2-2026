<?php

declare(strict_types=1);

namespace App\Controllers\Api\MasterData;

/**
 * G-04 — Master Kenaikan Pangkat: pangkat/golongan PNS, jenis KP, golongan PPPK.
 * Legacy: hr/master/c_kp/* (role 1).
 *
 * Endpoint generik (lihat README.md modul ini): index, show, options, create, update, setStatus, reorder, delete.
 * Role: CRUD = 1 (Super Admin); `options` = UL_ALL.
 */
class KpController extends BaseMasterController
{
    protected array $entities = ['pangkat', 'jenis-kp', 'gol-pppk'];
}
