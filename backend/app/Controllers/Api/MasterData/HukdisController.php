<?php

declare(strict_types=1);

namespace App\Controllers\Api\MasterData;

/**
 * G-06 — Master Hukuman Disiplin: tingkat (`tingkat-hukdis`) dan jenis (`jenis-hukdis`, induk tingkat). Legacy:
 * hr/master/c_hukdis (Lm_hukdis.php), role 1. DBV-005/CR-012 ⏳.
 *
 * Endpoint generik master (lihat UmumController / README MasterData). Dropdown berjenjang (UL_ALL):
 * `master/tingkat-hukdis/options` → `master/jenis-hukdis/options?parent={id_tingkat_hukdis}`; dropdown jenis hanya
 * memuat jenis yang tingkatnya aktif (statusChain). `tingkat_hukdis.bobot_ipasn` (skor IPASN legacy) disimpan tetapi
 * tidak dikelola dan tidak dikirim di respons (B5). `jenis_hukdis.masa_sanksi_bulan` opsional 1–255 (K5b): nilai awal
 * hitung akhir hukuman di riwayat B-14.
 */
class HukdisController extends BaseMasterController
{
    protected array $entities = ['tingkat-hukdis', 'jenis-hukdis'];
}
