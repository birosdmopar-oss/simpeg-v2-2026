<?php

declare(strict_types=1);

namespace App\Libraries\MasterData;

use App\Exceptions\ValidationException;

/**
 * Hook master `lokasi_presensi` (G-03, DBV-007/CR-031). Engine sudah memvalidasi bentuk field (nama wajib & unik,
 * latitude −90..90, longitude −180..180, radius ≥ 10 meter). Hook ini menegakkan aturan yang butuh baris lain:
 *
 *  1. K-4 — koordinat (latitude + longitude) unik di antara lokasi yang belum dihapus (status ≠ 10), sama untuk tambah,
 *     ubah, DAN pemulihan dari status 10 (legacy L-3 hanya membandingkan lokasi aktif saat tambah). Perbandingan numerik,
 *     bukan teks: data legacy `-6.175400` sama dengan input `-6.1754` (kolom VARCHAR [K]).
 *  2. K-5 — lokasi yang masih dirujuk aturan penargetan aktif (`dm_user_lokasi_presensi.status` = 1, `target_lp`
 *     memuat id lokasi) tidak boleh dinonaktifkan/dihapus: 422 dengan daftar aturan perujuknya.
 *
 * Aturan 1 untuk tambah/ubah lewat beforeWrite(); pemulihan (PATCH status 10 → 1/2, atau PUT status) dan aturan 2 lewat
 * beforeStatusChange() — jalur PATCH status/DELETE engine tidak memanggil beforeWrite().
 */
final class LokasiPresensiHooks implements MasterHooks, MasterStatusHooks
{
    private const TABLE = 'lokasi_presensi';
    private const PK    = 'id_lokasi_presensi';

    public function derivedColumns(): array
    {
        return [];
    }

    public function beforeWrite(array $row, ?array $existing): array
    {
        // Ubah tanpa menyentuh koordinat: tidak dicek ulang (data lama tetap bisa diubah nama/radiusnya). Pemulihan dari
        // status 10 lewat PUT ditangani beforeStatusChange().
        if ($existing !== null && ! array_key_exists('latitude', $row) && ! array_key_exists('longitude', $row)) {
            return $row;
        }

        $final = $row + ($existing ?? []);

        if ((string) ($final['status'] ?? '1') !== '10') {
            self::assertCoordinateFree($final);
        }

        return $row;
    }

    public function beforeStatusChange(array $row, string $from, string $to): void
    {
        if ($from === '10') {
            self::assertCoordinateFree($row);
        }

        if ($to !== '1') {
            self::assertNotReferenced((string) ($row[self::PK] ?? ''), $to);
        }
    }

    /**
     * Dua nilai koordinat dianggap sama bila keduanya angka dan bernilai sama (`-6.175400` = `-6.1754`). Nilai legacy
     * yang bukan angka hanya sama dengan teks yang persis sama.
     */
    public static function sameCoordinate(mixed $a, mixed $b): bool
    {
        $a = trim((string) $a);
        $b = trim((string) $b);

        if (is_numeric($a) && is_numeric($b)) {
            return (float) $a === (float) $b;
        }

        return $a === $b;
    }

    /**
     * @param array<string, mixed> $row baris final (koordinat + id bila sudah ada)
     */
    private static function assertCoordinateFree(array $row): void
    {
        $latitude  = $row['latitude'] ?? null;
        $longitude = $row['longitude'] ?? null;

        if ($latitude === null || $longitude === null || $latitude === '' || $longitude === '') {
            return;
        }

        $builder = db_connect()->table(self::TABLE)
            ->select(self::PK . ', nama_lokasi, latitude, longitude')
            ->where('status !=', 10);

        if (isset($row[self::PK]) && (string) $row[self::PK] !== '') {
            $builder->where(self::PK . ' !=', (int) $row[self::PK]);
        }

        foreach ($builder->get()->getResultArray() as $other) {
            if (self::sameCoordinate($other['latitude'], $latitude) && self::sameCoordinate($other['longitude'], $longitude)) {
                throw ValidationException::forField(
                    'latitude',
                    "Koordinat latitude dan longitude sudah dipakai lokasi lain ({$other['nama_lokasi']}).",
                );
            }
        }
    }

    /**
     * K-5: daftar aturan aktif yang `target_lp`-nya memuat lokasi ini. JSON dibaca di PHP (bukan LIKE), karena data
     * legacy bisa berupa `["1"]` maupun `[1]` dan masih ber-addslashes.
     */
    private static function assertNotReferenced(string $id, string $to): void
    {
        if ($id === '') {
            return;
        }

        $rules = db_connect()->table('dm_user_lokasi_presensi')
            ->select('id_dm_user_lokasi_presensi, target_lp, keterangan')
            ->where('status', 1)
            ->orderBy('id_dm_user_lokasi_presensi', 'ASC')
            ->get()->getResultArray();

        $referencing = [];

        foreach ($rules as $rule) {
            if (in_array($id, AturanLokasiPresensiHooks::storedIds($rule['target_lp']), true)) {
                $keterangan    = trim((string) ($rule['keterangan'] ?? ''));
                $referencing[] = '#' . $rule['id_dm_user_lokasi_presensi'] . ($keterangan !== '' ? " ({$keterangan})" : '');
            }
        }

        if ($referencing !== []) {
            $action = $to === '10' ? 'dihapus' : 'dinonaktifkan';

            throw ValidationException::forField(
                'status',
                "Lokasi presensi tidak dapat {$action} karena masih dirujuk aturan lokasi presensi aktif: "
                . implode(', ', $referencing) . '. Ubah atau nonaktifkan aturan tersebut lebih dulu.',
            );
        }
    }
}
