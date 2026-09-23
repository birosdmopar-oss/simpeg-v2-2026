<?php

declare(strict_types=1);

namespace App\Controllers\Api\MasterData;

/**
 * G-06 — Master Jenis Kondisi Kerja (Dinas Luar/WFH/WFO) dengan flag `affect_tukin`.
 * Legacy: hr/master/c_konket/* (role 1).
 *
 * Endpoint generik (lihat README.md modul ini): index, show, options, create, update, setStatus, reorder, delete.
 * Role: CRUD = 1 (Super Admin); `options` = UL_ALL.
 */
class KonketController extends BaseMasterController
{
    protected array $entities = ['jenis-konket'];
}
