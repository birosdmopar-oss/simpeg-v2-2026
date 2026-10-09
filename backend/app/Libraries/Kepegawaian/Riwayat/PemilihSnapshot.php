<?php

declare(strict_types=1);

namespace App\Libraries\Kepegawaian\Riwayat;

use InvalidArgumentException;

/**
 * Pemilih baris riwayat yang menjadi snapshot `pegawai_*` (WS-1 M1 MAKE-004) — FUNGSI MURNI: tanpa DB, jam, atau
 * sesi, sehingga aturan dok DBV-012 §5.1 bisa diuji unit per target. SnapshotSync membaca kandidat dari DB lalu
 * menyerahkan pemilihan ke sini.
 *
 * Aturan (setara `SELECT … WHERE status = 1 AND <filter> ORDER BY <urutan> LIMIT 1` trigger legacy):
 *  1. Hanya baris berstatus Disetujui (nilai kolom status yang dipetakan ke StatusRiwayat::Disetujui).
 *  2. Filter AturanSnapshot::$filter dengan logika tiga nilai SQL: `kolom = v` / `kolom != v` / `IN` / `NOT IN` bernilai
 *     salah bila kolom NULL (legacy `id_jenis_kp != 6` tidak memilih baris ber-`id_jenis_kp` NULL); nilai filter null =
 *     `IS NULL` / `IS NOT NULL`.
 *  3. Urutan AturanSnapshot::$urutan berprioritas; NULL dianggap terkecil (MySQL: NULL pertama pada ASC, terakhir pada
 *     DESC). Angka dibandingkan sebagai angka, selain itu sebagai teks (tanggal `Y-m-d` aman dibandingkan teks).
 *  4. [V2] Seri pada semua kolom urutan → PK terbesar menang, yaitu baris yang DIBUAT belakangan (bukan yang disetujui
 *     belakangan). Legacy `LIMIT 1` tanpa pemecah seri tidak deterministik. Jenis yang butuh "disetujui belakangan
 *     menang" (B-07 mutasi jabatan, dok §5.1) menaruh kolom waktu persetujuan (mis. `notif_date`/`updated_at` DESC) di
 *     urutan sebelum pemecah seri PK.
 *
 * Kolom urutan tabel join ditulis `tabel.kolom` dan harus ada sebagai key yang sama di baris kandidat (SnapshotSync
 * menyediakannya).
 */
final class PemilihSnapshot
{
    private function __construct()
    {
    }

    /**
     * @param list<array<string, mixed>> $baris          kandidat (baris riwayat milik satu NIP)
     * @param array<int, StatusRiwayat>  $pemetaanStatus RiwayatDefinisi::pemetaanStatus()
     *
     * @return array<string, mixed>|null baris terpilih; null = tidak ada (snapshot dihapus)
     */
    public static function pilih(
        AturanSnapshot $aturan,
        array $baris,
        string $primaryKey,
        string $kolomStatus = 'status',
        array $pemetaanStatus = [],
    ): ?array {
        $nilaiDisetujui = self::nilaiDisetujui($pemetaanStatus);
        $kandidat       = array_values(array_filter(
            $baris,
            static fn (array $row): bool => self::setara($row[$kolomStatus] ?? null, $nilaiDisetujui)
                && self::lolosFilter($aturan->filter, $row),
        ));

        if ($kandidat === []) {
            return null;
        }

        usort($kandidat, static function (array $a, array $b) use ($aturan, $primaryKey): int {
            foreach ($aturan->urutan as $kolom => $arah) {
                $cmp = self::bandingkan($a[$kolom] ?? null, $b[$kolom] ?? null);

                if ($cmp !== 0) {
                    return $arah === 'DESC' ? -$cmp : $cmp;
                }
            }

            return -self::bandingkan($a[$primaryKey] ?? null, $b[$primaryKey] ?? null);
        });

        return $kandidat[0];
    }

    /**
     * @param array<string, scalar|list<scalar>|null> $filter
     * @param array<string, mixed>                    $row
     */
    public static function lolosFilter(array $filter, array $row): bool
    {
        foreach ($filter as $kunci => $nilai) {
            $negasi = str_ends_with($kunci, ' !=');
            $kolom  = $negasi ? rtrim(substr($kunci, 0, -3)) : $kunci;

            if (! array_key_exists($kolom, $row)) {
                throw new InvalidArgumentException("Kolom filter snapshot '{$kolom}' tidak ada di baris riwayat.");
            }

            $isi = $row[$kolom];

            if ($nilai === null) {
                if (($isi === null) === $negasi) {
                    return false;
                }

                continue;
            }

            // Logika tiga nilai SQL: perbandingan dengan NULL tidak pernah benar, juga untuk != dan NOT IN.
            if ($isi === null) {
                return false;
            }

            $cocok = false;

            foreach (is_array($nilai) ? $nilai : [$nilai] as $v) {
                if (self::setara($isi, $v)) {
                    $cocok = true;
                    break;
                }
            }

            if ($cocok === $negasi) {
                return false;
            }
        }

        return true;
    }

    /**
     * Perbandingan nilai urutan: null terkecil; angka sebagai angka; selain itu teks biner.
     */
    public static function bandingkan(mixed $a, mixed $b): int
    {
        if ($a === null || $b === null) {
            return ($a === null ? 0 : 1) <=> ($b === null ? 0 : 1);
        }

        if (is_numeric($a) && is_numeric($b)) {
            return (float) $a <=> (float) $b;
        }

        return strcmp(self::teks($a), self::teks($b));
    }

    private static function setara(mixed $a, mixed $b): bool
    {
        if ($a === null || $b === null) {
            return false;
        }

        if (is_numeric($a) && is_numeric($b)) {
            return (float) $a === (float) $b;
        }

        return self::teks($a) === self::teks($b);
    }

    private static function teks(mixed $nilai): string
    {
        return is_scalar($nilai) ? (string) $nilai : '';
    }

    /**
     * @param array<int, StatusRiwayat> $pemetaanStatus
     */
    private static function nilaiDisetujui(array $pemetaanStatus): int
    {
        foreach ($pemetaanStatus as $nilai => $status) {
            if ($status === StatusRiwayat::Disetujui) {
                return $nilai;
            }
        }

        return StatusRiwayat::Disetujui->value;
    }
}
