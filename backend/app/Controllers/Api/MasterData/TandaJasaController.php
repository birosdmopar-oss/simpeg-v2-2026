<?php

declare(strict_types=1);

namespace App\Controllers\Api\MasterData;

/**
 * G-06 — Master Tanda Jasa & Bintang Kehormatan. Legacy: hr/master/c_tj/* (role 1).
 *
 * Endpoint generik (lihat README.md modul ini): index, show, options, create, update, setStatus, reorder, delete.
 * Role: CRUD = 1 (Super Admin); `options` = UL_ALL.
 */
class TandaJasaController extends BaseMasterController
{
    protected array $entities = ['tanda-jasa'];
}
