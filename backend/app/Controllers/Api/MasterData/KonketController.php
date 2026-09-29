<?php

declare(strict_types=1);

namespace App\Controllers\Api\MasterData;

/**
 * G-06 — Master Jenis Konfirmasi Ketidakhadiran (jenis konket). Legacy: hr/master/c_konket (Lm_konket.php), role 1.
 * DBV-005/CR-012 ⏳.
 *
 * Endpoint generik master (lihat UmumController / README MasterData): `master/jenis-konket`, dropdown
 * `master/jenis-konket/options` (UL_ALL). `old_id` = kode kategori yang disimpan pengajuan konket
 * (`absen_ijin.kategori`, B2): wajib, unik, bisa diubah admin. `affect_tukin` 1 Ya / 2 Tidak = nilai awal "Pengaruh ke
 * Tukin" pengajuan (K5a). ID 2/4/5/6 dan `old_id` 8/10/13 dipakai kode legacy (tidak dikunci, B6); filter pilihan
 * per role = B-13.
 */
class KonketController extends BaseMasterController
{
    protected array $entities = ['jenis-konket'];
}
