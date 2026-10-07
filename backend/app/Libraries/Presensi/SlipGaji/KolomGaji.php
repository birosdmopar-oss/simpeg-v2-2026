<?php

declare(strict_types=1);

namespace App\Libraries\Presensi\SlipGaji;

use InvalidArgumentException;

/**
 * CR-027 — kolom slip gaji: 28 field nominal (21 + `bersih_gpp` dari sheet GPP, 6 dari sheet TK), peta kolom Excel →
 * field, kolom wajib terisi, normalisasi header, dan pencarian sheet (I-1..I-3).
 *
 * [K] label `libraries/hr/Lsl_gaji.php:15-48`, peta `:51-70`, wajib terisi `:712-713`, header `:742-760`, sheet
 * `:671-678`. 28 field = 28 kolom `DECIMAL(14,2) NOT NULL DEFAULT 0.00` berkas migrasi dev legacy (skema final D-01).
 */
final class KolomGaji
{
    public const SHEET_GPP = 'GPP';

    public const SHEET_TK = 'TK';

    /**
     * Field GPP => label [K] `Lsl_gaji.php:15-38`.
     */
    public const GPP = [
        'gjpokok'    => 'Gaji Pokok',
        'tjistri'    => 'Tunjangan Istri/Suami',
        'tjanak'     => 'Tunjangan Anak',
        'tjupns'     => 'Tunjangan Umum PNS',
        'tjstruk'    => 'Tunjangan Struktural',
        'tjfungs'    => 'Tunjangan Fungsional',
        'tjdaerah'   => 'Tunjangan Daerah',
        'tjpencil'   => 'Tunjangan Pencil/Pensiun',
        'tjlain'     => 'Tunjangan Lain-lain',
        'tjkompen'   => 'Tunjangan Kompensasi',
        'pembul'     => 'Pembulatan',
        'tjberas'    => 'Tunjangan Beras',
        'tjpph'      => 'Tunjangan PPh',
        'potpfkbul'  => 'Potongan PFK Bulanan',
        'potpfk2'    => 'Potongan PFK 2%',
        'potpfk10'   => 'Potongan PFK 10%',
        'potpph'     => 'Potongan PPh',
        'potswrum'   => 'Potongan Sewa Rumah',
        'potkelbtj'  => 'Potongan Kelebihan Tunjangan',
        'potlain'    => 'Potongan Lain-lain',
        'pottabrum'  => 'Potongan Tabungan Perumahan',
        'bersih_gpp' => 'Gaji Bersih (GPP)',
    ];

    /**
     * Field TK => label [K] `Lsl_gaji.php:41-48`.
     */
    public const TK = [
        'kotor'      => 'Penghasilan Kotor',
        'potongan'   => 'Potongan',
        'bersih_tk'  => 'Bersih (TK)',
        'pajak'      => 'Pajak',
        'tunj_pajak' => 'Tunjangan Pajak',
        'bersih_2'   => 'Tunjangan Kinerja Bersih',
    ];

    /**
     * Kolom Excel sheet GPP => field [K] `Lsl_gaji.php:61-70`; `bersih` → `bersih_gpp`.
     */
    public const SUMBER_GPP = [
        'gjpokok'   => 'gjpokok',
        'tjistri'   => 'tjistri',
        'tjanak'    => 'tjanak',
        'tjupns'    => 'tjupns',
        'tjstruk'   => 'tjstruk',
        'tjfungs'   => 'tjfungs',
        'tjdaerah'  => 'tjdaerah',
        'tjpencil'  => 'tjpencil',
        'tjlain'    => 'tjlain',
        'tjkompen'  => 'tjkompen',
        'pembul'    => 'pembul',
        'tjberas'   => 'tjberas',
        'tjpph'     => 'tjpph',
        'potpfkbul' => 'potpfkbul',
        'potpfk2'   => 'potpfk2',
        'potpfk10'  => 'potpfk10',
        'potpph'    => 'potpph',
        'potswrum'  => 'potswrum',
        'potkelbtj' => 'potkelbtj',
        'potlain'   => 'potlain',
        'pottabrum' => 'pottabrum',
        'bersih'    => 'bersih_gpp',
    ];

    /**
     * Kolom Excel sheet TK => field [K] `Lsl_gaji.php:51-58`; `bersih` → `bersih_tk`.
     */
    public const SUMBER_TK = [
        'kotor'      => 'kotor',
        'potongan'   => 'potongan',
        'bersih'     => 'bersih_tk',
        'pajak'      => 'pajak',
        'tunj_pajak' => 'tunj_pajak',
        'bersih_2'   => 'bersih_2',
    ];

    /**
     * Field penghasilan GPP (13). Legacy menurunkannya dari prefiks `pot` (`Lsl_gaji.php:234-245`); v2 mendaftarnya
     * eksplisit agar field baru tidak masuk jumlah tanpa disengaja.
     *
     * @var list<string>
     */
    public const PENGHASILAN_GPP = [
        'gjpokok', 'tjistri', 'tjanak', 'tjupns', 'tjstruk', 'tjfungs', 'tjdaerah', 'tjpencil', 'tjlain', 'tjkompen',
        'pembul', 'tjberas', 'tjpph',
    ];

    /**
     * Field potongan GPP (8).
     *
     * @var list<string>
     */
    public const POTONGAN_GPP = ['potpfkbul', 'potpfk2', 'potpfk10', 'potpph', 'potswrum', 'potkelbtj', 'potlain', 'pottabrum'];

    /**
     * Kolom Excel yang wajib terisi; sel kosong → `nominal_wajib` [K] `Lsl_gaji.php:712-713`.
     *
     * @var list<string>
     */
    public const WAJIB_TERISI_GPP = ['gjpokok', 'bersih'];

    /**
     * @var list<string>
     */
    public const WAJIB_TERISI_TK = ['kotor', 'bersih'];

    /**
     * Kolom kunci wajib ada di header [K] `Lsl_gaji.php:756-760`.
     *
     * @var list<string>
     */
    public const KUNCI = ['nip', 'bulan', 'tahun'];

    /**
     * Kolom opsional untuk pencocokan nama (M-1) [K] `Lsl_gaji.php:775-778`.
     */
    public const KOLOM_NAMA = 'nama';

    private function __construct()
    {
    }

    /**
     * 28 field nominal: field GPP lalu field TK.
     *
     * @return list<string>
     */
    public static function semuaField(): array
    {
        return [...array_keys(self::GPP), ...array_keys(self::TK)];
    }

    /**
     * Peta kolom Excel => field untuk sheet `GPP`/`TK`; nama sheet lain → `InvalidArgumentException`.
     *
     * @return array<string, string>
     */
    public static function sumber(string $sheet): array
    {
        return match ($sheet) {
            self::SHEET_GPP => self::SUMBER_GPP,
            self::SHEET_TK  => self::SUMBER_TK,
            default         => throw new InvalidArgumentException("Sheet slip gaji tidak dikenal: {$sheet}"),
        };
    }

    /**
     * Kolom Excel wajib terisi untuk sheet `GPP`/`TK`.
     *
     * @return list<string>
     */
    public static function wajibTerisi(string $sheet): array
    {
        return match ($sheet) {
            self::SHEET_GPP => self::WAJIB_TERISI_GPP,
            self::SHEET_TK  => self::WAJIB_TERISI_TK,
            default         => throw new InvalidArgumentException("Sheet slip gaji tidak dikenal: {$sheet}"),
        };
    }

    /**
     * Normalisasi teks header: huruf kecil, lalu setiap rangkaian whitespace, NBSP, `-`, `.`, dan `_` dipadatkan menjadi
     * satu `_`, dan `_` di tepi dibuang (`'TUNJ  PAJAK'` → `tunj_pajak`). Legacy hanya mengganti per karakter
     * (`Lsl_gaji.php:749-750`), sehingga spasi ganda menghasilkan `tunj__pajak` yang tidak cocok (I-2, PERBAIKI).
     */
    public static function normalisasiHeader(string $header): string
    {
        $kecil = strtolower($header);

        $padat = preg_replace('/[\s\x{00A0}\-._]+/u', '_', $kecil) ?? preg_replace('/[\s\-._]+/', '_', $kecil) ?? $kecil;

        return trim($padat, '_');
    }

    /**
     * Petakan baris header (nilai mentah sel, urut kolom) ke indeks kolom.
     *
     * Gagal: `kolom_ganda` (kolom yang dikenali muncul lebih dari sekali setelah normalisasi; legacy diam-diam memakai
     * yang terakhir), `kolom_kunci_tidak_ada` (`nip`/`bulan`/`tahun`), `kolom_tidak_ada` (kolom peta yang hilang; legacy
     * mengisi 0 diam-diam). Kolom tak dikenal diabaikan dan dilaporkan di `diabaikan` (info `kolom_diabaikan`).
     *
     * `nilai` = `array{kolom: array<string, int>, nama: ?int, diabaikan: list<string>}`; kunci `kolom` = `nip`, `bulan`,
     * `tahun`, dan nama field (mis. `bersih_gpp`).
     *
     * @param array<int, mixed> $header
     */
    public static function petakanHeader(array $header, string $sheet): HasilSlip
    {
        $sumber  = self::sumber($sheet);
        $dikenal = [...self::KUNCI, self::KOLOM_NAMA, ...array_keys($sumber)];

        $indeks    = [];
        $ganda     = [];
        $diabaikan = [];

        foreach ($header as $kolom => $sel) {
            $nama = self::normalisasiHeader(self::teksHeader($sel));

            if ($nama === '') {
                continue;
            }

            if (! in_array($nama, $dikenal, true)) {
                $diabaikan[] = $nama;

                continue;
            }

            if (isset($indeks[$nama])) {
                $ganda[$nama] = true;

                continue;
            }

            $indeks[$nama] = (int) $kolom;
        }

        if ($ganda !== []) {
            $daftar = array_keys($ganda);

            return HasilSlip::gagal(
                'kolom_ganda',
                "Sheet {$sheet} memiliki kolom ganda: " . implode(', ', $daftar) . '.',
                ['sheet' => $sheet, 'kolom' => $daftar],
            );
        }

        $kunciHilang = array_values(array_diff(self::KUNCI, array_keys($indeks)));

        if ($kunciHilang !== []) {
            return HasilSlip::gagal(
                'kolom_kunci_tidak_ada',
                "Sheet {$sheet} tidak memiliki kolom wajib: " . strtoupper(implode(', ', $kunciHilang)) . '.',
                ['sheet' => $sheet, 'kolom' => $kunciHilang],
            );
        }

        $sumberHilang = array_values(array_diff(array_keys($sumber), array_keys($indeks)));

        if ($sumberHilang !== []) {
            return HasilSlip::gagal(
                'kolom_tidak_ada',
                "Sheet {$sheet} tidak memiliki kolom: " . implode(', ', $sumberHilang) . '. Gunakan template resmi.',
                ['sheet' => $sheet, 'kolom' => $sumberHilang],
            );
        }

        $kolom = [];

        foreach (self::KUNCI as $kunci) {
            $kolom[$kunci] = $indeks[$kunci];
        }

        foreach ($sumber as $namaSumber => $field) {
            $kolom[$field] = $indeks[$namaSumber];
        }

        $diabaikan = array_values(array_unique($diabaikan));

        return HasilSlip::berhasil(
            ['kolom' => $kolom, 'nama' => $indeks[self::KOLOM_NAMA] ?? null, 'diabaikan' => $diabaikan],
            [],
            $diabaikan === [] ? null : 'kolom_diabaikan',
            $diabaikan === [] ? null : "Kolom tidak dikenal di sheet {$sheet} diabaikan: " . implode(', ', $diabaikan) . '.',
        );
    }

    /**
     * Indeks sheet pertama yang namanya sama dengan `$dicari` setelah trim, tidak peka huruf [K] `Lsl_gaji.php:671-678`.
     *
     * @param array<int, string> $namaSheet
     */
    public static function cariSheet(array $namaSheet, string $dicari): ?int
    {
        $target = strtolower(trim($dicari));

        foreach ($namaSheet as $indeks => $nama) {
            if (strtolower(NilaiSel::rapikan($nama)) === $target) {
                return $indeks;
            }
        }

        return null;
    }

    /**
     * Kedua sheet `GPP` dan `TK` wajib ada (I-1, PERBAIKI; legacy hanya gagal bila keduanya tidak ada). `nilai` =
     * `array{GPP: int, TK: int}`; gagal `sheet_tidak_ada`.
     *
     * @param array<int, string> $namaSheet
     */
    public static function cariSheetWajib(array $namaSheet): HasilSlip
    {
        $hasil  = [];
        $hilang = [];

        foreach ([self::SHEET_GPP, self::SHEET_TK] as $sheet) {
            $indeks = self::cariSheet($namaSheet, $sheet);

            if ($indeks === null) {
                $hilang[] = $sheet;
            } else {
                $hasil[$sheet] = $indeks;
            }
        }

        if ($hilang !== []) {
            return HasilSlip::gagal(
                'sheet_tidak_ada',
                'Berkas tidak memiliki sheet ' . implode(' dan ', $hilang) . '. Pastikan nama sheet sesuai template (GPP dan TK).',
                ['sheet' => $hilang],
            );
        }

        return HasilSlip::berhasil($hasil);
    }

    private static function teksHeader(mixed $sel): string
    {
        return match (true) {
            is_string($sel)                => $sel,
            is_int($sel) || is_float($sel) => (string) $sel,
            default                        => '',
        };
    }
}
