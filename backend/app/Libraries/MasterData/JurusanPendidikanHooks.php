<?php

declare(strict_types=1);

namespace App\Libraries\MasterData;

use App\Exceptions\ValidationException;
use CodeIgniter\Database\ResultInterface;

/**
 * Hook tulis master `jurusan-pendidikan` (G-05, DBV-004/CR-011): jurusan wajib tersedia untuk minimal satu jenjang —
 * salah satu flag `D_I`..`S_3` bernilai 1 (legacy `Lm_pendidikan.php:584-587`, "Silahkan pilih jenjang pendidikan
 * minimal satu"). Tanpa flag, jurusan tidak pernah muncul di dropdown jurusan per jenjang (filter `<row_jurusan> = 1`,
 * legacy `Local.php:83, 109`).
 *
 * Diperiksa saat tambah dan saat ubah yang MENYENTUH flag. Ubah yang tidak menyentuh flag (ganti nama, pindah bidang,
 * status) tidak diperiksa ulang — pola E6 engine (aturan diperiksa hanya bila nilainya berubah), sehingga baris impor
 * yang ketujuh flag-nya 0 (hanya mungkin lewat SQL manual, G-doc 6.3 #12) tetap bisa dirapikan kolom lainnya.
 *
 * Ubah: flag saat ini dibaca ulang dengan SELECT ... FOR UPDATE di transaksi tulis (hook dipanggil di dalamnya), bukan
 * dari snapshot $existing yang dibaca service sebelum transaksi. Dua ubah bersamaan yang masing-masing mematikan flag
 * berbeda (mis. S_1 dan S_2 dari jurusan ber-S_1+S_2) jadi berurutan: yang kedua melihat hasil yang pertama dan
 * ditolak, sehingga ketujuh flag tidak bisa menjadi 0 lewat aplikasi.
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

        // Tambah: flag yang tidak dikirim = default DB 0. Ubah: flag yang tidak berubah = nilai saat ini (terkunci).
        $final = $row + ($existing === null ? [] : $this->lockedFlags($existing));

        foreach (self::FLAGS as $flag) {
            if ((string) ($final[$flag] ?? '0') === '1') {
                return $row;
            }
        }

        // Error dilaporkan pada flag pertama (form menampilkan kelompok checkbox jenjang mulai D.I).
        throw ValidationException::forField(self::FLAGS[0], self::MESSAGE);
    }

    /**
     * Flag jurusan saat ini, dibaca dengan kunci baris (koneksi bersama MasterService, di dalam transaksinya). Baris
     * yang tidak ditemukan (tidak mungkin setelah findOrFail) jatuh ke snapshot $existing.
     *
     * @param array<string, mixed> $existing
     *
     * @return array<string, mixed>
     */
    private function lockedFlags(array $existing): array
    {
        $db    = db_connect();
        $table = $db->escapeIdentifiers($db->prefixTable('jurusan_pendidikan'));
        $cols  = implode(', ', array_map(static fn (string $flag): string => $db->escapeIdentifiers($flag), self::FLAGS));

        $result = $db->query(
            "SELECT {$cols} FROM {$table} WHERE `id_jurusan_pendidikan` = ? FOR UPDATE",
            [(int) ($existing['id_jurusan_pendidikan'] ?? 0)],
        );
        $row = $result instanceof ResultInterface ? $result->getRowArray() : null;

        return is_array($row) ? $row : $existing;
    }
}
