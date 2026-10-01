<?php

declare(strict_types=1);

namespace App\Libraries\MasterData;

use App\Exceptions\ValidationException;

/** Normalisasi payload kompatibel legacy untuk aturan penargetan G-03. */
final class AturanLokasiPresensiHooks implements MasterHooks
{
    private const JSON_FIELDS = ['target_lp', 'target_uns', 'target_jp'];

    public function derivedColumns(): array
    {
        return ['target_lp_desc', 'target_uns_desc', 'target_jp_desc'];
    }

    public function beforeWrite(array $row, ?array $existing): array
    {
        foreach (self::JSON_FIELDS as $field) {
            if (! array_key_exists($field, $row)) {
                continue;
            }
            $value = $row[$field];
            $decoded = is_string($value) ? json_decode($value, true) : $value;
            if (! is_array($decoded) || $decoded === []) {
                throw ValidationException::forField($field, 'Nilai harus JSON array dan minimal berisi satu pilihan.');
            }
            $decoded = array_values(array_unique(array_map(static fn (mixed $v): string => trim((string) $v, " \t\n\r\0\x0B\""), $decoded)));
            $encoded = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($encoded === false) {
                throw ValidationException::forField($field, 'Nilai JSON tidak valid.');
            }
            $row[$field] = $encoded;
        }

        if (isset($row['target_lp'])) {
            $ids = json_decode((string) $row['target_lp'], true);
            $names = [];
            if (is_array($ids) && $ids !== []) {
                $names = db_connect()->table('lokasi_presensi')
                    ->select('id_lokasi_presensi, nama_lokasi')
                    ->whereIn('id_lokasi_presensi', array_map('intval', $ids))
                    ->get()->getResultArray();
            }
            $byId = array_column($names, 'nama_lokasi', 'id_lokasi_presensi');
            $row['target_lp_desc'] = implode(', ', array_map(static fn (mixed $id): string => $byId[(string) (int) $id] ?? (string) $id, $ids ?? []));
        }
        foreach (['target_uns', 'target_jp'] as $field) {
            if (isset($row[$field])) {
                $ids = json_decode((string) $row[$field], true);
                $row[$field . '_desc'] = is_array($ids) ? implode(', ', $ids) : (string) $row[$field];
            }
        }

        if (array_key_exists('hari_berlaku', $row) && $row['hari_berlaku'] !== null && $row['hari_berlaku'] !== '') {
            $days = array_values(array_unique(array_map('intval', explode(',', (string) $row['hari_berlaku']))));
            sort($days);
            if (array_filter($days, static fn (int $day): bool => $day < 1 || $day > 7) !== []) {
                throw ValidationException::forField('hari_berlaku', 'Hari berlaku hanya boleh berisi angka 1 sampai 7.');
            }
            $row['hari_berlaku'] = implode(',', $days);
        }

        return $row;
    }
}
