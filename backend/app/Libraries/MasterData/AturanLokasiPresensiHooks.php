<?php

declare(strict_types=1);

namespace App\Libraries\MasterData;

use App\Exceptions\ValidationException;
use CodeIgniter\Database\BaseConnection;

/**
 * Hook master aturan penargetan lokasi presensi `dm_user_lokasi_presensi` (G-03, DBV-007/CR-031).
 *
 * Format kolom mengikuti legacy (`Lm_lokasi::set_param_dm`, P-4/K-7) agar data impor dan data v2 sama bentuknya:
 *  - `target_lp` / `target_uns` / `target_jp`: JSON array teks, mis. `["1","2"]`;
 *  - `target_lp_desc` / `target_uns_desc` / `target_jp_desc`: JSON array NAMA, mis. `["Kantor Pusat"]` (G3-FR-12). Kolom
 *    ini turunan: selalu dihitung ulang dari master saat kolom target-nya berubah, nilai dari input diabaikan.
 *
 * Validasi (422 per field, tidak pernah 500 untuk input apa pun):
 *  - target: string JSON berupa array (list) tak kosong berisi skalar; JSON kosong/rusak/objek/bersarang → 422;
 *  - `target_lp` (P-1): id lokasi presensi yang ada dan aktif;
 *  - `target_uns` (P-5): `0` (seluruh kementerian), id unit, atau `sat_<id>` satker yang ada dan aktif. Bila `0`
 *    dipilih, isinya dinormalkan menjadi `["0"]` seperti legacy (pilihan lain diabaikan). Tabel `unit`/`satker` belum
 *    ada di SIMPEG v2 (G-02 belum di main) → selama itu hanya `0` yang diterima;
 *  - `target_jp` (P-3, K-8): id jenis pegawai yang ada dan aktif, kecuali id 7 (legacy `Lm_lokasi.php:276`);
 *  - `hari_berlaku` (G3-FR-13): bentuk divalidasi rule field; di sini dinormalkan unik + terurut ("5,1,3,3" → "1,3,5").
 *
 * Ubah (update): hanya kolom yang berubah yang divalidasi & dihitung ulang, jadi aturan hasil impor tetap bisa diubah
 * keterangannya. Mengaktifkan/memulihkan aturan (beforeStatusChange) mensyaratkan semua lokasinya aktif (K-5).
 */
final class AturanLokasiPresensiHooks implements MasterHooks, MasterStatusHooks
{
    /**
     * Teks `target_uns_desc` untuk kode `0` bila `web_config.nama_kementerian` belum tersedia.
     */
    public const SELURUH_KEMENTERIAN = 'Seluruh Kementerian';

    /**
     * Jenis pegawai yang tidak dapat dipilih (K-8, legacy `Lm_lokasi.php:276`).
     */
    public const JENIS_PEGAWAI_DIKECUALIKAN = '7';

    private const LABELS = [
        'target_lp'  => 'Target lokasi',
        'target_uns' => 'Target unit/satker',
        'target_jp'  => 'Jenis pegawai',
    ];

    public function derivedColumns(): array
    {
        return ['target_lp_desc', 'target_uns_desc', 'target_jp_desc'];
    }

    public function beforeWrite(array $row, ?array $existing): array
    {
        // Kolom *_desc hanya dari server: buang nilai kiriman klien.
        foreach ($this->derivedColumns() as $column) {
            unset($row[$column]);
        }

        if (array_key_exists('target_lp', $row)) {
            [$row['target_lp'], $row['target_lp_desc']] = $this->targetLokasi($row['target_lp']);
        }

        if (array_key_exists('target_uns', $row)) {
            [$row['target_uns'], $row['target_uns_desc']] = $this->targetUnitSatker($row['target_uns']);
        }

        if (array_key_exists('target_jp', $row)) {
            [$row['target_jp'], $row['target_jp_desc']] = $this->targetJenisPegawai($row['target_jp']);
        }

        if (array_key_exists('hari_berlaku', $row) && $row['hari_berlaku'] !== null && $row['hari_berlaku'] !== '') {
            $days = array_values(array_unique(array_map('intval', explode(',', (string) $row['hari_berlaku']))));
            sort($days);

            if (min($days) < 1 || max($days) > 7) {
                throw ValidationException::forField('hari_berlaku', 'Hari berlaku hanya boleh berisi angka 1 sampai 7.');
            }

            $row['hari_berlaku'] = implode(',', $days);
        }

        return $row;
    }

    public function beforeStatusChange(array $row, string $from, string $to): void
    {
        if ($to !== '1') {
            return;
        }

        $ids    = self::storedIds($row['target_lp'] ?? null);
        $active = $ids === [] ? [] : array_map('strval', array_column(
            $this->db()->table('lokasi_presensi')->select('id_lokasi_presensi')
                ->whereIn('id_lokasi_presensi', array_map('intval', $ids))->where('status', 1)
                ->get()->getResultArray(),
            'id_lokasi_presensi',
        ));
        $inactive = array_values(array_diff($ids, $active));

        if ($inactive !== []) {
            throw ValidationException::forField(
                'status',
                'Aturan tidak dapat diaktifkan karena lokasi presensi berikut tidak aktif atau tidak dikenal: ID '
                . implode(', ', $inactive) . '. Ubah target lokasinya lebih dulu.',
            );
        }
    }

    /**
     * Id di kolom target tersimpan (JSON legacy bisa ber-addslashes, `["1"]` atau `[1]`). Nilai rusak → [].
     *
     * @return list<string>
     */
    public static function storedIds(mixed $json): array
    {
        if (! is_string($json) || $json === '') {
            return [];
        }

        $decoded = json_decode($json, true);

        if (! is_array($decoded)) {
            $decoded = json_decode(stripslashes($json), true);
        }

        if (! is_array($decoded)) {
            return [];
        }

        $ids = [];

        foreach ($decoded as $value) {
            if (is_int($value) || is_string($value)) {
                $ids[] = trim((string) $value);
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @return array{0: string, 1: string} [JSON id, JSON nama]
     */
    private function targetLokasi(mixed $raw): array
    {
        $ids = $this->parseList('target_lp', $raw, static fn (string $v): bool => self::isId($v));

        $names = array_column(
            $this->db()->table('lokasi_presensi')->select('id_lokasi_presensi, nama_lokasi')
                ->whereIn('id_lokasi_presensi', array_map('intval', $ids))->where('status', 1)
                ->get()->getResultArray(),
            'nama_lokasi',
            'id_lokasi_presensi',
        );

        $this->assertAllKnown('target_lp', $ids, $names, 'Lokasi presensi');

        return [self::encode($ids), self::encode(array_map(static fn (string $id): string => (string) $names[$id], $ids))];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function targetUnitSatker(mixed $raw): array
    {
        $ids = $this->parseList(
            'target_uns',
            $raw,
            static fn (string $v): bool => $v === '0' || self::isId($v) || (str_starts_with($v, 'sat_') && self::isId(substr($v, 4))),
        );

        // Legacy: memilih "Seluruh Kementerian" menghentikan pembacaan pilihan lain (Lm_lokasi.php, `break`).
        if (in_array('0', $ids, true)) {
            return [self::encode(['0']), self::encode([$this->namaKementerian()])];
        }

        $unitIds   = array_values(array_filter($ids, static fn (string $v): bool => ! str_starts_with($v, 'sat_')));
        $satkerIds = array_values(array_map(static fn (string $v): string => substr($v, 4), array_filter($ids, static fn (string $v): bool => str_starts_with($v, 'sat_'))));

        $names = [];

        foreach ($this->activeNames('unit', 'id_unit', 'unit', $unitIds) as $id => $name) {
            $names[(string) $id] = $name;
        }

        foreach ($this->activeNames('satker', 'id_satker', 'satker', $satkerIds) as $id => $name) {
            $names['sat_' . $id] = $name;
        }

        $this->assertAllKnown('target_uns', $ids, $names, 'Unit/satker');

        return [self::encode($ids), self::encode(array_map(static fn (string $id): string => (string) $names[$id], $ids))];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function targetJenisPegawai(mixed $raw): array
    {
        $ids = $this->parseList('target_jp', $raw, static fn (string $v): bool => self::isId($v));

        if (in_array(self::JENIS_PEGAWAI_DIKECUALIKAN, $ids, true)) {
            throw ValidationException::forField('target_jp', 'Jenis pegawai dengan ID ' . self::JENIS_PEGAWAI_DIKECUALIKAN . ' tidak dapat dipilih untuk aturan lokasi presensi.');
        }

        $names = array_column(
            $this->db()->table('jenis_pegawai')->select('id_jenis_pegawai, jenis_pegawai')
                ->whereIn('id_jenis_pegawai', array_map('intval', $ids))->where('status', 1)
                ->get()->getResultArray(),
            'jenis_pegawai',
            'id_jenis_pegawai',
        );

        $this->assertAllKnown('target_jp', $ids, $names, 'Jenis pegawai');

        return [self::encode($ids), self::encode(array_map(static fn (string $id): string => (string) $names[$id], $ids))];
    }

    /**
     * String JSON → daftar id unik (urutan input). Bukan string, JSON rusak, bukan array list, kosong, elemen bukan
     * skalar teks/angka bulat, atau bentuk id tidak sah → 422.
     *
     * @param callable(string): bool $validId
     *
     * @return list<string>
     */
    private function parseList(string $field, mixed $raw, callable $validId): array
    {
        $label   = self::LABELS[$field];
        $decoded = is_string($raw) && trim($raw) !== '' ? json_decode($raw, true) : null;

        if (! is_array($decoded) || $decoded === [] || ! array_is_list($decoded)) {
            throw ValidationException::forField($field, "{$label} wajib dipilih minimal satu.");
        }

        $ids = [];

        foreach ($decoded as $value) {
            if (! is_int($value) && ! is_string($value)) {
                throw ValidationException::forField($field, "{$label} tidak valid.");
            }

            $value = trim((string) $value);

            if (! $validId($value)) {
                throw ValidationException::forField($field, "{$label} tidak valid: {$value}.");
            }

            $ids[] = $value;
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param list<string>          $ids
     * @param array<string, string> $names id => nama (hanya entri yang ada & aktif)
     */
    private function assertAllKnown(string $field, array $ids, array $names, string $label): void
    {
        $unknown = array_values(array_filter($ids, static fn (string $id): bool => ! array_key_exists($id, $names)));

        if ($unknown !== []) {
            throw ValidationException::forField($field, "{$label} tidak dikenal atau tidak aktif: " . implode(', ', $unknown) . '.');
        }
    }

    /**
     * Nama entri aktif tabel unit/satker. Tabel belum ada di SIMPEG v2 → [] (semua id ditolak sebagai tidak dikenal).
     *
     * @param list<string> $ids
     *
     * @return array<string, string>
     */
    private function activeNames(string $table, string $pk, string $nameColumn, array $ids): array
    {
        if ($ids === [] || ! $this->tableExists($table)) {
            return [];
        }

        $rows = $this->db()->table($table)->select("{$pk}, {$nameColumn}")
            ->whereIn($pk, array_map('intval', $ids))->where('status', 1)
            ->get()->getResultArray();

        return array_map('strval', array_column($rows, $nameColumn, $pk));
    }

    /**
     * Legacy: `web_config.nama_kementerian`. Tabel/baris belum ada (G-09 belum di main) → teks "Seluruh Kementerian".
     */
    private function namaKementerian(): string
    {
        if ($this->tableExists('web_config')) {
            $row = $this->db()->table('web_config')->select('config_value')
                ->where('config_name', 'nama_kementerian')->get()->getRowArray();
            $nama = trim((string) ($row['config_value'] ?? ''));

            if ($nama !== '') {
                return $nama;
            }
        }

        return self::SELURUH_KEMENTERIAN;
    }

    /**
     * Cek tabel tanpa cache daftar tabel koneksi (tabel G-02/G-09 bisa dibuat setelah koneksi dibuka). Mode tanpa cache
     * CI4 tidak menambahkan DBPrefix sendiri.
     */
    private function tableExists(string $table): bool
    {
        return $this->db()->tableExists($this->db()->prefixTable($table), false);
    }

    private static function isId(string $value): bool
    {
        return preg_match('/^[1-9][0-9]{0,9}$/', $value) === 1 && (int) $value <= 2147483647;
    }

    /**
     * @param list<string> $values
     */
    private static function encode(array $values): string
    {
        return (string) json_encode($values, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function db(): BaseConnection
    {
        return db_connect();
    }
}
