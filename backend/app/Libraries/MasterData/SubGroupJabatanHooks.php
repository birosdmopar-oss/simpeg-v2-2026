<?php

declare(strict_types=1);

namespace App\Libraries\MasterData;

use App\Exceptions\ValidationException;

/**
 * Hook tulis master `sub-group-jabatan` (G-02, DBV-008/CR-026): sub group yang sudah dirujuk jabatan tidak boleh
 * dipindah ke group lain [V2]. `jabatan` menyimpan `id_group_jabatan` dan `id_sub_group_jabatan` sendiri-sendiri
 * (DDL [K] simpeg_prod.sql:1449-1450), sehingga memindah sub group membuat group di jabatan-jabatannya basi (tidak lagi
 * induk sub group-nya) — legacy mengizinkannya tanpa memperbaiki jabatan (`Lm_jabatan.php:938-1018`).
 *
 * Hanya diperiksa saat ubah yang MENGGANTI group (engine hanya mengirim kolom yang berubah). Ganti nama, `need_satker`,
 * urutan, dan status tetap boleh. Semua jabatan dihitung, termasuk yang Tidak Aktif/Dihapus: barisnya tetap ada dan
 * tetap dirujuk data riwayat.
 *
 * Batas yang diketahui (sama dengan cek rujukan engine lain): tambah jabatan dan pindah group sub group yang berjalan
 * PERSIS bersamaan memakai named lock tabel yang berbeda, sehingga keduanya bisa lolos cek masing-masing (G-02 Bagian
 * 2.9). Tulis master hanya oleh Super Admin.
 */
class SubGroupJabatanHooks implements MasterHooks
{
    public function derivedColumns(): array
    {
        return [];
    }

    public function beforeWrite(array $row, ?array $existing): array
    {
        if ($existing === null || ! array_key_exists('id_group_jabatan', $row)) {
            return $row;
        }

        $db    = db_connect();
        $count = $db->table('jabatan')->where('id_sub_group_jabatan', (int) $existing['id_sub_group_jabatan'])->countAllResults();

        if ($count > 0) {
            throw ValidationException::forField(
                'id_group_jabatan',
                "Sub group jabatan ini sudah dipakai {$count} jabatan; pindah group tidak diizinkan. Buat sub group baru di group tujuan.",
            );
        }

        return $row;
    }
}
