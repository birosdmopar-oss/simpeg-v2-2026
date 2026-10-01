<?php

declare(strict_types=1);

namespace App\Libraries\Presensi\SlipGaji;

use App\Libraries\Kepegawaian\Kalkulasi\TanggalBisnis;
use App\Libraries\MasterData\HariLiburRules;

/**
 * CR-027 — validasi sel kunci (NIP, bulan, tahun) dan nominal satu baris impor (I-5..I-7, P-3, P-4). Masukan adalah
 * nilai mentah sel (`int|float|string|bool|null`) dari pembaca berkas D-10.
 *
 * Legacy menafsirkan sel secara diam-diam (`libraries/hr/Lsl_gaji.php:685-704`, `:771-789`): `"8.7"` → bulan 8,
 * `"2026abc"` → tahun 2026, dan NIP numerik kehilangan presisi float. v2 menolak semua itu secara eksplisit (PERBAIKI).
 */
final class BarisImpor
{
    /**
     * Panjang NIP maksimal; sama dengan `PenggunaModel::NIP_MAX_DIGITS` (NIP 18 digit, NIK Non-PNS 16 digit). Tidak
     * dirujuk langsung agar kelas ini tetap bebas dari namespace Model (dijaga `SlipGajiMurniTest`).
     */
    public const NIP_MAKS_DIGIT = 18;

    private function __construct()
    {
    }

    /**
     * NIP wajib sel **teks**: setelah trim (termasuk NBSP) hanya digit dan spasi, spasi dibuang, panjang 1–18 digit.
     * `nilai` = NIP, atau `null` bila sel kosong (baris dilewati, I-4). Gagal: `nip_bukan_teks` (sel numerik; NIP
     * 18 digit melebihi presisi double), `nip_tidak_valid`.
     */
    public static function nip(mixed $sel): HasilSlip
    {
        if (NilaiSel::kosong($sel)) {
            return HasilSlip::berhasil(null);
        }

        if (is_int($sel) || is_float($sel)) {
            return HasilSlip::gagal(
                'nip_bukan_teks',
                sprintf(
                    'NIP harus diisi sebagai teks, bukan angka (nilai: %s). Format sel NIP sebagai Text agar digit tidak berubah.',
                    NilaiSel::uraikan($sel),
                ),
            );
        }

        $teks = is_string($sel) ? NilaiSel::rapikan($sel) : '';

        if (preg_match('/^[0-9 ]+\z/', $teks) === 1) {
            $nip = str_replace(' ', '', $teks);

            if (strlen($nip) <= self::NIP_MAKS_DIGIT) {
                return HasilSlip::berhasil($nip);
            }
        }

        return HasilSlip::gagal(
            'nip_tidak_valid',
            sprintf('NIP tidak valid (nilai: %s). NIP berupa 1-%d digit angka.', NilaiSel::uraikan($sel), self::NIP_MAKS_DIGIT),
        );
    }

    /**
     * Bulan: bilangan bulat 1–12 (`int`, `float` tanpa pecahan, atau teks `^\d{1,2}$`) atau nama bulan Indonesia
     * lengkap (trim, tidak peka huruf). Singkatan, nama Inggris, `"8.7"`, `"1e1"` → `bulan_tidak_valid` (P-3).
     */
    public static function bulan(mixed $sel): HasilSlip
    {
        $angka = self::bilanganBulat($sel, '/^\d{1,2}\z/');

        if ($angka !== null && $angka >= 1 && $angka <= 12) {
            return HasilSlip::berhasil($angka);
        }

        if (is_string($sel)) {
            $nama = strtolower(NilaiSel::rapikan($sel));

            foreach (TanggalBisnis::NAMA_BULAN as $nomor => $namaBulan) {
                if (strtolower($namaBulan) === $nama) {
                    return HasilSlip::berhasil($nomor);
                }
            }
        }

        return HasilSlip::gagal(
            'bulan_tidak_valid',
            sprintf('Bulan tidak valid (nilai: %s). Gunakan angka 1-12 atau nama bulan lengkap, mis. Agustus.', NilaiSel::uraikan($sel)),
        );
    }

    /**
     * Tahun: bilangan bulat 1900–2100 (`int`, `float` tanpa pecahan seperti `2026.0`, atau teks `^\d{4}$`). `"2026abc"`,
     * `"2,026"`, `2026.5` → `tahun_tidak_valid` (P-4).
     */
    public static function tahun(mixed $sel): HasilSlip
    {
        $angka = self::bilanganBulat($sel, '/^\d{4}\z/');

        if ($angka !== null && $angka >= HariLiburRules::YEAR_MIN && $angka <= HariLiburRules::YEAR_MAX) {
            return HasilSlip::berhasil($angka);
        }

        return HasilSlip::gagal(
            'tahun_tidak_valid',
            sprintf(
                'Tahun tidak valid (nilai: %s). Gunakan tahun 4 digit %d-%d.',
                NilaiSel::uraikan($sel),
                HariLiburRules::YEAR_MIN,
                HariLiburRules::YEAR_MAX,
            ),
        );
    }

    /**
     * Urai satu baris sheet. `$sel` = kunci (`nip`, `bulan`, `tahun`, opsional `nama`) dan field nominal sheet itu
     * (mis. `bersih_gpp`) => nilai mentah; kunci `nama` tidak ada = sheet tanpa kolom nama. `null` bila NIP kosong
     * (baris dilewati, I-4).
     *
     * Galat per sel dikumpulkan (bukan berhenti di galat pertama) dengan pesan yang menyebut sheet, nomor baris, dan
     * kolom Excel. Nominal: kolom wajib terisi kosong → `nominal_wajib`; kolom lain kosong → 0 [K]; negatif →
     * `nominal_negatif` (interim N-5).
     *
     * @param array<string, mixed> $sel
     */
    public static function urai(array $sel, string $sheet, int $nomorBaris): ?BarisSheet
    {
        $sumber = KolomGaji::sumber($sheet);
        $wajib  = KolomGaji::wajibTerisi($sheet);
        $lokasi = "Sheet {$sheet} baris {$nomorBaris}";

        $hasilNip = self::nip($sel['nip'] ?? null);

        if ($hasilNip->valid && $hasilNip->nilai === null) {
            return null;
        }

        $galat = [];

        $tambahGalat = static function (HasilSlip $hasil, string $awalan) use (&$galat): void {
            $galat[] = ['kode' => (string) $hasil->kode, 'pesan' => "{$awalan}: {$hasil->pesan}"];
        };

        $nip = $hasilNip->valid ? (string) $hasilNip->nilai : null;

        if (! $hasilNip->valid) {
            $tambahGalat($hasilNip, $lokasi);
        }

        $hasilBulan = self::bulan($sel['bulan'] ?? null);
        $hasilTahun = self::tahun($sel['tahun'] ?? null);

        foreach ([$hasilBulan, $hasilTahun] as $hasil) {
            if (! $hasil->valid) {
                $tambahGalat($hasil, $lokasi);
            }
        }

        $periode = $hasilBulan->valid && $hasilTahun->valid
            ? PeriodeSlip::buat((int) $hasilTahun->nilai, (int) $hasilBulan->nilai)
            : null;

        $nama = null;

        if (array_key_exists(KolomGaji::KOLOM_NAMA, $sel)) {
            $mentah = $sel[KolomGaji::KOLOM_NAMA];
            $nama   = match (true) {
                is_string($mentah)                   => NilaiSel::rapikan($mentah),
                is_int($mentah) || is_float($mentah) => (string) $mentah,
                default                              => '',
            };
        }

        $nominal = [];

        foreach ($sumber as $kolom => $field) {
            $nominal[$field] = 0;
            $label           = "{$lokasi}: kolom {$kolom}";
            $hasil           = NominalGaji::dariSel($sel[$field] ?? null, $label);

            if (! $hasil->valid) {
                $galat[] = ['kode' => (string) $hasil->kode, 'pesan' => (string) $hasil->pesan];

                continue;
            }

            if ($hasil->nilai === null) {
                if (in_array($kolom, $wajib, true)) {
                    $galat[] = ['kode' => 'nominal_wajib', 'pesan' => "{$label} wajib diisi, tidak boleh kosong."];
                }

                continue;
            }

            $sen     = (int) $hasil->nilai;
            $negatif = NominalGaji::periksaNonNegatif($sen, $label);

            if ($negatif !== null) {
                $galat[] = ['kode' => 'nominal_negatif', 'pesan' => (string) $negatif->pesan];

                continue;
            }

            $nominal[$field] = $sen;
        }

        return new BarisSheet($sheet, $nomorBaris, $nip, $periode, $nama, $nominal, $galat);
    }

    /**
     * Bilangan bulat dari `int`, `float` tanpa pecahan, atau teks (setelah trim) yang cocok `$polaTeks`; selain itu `null`.
     */
    private static function bilanganBulat(mixed $sel, string $polaTeks): ?int
    {
        if (is_int($sel)) {
            return $sel;
        }

        if (is_float($sel)) {
            return is_finite($sel) && floor($sel) === $sel && abs($sel) < 1e9 ? (int) $sel : null;
        }

        if (is_string($sel)) {
            $teks = NilaiSel::rapikan($sel);

            return preg_match($polaTeks, $teks) === 1 ? (int) $teks : null;
        }

        return null;
    }
}
