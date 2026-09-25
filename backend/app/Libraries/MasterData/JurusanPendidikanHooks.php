<?php

declare(strict_types=1);

namespace App\Libraries\MasterData;

use App\Exceptions\ValidationException;

/**
 * Hook tulis master `jurusan-pendidikan` (G-05, DBV-004/CR-011): jurusan wajib tersedia untuk minimal satu jenjang —
 * salah satu flag `D_I`..`S_3` bernilai 1 (legacy `Lm_pendidikan.php:584-587`, "Silahkan pilih jenjang pendidikan
 * minimal satu"). Tanpa flag, jurusan tidak pernah muncul di dropdown jurusan per jenjang (filter `<row_jurusan> = 1`,
 * legacy `Local.php:83, 109`).
 *
 * Diperiksa saat tambah dan saat ubah yang MENYENTUH flag. Ubah yang tidak menyentuh flag (ganti nama, pindah bidang,
 * status) tidak diperiksa ulang — pola E6 engine (aturan diperiksa hanya bila nilainya berubah), sehingga baris impor
 * yang ketujuh flag-nya 0 (hanya mungkin lewat SQL manual, G-doc 6.3 #12) tetap bisa dirapikan kolom lainnya.
 */
class JurusanPendidikanHooks implements MasterHooks
{
    /**
     * Kolom flag jenjang (nilai 1/0, P10) — sama dengan pilihan jenjang_pendidikan.row_jurusan dan CHECK-nya.
     */
    public const FLAGS = ['D_I', 'D_II', 'D_III', 'D_IV', 'S_1', 'S_2', 'S_3'];

    public const MESSAGE = 'Pilih minimal satu jenjang pendidikan.';

    public function derivedColumns(): array
    {
        return [];
    }

    public function beforeWrite(array $row, ?array $existing): array
    {
        if ($existing !== null && array_intersect(self::FLAGS, array_keys($row)) === []) {
            return $row;
        }

        // Tambah: flag yang tidak dikirim = default DB 0. Ubah: flag yang tidak berubah = nilai saat ini.
        $final = $row + ($existing ?? []);

        foreach (self::FLAGS as $flag) {
            if ((string) ($final[$flag] ?? '0') === '1') {
                return $row;
            }
        }

        // Error dilaporkan pada flag pertama (form menampilkan kelompok checkbox jenjang mulai D.I).
        throw ValidationException::forField(self::FLAGS[0], self::MESSAGE);
    }
}
