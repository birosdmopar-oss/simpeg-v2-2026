<?php

declare(strict_types=1);

namespace App\Controllers\Api\MasterData;

/**
 * G-04 — Kenaikan Pangkat (DBV-004/CR-011). Legacy: hr/master/c_kp/* (role 1).
 *
 * Endpoint master generik (daftar route lihat UmumController): `pangkat`, `jenis-kp`, `gol-pppk`. CRUD role 1,
 * dropdown `{entity}/options` untuk semua role yang login (dipakai form riwayat KP/KGB Fase 3).
 *
 * - `pangkat`: nama = `gol_ruang` (label dropdown & kunci peta SIASN legacy, unik). `order` = level pangkat (mode urutan
 *   manual): disimpan apa adanya (1..127), entri lain tidak pernah digeser/dinomori ulang. Dropdown per jenis:
 *   `pangkat/options?cpns=1` (CPNS) / `?cpns=2` (PNS); daftar admin menerima filter yang sama.
 * - `gol-pppk`: `uang_makan` (tarif uang makan PPPK per hari) dapat diubah role 1; `keterangan` ≤ 255 byte; status
 *   1/2/10 (legacy ENUM('1','2') tidak bisa menyimpan status Dihapus).
 */
class KpController extends BaseMasterController
{
    protected array $entities = ['pangkat', 'jenis-kp', 'gol-pppk'];
}
