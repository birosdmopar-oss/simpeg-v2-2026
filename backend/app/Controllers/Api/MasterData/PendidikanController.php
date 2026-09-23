<?php

declare(strict_types=1);

namespace App\Controllers\Api\MasterData;

/**
 * G-05 — Master Pendidikan: jenjang, bidang, jurusan.
 * Legacy: hr/master/c_pendidikan/* (role 1). Dropdown berjenjang bidang → jurusan lewat `options?parent=`.
 *
 * Endpoint generik (lihat README.md modul ini): index, show, options, create, update, setStatus, reorder, delete.
 * Role: CRUD = 1 (Super Admin); `options` = UL_ALL.
 */
class PendidikanController extends BaseMasterController
{
    protected array $entities = ['jenjang-pendidikan', 'bidang-pendidikan', 'jurusan-pendidikan'];
}
