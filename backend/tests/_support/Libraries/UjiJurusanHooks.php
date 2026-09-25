<?php

declare(strict_types=1);

namespace Tests\Support\Libraries;

use App\Libraries\MasterData\MasterHooks;

/**
 * Hook master UJI `uji-jurusan` (CR-009) — mensimulasikan balapan: bila $concurrentRow diisi test, baris itu
 * di-INSERT lewat koneksi DB KEDUA (autocommit) sesaat sebelum engine menulis, seperti permintaan lain yang menang
 * setelah cek aplikasi lolos. Tulisan engine lalu menabrak UNIQUE index (1062) dan harus diterjemahkan ke 422.
 */
class UjiJurusanHooks implements MasterHooks
{
    /**
     * @var array<string, mixed>|null
     */
    public static ?array $concurrentRow = null;

    public function derivedColumns(): array
    {
        return [];
    }

    public function beforeWrite(array $row, ?array $existing): array
    {
        if (self::$concurrentRow !== null) {
            $other = db_connect('tests', false);
            $other->table('uji_jurusan')->insert(self::$concurrentRow);
            $other->close();

            self::$concurrentRow = null;
        }

        return $row;
    }
}
