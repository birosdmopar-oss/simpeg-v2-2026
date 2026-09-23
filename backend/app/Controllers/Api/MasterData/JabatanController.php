<?php

declare(strict_types=1);

namespace App\Controllers\Api\MasterData;

/**
 * G-02 — Master Jabatan, Unit & Satker. Legacy: hr/master/c_jabatan/* (role 1).
 * Satker memuat `zonasi` (offset menit terhadap WIB) yang dipakai presensi Fase 5.
 * Master kelas jabatan & peta formasi belum dibuat: DDL legacy belum tersedia (Trello ISSUE-003).
 *
 * Endpoint generik (lihat README.md modul ini): index, show, options, create, update, setStatus, reorder, delete.
 * Role: CRUD = 1 (Super Admin); `options` = UL_ALL.
 */
class JabatanController extends BaseMasterController
{
    protected array $entities = ['group-jabatan', 'sub-group-jabatan', 'unit', 'satker', 'jabatan'];
}
