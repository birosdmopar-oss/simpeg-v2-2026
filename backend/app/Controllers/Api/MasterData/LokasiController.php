<?php

declare(strict_types=1);

namespace App\Controllers\Api\MasterData;

/**
 * G-03 — Master Lokasi Presensi (GPS + radius geofencing) dan pemetaan pegawai ke lokasi.
 * Legacy: hr/master/c_lokasi/* (role 1). Radius minimal 10 meter divalidasi di rules field `radius_meter`.
 * Pemetaan memakai NIP; FK ke `pegawai` menyusul di Fase 3.
 *
 * Endpoint generik (lihat README.md modul ini): index, show, options, create, update, setStatus, reorder, delete.
 * Role: CRUD = 1 (Super Admin); `options` = UL_ALL.
 */
class LokasiController extends BaseMasterController
{
    protected array $entities = ['lokasi-presensi', 'pegawai-lokasi-presensi'];
}
