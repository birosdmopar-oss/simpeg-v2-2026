<?php

declare(strict_types=1);

namespace App\Libraries\MasterData;

use App\Exceptions\ValidationException;

/**
 * Hook tulis master `kantor` (G-07, DBV-003/CR-010, keputusan F8). Engine sudah memeriksa tiap kode wilayah (kanonik,
 * ada, aktif bila berubah; kode LAIN-LAIN hanya lewat field ber-allowSystem) dan keunikan `nama_kantor` global. Hook
 * ini menegakkan aturan lintas kolom yang di legacy hanya ada di JS form (`kantor/form.php:280-299`) atau tidak ada
 * sama sekali (`Lm_umum.php:1771-1836` tidak memeriksa rantai):
 *
 *  1. Sentinel berjenjang: bila satu level LAIN-LAIN, semua level di bawahnya wajib LAIN-LAIN.
 *  2. Rantai non-sentinel: kabupaten/kota wajib milik provinsi terpilih, dst. (pesan sama dengan engine). Induk riil
 *     dengan anak LAIN-LAIN boleh (legacy `kantor/form.php:274`).
 *  3. Isian `*_lain`: wajib bila level itu LAIN-LAIN; dipaksa NULL bila bukan (seperti `set_param_kantor` :1845-1851).
 *  4. `kode_pos` (opsional, 5 digit — rule field): bila kelurahan bukan LAIN-LAIN dan `kelurahan.kd_pos` terisi, wajib
 *     salah satu kode pos di daftar itu (usulan DBV-003 #12). Kelurahan LAIN-LAIN atau tanpa `kd_pos` = bebas 5 digit.
 *
 * Ubah (update): aturan hanya diperiksa bila salah satu kolom wilayah/`*_lain`/`kode_pos` ikut berubah (pola E6), jadi
 * data lama yang rantainya tidak konsisten tetap bisa diubah nama/alamatnya. Kode LAIN-LAIN dibaca dari registry
 * (`systemIds` master wilayah), bukan literal. Dijalankan di dalam transaksi engine (koneksi default yang sama).
 */
class KantorHooks implements MasterHooks
{
    /**
     * Level wilayah kantor urut provinsi → kelurahan: kolom kode => [key master wilayah, kolom isian "lainnya"].
     */
    public const LEVELS = [
        'id_provinsi'  => ['provinsi', 'provinsi_lain'],
        'id_kabupaten' => ['kabupaten-kota', 'kabupaten_lain'],
        'id_kecamatan' => ['kecamatan', 'kecamatan_lain'],
        'id_kelurahan' => ['kelurahan', 'kelurahan_lain'],
    ];

    public const KODE_POS = 'kode_pos';

    /**
     * Kolom daftar kode pos di master kelurahan (dipisah koma).
     */
    private const KD_POS = 'kd_pos';

    public function derivedColumns(): array
    {
        return [];
    }

    public function beforeWrite(array $row, ?array $existing): array
    {
        if ($existing !== null && array_intersect(array_keys($row), self::watchedColumns()) === []) {
            return $row;
        }

        $final    = $row + ($existing ?? []);
        $registry = service('masterRegistry');
        $kantor   = $registry->get('kantor');
        $levels   = [];

        foreach (self::LEVELS as $column => [$entity, $otherColumn]) {
            $def      = $registry->get($entity);
            $code     = trim((string) ($final[$column] ?? ''));
            $levels[] = [
                'column' => $column,
                'other'  => $otherColumn,
                'def'    => $def,
                'code'   => $code,
                'system' => $def->isSystemId($code),
                'label'  => $kantor->field($column)->label ?? $def->label,
            ];
        }

        $this->assertSentinelCascade($levels);
        $rows = $this->assertChain($levels);
        $row  = $this->normalizeOtherColumns($row, $final, $levels, $kantor);

        return $this->assertKodePos($row, $final, $levels[3], $rows['id_kelurahan'] ?? null);
    }

    /**
     * Kolom yang memicu pemeriksaan ulang saat ubah.
     *
     * @return list<string>
     */
    public static function watchedColumns(): array
    {
        $columns = [];

        foreach (self::LEVELS as $column => [, $otherColumn]) {
            $columns[] = $column;
            $columns[] = $otherColumn;
        }

        $columns[] = self::KODE_POS;

        return $columns;
    }

    /**
     * Aturan 1: level di bawah level LAIN-LAIN wajib LAIN-LAIN.
     *
     * @param list<array{column: string, other: string, def: MasterDefinition, code: string, system: bool, label: string}> $levels
     */
    private function assertSentinelCascade(array $levels): void
    {
        $systemAbove = null;

        foreach ($levels as $level) {
            if ($systemAbove !== null && ! $level['system']) {
                throw ValidationException::forField($level['column'], "{$level['label']} harus LAIN-LAIN bila {$systemAbove} LAIN-LAIN.");
            }

            if ($level['system'] && $systemAbove === null) {
                $systemAbove = $level['label'];
            }
        }
    }

    /**
     * Aturan 2: pasangan level (atas, bawah) yang keduanya bukan LAIN-LAIN harus satu rantai.
     *
     * @param list<array{column: string, other: string, def: MasterDefinition, code: string, system: bool, label: string}> $levels
     *
     * @return array<string, array<string, mixed>> baris wilayah riil yang dibaca, per kolom kode kantor
     */
    private function assertChain(array $levels): array
    {
        $db   = db_connect();
        $rows = [];

        foreach ($levels as $index => $level) {
            if ($level['system'] || $level['code'] === '') {
                continue;
            }

            /** @var MasterDefinition $def */
            $def = $level['def'];

            /** @var array<string, mixed>|null $row */
            $row = $db->table($def->table)->where($def->primaryKey, $level['code'])->get()->getRowArray();

            if ($row === null) {
                throw ValidationException::forField($level['column'], "{$level['label']} tidak ditemukan.");
            }

            $rows[$level['column']] = $row;
            $upper                  = $levels[$index - 1] ?? null;

            if ($upper === null || $upper['system'] || $def->parentField === null) {
                continue;
            }

            if ((string) $row[$def->parentField] !== $upper['code']) {
                throw ValidationException::forField(
                    $level['column'],
                    "{$level['label']} {$row[$def->nameField]} tidak berada di bawah {$upper['label']} yang dipilih.",
                );
            }
        }

        return $rows;
    }

    /**
     * Aturan 3: `*_lain` wajib (sudah di-trim & dibatasi rule field) bila levelnya LAIN-LAIN, NULL bila bukan.
     *
     * @param array<string, mixed>                                                                                         $row
     * @param array<string, mixed>                                                                                         $final
     * @param list<array{column: string, other: string, def: MasterDefinition, code: string, system: bool, label: string}> $levels
     *
     * @return array<string, mixed>
     */
    private function normalizeOtherColumns(array $row, array $final, array $levels, MasterDefinition $kantor): array
    {
        foreach ($levels as $level) {
            $value = trim((string) ($final[$level['other']] ?? ''));

            if ($level['system']) {
                if ($value === '') {
                    $label = $kantor->field($level['other'])->label ?? $level['other'];

                    throw ValidationException::forField($level['other'], "{$label} wajib diisi bila {$level['label']} LAIN-LAIN.");
                }

                continue;
            }

            if (($final[$level['other']] ?? null) !== null) {
                $row[$level['other']] = null;
            }
        }

        return $row;
    }

    /**
     * Aturan 4: kode pos 5 digit; bila kelurahan riil punya daftar `kd_pos`, wajib salah satunya.
     *
     * @param array<string, mixed>                                                                                   $row
     * @param array<string, mixed>                                                                                   $final
     * @param array{column: string, other: string, def: MasterDefinition, code: string, system: bool, label: string} $kelurahan
     * @param array<string, mixed>|null                                                                              $kelurahanRow
     *
     * @return array<string, mixed>
     */
    private function assertKodePos(array $row, array $final, array $kelurahan, ?array $kelurahanRow): array
    {
        $kodePos = trim((string) ($final[self::KODE_POS] ?? ''));

        if ($kodePos === '') {
            return $row;
        }

        if (preg_match('/^[0-9]{5}\z/', $kodePos) !== 1) {
            throw ValidationException::forField(self::KODE_POS, 'Kode Pos harus 5 digit angka.');
        }

        if ($kelurahan['system'] || $kelurahanRow === null) {
            return $row;
        }

        $allowed = array_values(array_filter(array_map('trim', explode(',', (string) ($kelurahanRow[self::KD_POS] ?? ''))), static fn (string $v): bool => $v !== ''));

        if ($allowed !== [] && ! in_array($kodePos, $allowed, true)) {
            throw ValidationException::forField(self::KODE_POS, 'Kode Pos harus salah satu kode pos kelurahan terpilih: ' . implode(', ', $allowed) . '.');
        }

        return $row;
    }
}
