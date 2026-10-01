<?php

declare(strict_types=1);

namespace App\Libraries\MasterData;

use App\Exceptions\ValidationException;

/** Validasi bisnis G-03 yang tidak dapat diekspresikan sebagai field generik. */
final class LokasiPresensiHooks implements MasterHooks
{
    public function derivedColumns(): array
    {
        return [];
    }

    public function beforeWrite(array $row, ?array $existing): array
    {
        $latitude = $row['latitude'] ?? $existing['latitude'] ?? null;
        $longitude = $row['longitude'] ?? $existing['longitude'] ?? null;

        if ($latitude === null || $longitude === null) {
            return $row;
        }

        $builder = db_connect()->table('lokasi_presensi')
            ->select('id_lokasi_presensi')
            ->where('latitude', (string) $latitude)
            ->where('longitude', (string) $longitude)
            ->where('status !=', 10);

        if ($existing !== null && isset($existing['id_lokasi_presensi'])) {
            $builder->where('id_lokasi_presensi !=', $existing['id_lokasi_presensi']);
        }

        if ($builder->get()->getRowArray() !== null) {
            throw ValidationException::forField('latitude', 'Koordinat latitude dan longitude sudah dipakai lokasi lain.');
        }

        return $row;
    }
}
