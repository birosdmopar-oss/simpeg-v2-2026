<?php

declare(strict_types=1);

namespace App\Controllers\Api\MasterData;

/**
 * G-05 — Pendidikan (DBV-004/CR-011). Legacy: hr/master/c_pendidikan/* (role 1).
 *
 * Endpoint master generik (daftar route lihat UmumController): `jenjang-pendidikan`, `bidang-pendidikan`,
 * `jurusan-pendidikan`. CRUD role 1, dropdown `{entity}/options` untuk semua role yang login (form riwayat pendidikan
 * Fase 3).
 *
 * Dropdown berjenjang bidang → jurusan: `bidang-pendidikan/options` → `jurusan-pendidikan/options?parent={id_bidang}`.
 * Dropdown jurusan mengikuti rantai status: jurusan hanya muncul bila jurusan DAN bidangnya aktif.
 *
 * - `jenjang-pendidikan`: singkatan unik (422 pada `jenjang_pendidikan_singkat`); `row_jurusan` dipilih dari daftar tetap
 *   `D_I`..`S_3` (kosong = NULL); `bobot_ipasn` tidak pernah dikirim di respons dan tidak bisa diubah lewat API.
 * - `jurusan-pendidikan`: nama unik per bidang; flag `D_I`..`S_3` bernilai 1/0 dengan minimal satu flag 1
 *   (JurusanPendidikanHooks).
 */
class PendidikanController extends BaseMasterController
{
    protected array $entities = ['jenjang-pendidikan', 'bidang-pendidikan', 'jurusan-pendidikan'];
}
