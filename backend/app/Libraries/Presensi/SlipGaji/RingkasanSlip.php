<?php

declare(strict_types=1);

namespace App\Libraries\Presensi\SlipGaji;

use InvalidArgumentException;

/**
 * CR-027 (K-CR027-8) — ringkasan dan total slip gaji, int sen tanpa float.
 *
 * Interim setia legacy (`libraries/hr/Lsl_gaji.php:234-261`) sampai Biro Keuangan memutuskan rumus (R-4, TUNGGU-BK):
 * - `jumlah_penghasilan_gpp` = Σ 13 field penghasilan GPP; `jumlah_potongan_gpp` = Σ 8 field potongan GPP (legacy
 *   menghitung keduanya tetapi tidak menampilkannya);
 * - `bersih_gpp`, `bersih_tk`, `bersih_2` = nilai berkas apa adanya, tidak dihitung ulang;
 * - **`total_penghasilan = bersih_gpp + bersih_tk`**; `bersih_2` (Tunjangan Kinerja Bersih), tukin kelas jabatan, dan
 *   uang makan tidak masuk total.
 */
final class RingkasanSlip
{
    private function __construct()
    {
    }

    /**
     * Field yang tidak ada dianggap 0. Nilai bukan int → `InvalidArgumentException` (data sistem: sen sudah diurai).
     *
     * @param array<string, int> $sen field => sen
     *
     * @return array{jumlah_penghasilan_gpp: int, jumlah_potongan_gpp: int, bersih_gpp: int, bersih_tk: int, bersih_2: int, total_penghasilan: int}
     */
    public static function hitung(array $sen): array
    {
        $bersihGpp = self::ambil($sen, 'bersih_gpp');
        $bersihTk  = self::ambil($sen, 'bersih_tk');

        return [
            'jumlah_penghasilan_gpp' => self::jumlah($sen, KolomGaji::PENGHASILAN_GPP),
            'jumlah_potongan_gpp'    => self::jumlah($sen, KolomGaji::POTONGAN_GPP),
            'bersih_gpp'             => $bersihGpp,
            'bersih_tk'              => $bersihTk,
            'bersih_2'               => self::ambil($sen, 'bersih_2'),
            'total_penghasilan'      => $bersihGpp + $bersihTk,
        ];
    }

    /**
     * Cek konsistensi usulan (R-4 #4, **belum aktif**: dipakai sebagai peringatan pratinjau yang tidak memblokir hanya
     * setelah Biro Keuangan mengonfirmasi invariannya): `bersih_gpp = Σpenghasilan − Σpotongan`,
     * `bersih_tk = kotor − potongan`, `bersih_2 = bersih_tk − pajak + tunj_pajak`.
     *
     * @param array<string, int> $sen
     *
     * @return list<array{kode: string, pesan: string, harapan: int, nilai: int}>
     */
    public static function periksaKonsistensi(array $sen): array
    {
        $ringkasan = self::hitung($sen);
        $aturan    = [
            'bersih_gpp' => $ringkasan['jumlah_penghasilan_gpp'] - $ringkasan['jumlah_potongan_gpp'],
            'bersih_tk'  => self::ambil($sen, 'kotor') - self::ambil($sen, 'potongan'),
            'bersih_2'   => $ringkasan['bersih_tk'] - self::ambil($sen, 'pajak') + self::ambil($sen, 'tunj_pajak'),
        ];

        $peringatan = [];

        foreach ($aturan as $field => $harapan) {
            $nilai = self::ambil($sen, $field);

            if ($nilai !== $harapan) {
                $peringatan[] = [
                    'kode'  => "{$field}_tidak_konsisten",
                    'pesan' => sprintf(
                        '%s %s tidak sama dengan hasil hitung %s.',
                        KolomGaji::GPP[$field] ?? KolomGaji::TK[$field] ?? $field,
                        NominalGaji::formatAngka($nilai),
                        NominalGaji::formatAngka($harapan),
                    ),
                    'harapan' => $harapan,
                    'nilai'   => $nilai,
                ];
            }
        }

        return $peringatan;
    }

    /**
     * @param array<string, int> $sen
     * @param list<string>       $field
     */
    private static function jumlah(array $sen, array $field): int
    {
        $total = 0;

        foreach ($field as $nama) {
            $total += self::ambil($sen, $nama);
        }

        return $total;
    }

    /**
     * Penjaga data sistem: pemanggil bisa saja mengoper nilai mentah DB (`"4500000.00"`) alih-alih sen.
     *
     * @param array<string, mixed> $sen
     */
    private static function ambil(array $sen, string $field): int
    {
        $nilai = $sen[$field] ?? 0;

        if (! is_int($nilai)) {
            throw new InvalidArgumentException("Nominal {$field} harus int sen: " . get_debug_type($nilai));
        }

        return $nilai;
    }
}
