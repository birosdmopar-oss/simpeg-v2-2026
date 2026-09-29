<?php

declare(strict_types=1);

namespace App\Controllers\Api\MasterData;

/**
 * G-06 — Master Pelatihan (diklat). Legacy: hr/master/c_diklat (Lm_diklat.php), role 1. DBV-005/CR-012 ⏳.
 *
 * Endpoint generik master (lihat UmumController / README MasterData): `master/diklat`, dropdown
 * `master/diklat/options?jenis_diklat=` (UL_ALL). Urutan & keunikan nama per jenis pelatihan (1 Struktural, 2 Teknis,
 * 3 Fungsional, 4 Prajabatan, 5 Sertifikasi); daftar admin disaring `?jenis_diklat=`. PK TINYINT legacy: setelah id
 * 127 terpakai, tambah pelatihan gagal (G-06 Bagian 3 #8). ID 8 dikecualikan dari push SIASN di legacy (tidak dikunci,
 * B6).
 */
class DiklatController extends BaseMasterController
{
    protected array $entities = ['diklat'];
}
