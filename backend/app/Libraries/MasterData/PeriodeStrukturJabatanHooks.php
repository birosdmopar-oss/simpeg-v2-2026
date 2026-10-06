<?php

declare(strict_types=1);

namespace App\Libraries\MasterData;

use App\Exceptions\ValidationException;

/**
 * Hook tulis master `periode-struktur-jabatan` (G-02 sisa, DBV-018/CR-032): nama periode = tahun 4 digit 2000–3000.
 * Legacy hanya membatasinya lewat mask form (`views/hr/master/jabatan/periode/form.php:43, :66-69`, "Tahun periode, hanya
 * boleh angka. Min: 2000, Maks: 3000"); kolomnya VARCHAR(45) [K] D1:3882, jadi batas ditegakkan di aplikasi.
 *
 * Hanya diperiksa saat nama ikut ditulis (tambah, atau ubah yang mengganti nama). Data impor lama yang tidak berbentuk
 * tahun tetap bisa diubah status/kolom lainnya.
 */
class PeriodeStrukturJabatanHooks implements MasterHooks
{
    private const FIELD = 'periode_struktur_jabatan';

    private const MIN = 2000;

    private const MAX = 3000;

    public function derivedColumns(): array
    {
        return [];
    }

    public function beforeWrite(array $row, ?array $existing): array
    {
        if (! array_key_exists(self::FIELD, $row)) {
            return $row;
        }

        $value = trim((string) $row[self::FIELD]);

        if (preg_match('/^[0-9]{4}\z/', $value) !== 1 || (int) $value < self::MIN || (int) $value > self::MAX) {
            throw ValidationException::forField(self::FIELD, 'Periode Struktur harus tahun 4 digit antara ' . self::MIN . ' dan ' . self::MAX . '.');
        }

        return $row;
    }
}
