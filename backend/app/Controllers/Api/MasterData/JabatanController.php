<?php

declare(strict_types=1);

namespace App\Controllers\Api\MasterData;

/**
 * G-02 — Master Jabatan, Unit & Satker: unit kerja (`unit`), satuan kerja (`satker`, induk unit), group jabatan
 * (`group-jabatan`), sub group jabatan (`sub-group-jabatan`, induk group), kelas jabatan (`kelas-jabatan`), dan jabatan
 * (`jabatan`). Legacy: hr/master/c_jabatan (Lm_jabatan.php), role 1. DBV-008/CR-026 ⏳.
 *
 * Endpoint generik master (lihat UmumController / README MasterData). Dropdown (UL_ALL, ISSUE-012):
 *   - `unit/options` → `satker/options?parent={id_unit}` (satker aktif yang unitnya aktif, `statusChain`);
 *   - `group-jabatan/options` → `sub-group-jabatan/options?parent={id_group_jabatan}` (`statusChain`);
 *   - `jabatan/options?id_sub_group_jabatan=&id_satker=` (juga `id_group_jabatan`, `kelas_jabatan`);
 *   - `kelas-jabatan/options` (id = nama = nomor kelas, codeAsName CR-026).
 * `satker.zonasi` (offset jam presensi dari WIB, 0–120 menit) wajib. `satker.logo_uns` dan `jabatan.id_jenjang_jf`
 * disimpan tetapi tidak dikelola/diekspos (hiddenColumns). Pindah group sub group yang sudah dirujuk jabatan ditolak
 * (SubGroupJabatanHooks). Peta jabatan & tabel jabatan lain: DBV-018 (G-02b).
 */
class JabatanController extends BaseMasterController
{
    protected array $entities = ['unit', 'satker', 'group-jabatan', 'sub-group-jabatan', 'kelas-jabatan', 'jabatan'];
}
