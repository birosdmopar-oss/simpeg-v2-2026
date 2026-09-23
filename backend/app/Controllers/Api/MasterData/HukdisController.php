<?php

declare(strict_types=1);

namespace App\Controllers\Api\MasterData;

/**
 * G-06 — Master Hukuman Disiplin: tingkat sanksi → jenis pasal pelanggaran.
 * Legacy: hr/master/c_hukdis/* (role 1).
 *
 * Endpoint generik (lihat README.md modul ini): index, show, options, create, update, setStatus, reorder, delete.
 * Role: CRUD = 1 (Super Admin); `options` = UL_ALL.
 */
class HukdisController extends BaseMasterController
{
    protected array $entities = ['tingkat-hukdis', 'jenis-hukdis'];
}
