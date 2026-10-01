<?php

declare(strict_types=1);

namespace App\Libraries\Presensi\SlipGaji;

use InvalidArgumentException;

/**
 * CR-027 (K-CR027-2, K-CR027-6) — nominal slip gaji sebagai **int sen**, tanpa float di aritmetika. Satu aturan per
 * sumber (N-4, PERBAIKI):
 *
 * - {@see self::dariSel()}: nilai mentah sel Excel (`formatData = false`, keluaran pembaca D-10): `int`/`float` dari sel
 *   numerik, atau teks berformat Indonesia ketat (`1.234.567`, `1.234,50`; titik selalu pemisah ribuan).
 * - {@see self::dariInputApi()}: koreksi admin lewat API, hanya JSON number atau teks kanonik titik-desimal
 *   (`4500000.50`). Tidak pernah memakai format Indonesia.
 * - {@see self::dariDesimal()}/{@see self::keDesimal()}: batas DB `DECIMAL(14,2)`.
 *
 * Legacy membaca teks tampilan sel lalu menghapus semua titik dan mengganti koma dengan titik
 * (`libraries/hr/Lsl_gaji.php:794-812`, `:594-600`), sehingga `4500000.5` terbaca 45.000.005 dan setiap simpan koreksi
 * mengalikan nilai ×100. Di v2 setiap nilai yang tidak bisa dibaca pasti gagal eksplisit.
 */
final class NominalGaji
{
    /**
     * Batas `DECIMAL(14,2)`: 99.999.999.999.999 sen = 999.999.999.999,99 rupiah.
     */
    public const SEN_MAKS = 99_999_999_999_999;

    /**
     * Rupiah bulat terbesar (12 digit).
     */
    public const RUPIAH_MAKS = 999_999_999_999;

    /**
     * Teks sel: format Indonesia ketat, opsional minus ASCII, ribuan bertitik per 3 digit atau tanpa pemisah, desimal
     * koma 1–2 digit.
     */
    private const POLA_SEL = '/^(-?)(\d{1,3}(?:\.\d{3})+|\d+)(?:,(\d{1,2}))?\z/';

    /**
     * Teks API kanonik: titik desimal, tanpa pemisah ribuan, tanpa spasi.
     */
    private const POLA_API = '/^(-?)(\d{1,12})(?:\.(\d{1,2}))?\z/';

    private const POLA_DESIMAL_DB = '/^(-?)(\d{1,12})\.(\d{2})\z/';

    private function __construct()
    {
    }

    /**
     * Nilai mentah sel Excel → `nilai` int sen, atau `null` bila sel kosong (`null`, `''`, hanya spasi/NBSP). Pemanggil
     * menentukan sel kosong: kolom wajib terisi → `nominal_wajib`, kolom lain → 0 [K] (`Lsl_gaji.php:706-713`, `:802`).
     *
     * Gagal: `nominal_tidak_valid`, `nominal_desimal_berlebih`, `nominal_terlalu_besar`. Pesan memuat `$label` dan nilai
     * mentah. Nilai negatif lolos di sini; tolak dengan {@see self::periksaNonNegatif()}.
     */
    public static function dariSel(mixed $nilai, string $label = 'Nominal'): HasilSlip
    {
        if (NilaiSel::kosong($nilai)) {
            return HasilSlip::berhasil(null);
        }

        if (is_int($nilai)) {
            return self::dariInt($nilai, $label, $nilai);
        }

        if (is_float($nilai)) {
            return self::dariFloat($nilai, $label, $nilai);
        }

        if (! is_string($nilai) || preg_match(self::POLA_SEL, NilaiSel::rapikan($nilai), $m) !== 1) {
            return self::tidakValid(
                $label,
                $nilai,
                'Gunakan angka atau teks berformat Indonesia, mis. 1.234.567 atau 1.234,50.',
            );
        }

        return self::dariBagian($m[1] === '-', str_replace('.', '', $m[2]), $m[3] ?? '', $label, $nilai);
    }

    /**
     * Masukan API (koreksi admin) → `nilai` int sen. Hanya JSON number (`int`/`float`, aturan float sama dengan
     * {@see self::dariSel()}) atau teks kanonik `^-?\d{1,12}(\.\d{1,2})?$` tanpa trim. `bool`, `null`, `''`, array →
     * `nominal_tidak_valid`. Negatif lolos di sini; tolak dengan {@see self::periksaNonNegatif()}.
     */
    public static function dariInputApi(mixed $nilai, string $label = 'Nominal'): HasilSlip
    {
        if (is_int($nilai)) {
            return self::dariInt($nilai, $label, $nilai);
        }

        if (is_float($nilai)) {
            return self::dariFloat($nilai, $label, $nilai);
        }

        if (! is_string($nilai) || preg_match(self::POLA_API, $nilai, $m) !== 1) {
            return HasilSlip::gagal(
                'nominal_tidak_valid',
                "{$label} harus berupa angka dengan titik desimal tanpa pemisah ribuan, mis. 4500000.50.",
            );
        }

        return self::dariBagian($m[1] === '-', $m[2], $m[3] ?? '', $label, $nilai);
    }

    /**
     * Interim N-5 (TUNGGU-BK): nominal negatif ditolak di impor dan koreksi (`nominal_negatif`); `null` bila ≥ 0.
     */
    public static function periksaNonNegatif(int $sen, string $label = 'Nominal'): ?HasilSlip
    {
        if ($sen < 0) {
            return HasilSlip::gagal('nominal_negatif', "{$label} tidak boleh negatif.", ['sen' => $sen]);
        }

        return null;
    }

    /**
     * Sen → teks `DECIMAL(14,2)` untuk DB/JSON (`450000050` → `"4500000.50"`, `-150` → `"-1.50"`).
     */
    public static function keDesimal(int $sen): string
    {
        self::wajibDalamBatas($sen);

        $mutlak = abs($sen);

        return ($sen < 0 ? '-' : '') . intdiv($mutlak, 100) . '.' . sprintf('%02d', $mutlak % 100);
    }

    /**
     * Teks `DECIMAL(14,2)` dari DB → sen lewat pemecahan string (tanpa float). Format lain → `InvalidArgumentException`.
     */
    public static function dariDesimal(string $desimal): int
    {
        if (preg_match(self::POLA_DESIMAL_DB, $desimal, $m) !== 1) {
            throw new InvalidArgumentException('Bukan nilai DECIMAL(14,2): ' . var_export($desimal, true));
        }

        $sen = (int) $m[2] * 100 + (int) $m[3];

        return $m[1] === '-' ? -$sen : $sen;
    }

    /**
     * Angka rupiah tanpa desimal bergaya Indonesia (`450000050` → `"4.500.001"`), dibulatkan setengah menjauhi nol dan
     * tanpa `"-0"` — setara `number_format(x, 0, ',', '.')` legacy (P-10). Awalan "Rp" ditentukan UI.
     */
    public static function formatAngka(int $sen): string
    {
        self::wajibDalamBatas($sen);

        $rupiah = intdiv(abs($sen) + 50, 100);

        return ($sen < 0 && $rupiah > 0 ? '-' : '') . number_format($rupiah, 0, ',', '.');
    }

    private static function dariInt(int $rupiah, string $label, mixed $mentah): HasilSlip
    {
        if ($rupiah > self::RUPIAH_MAKS || $rupiah < -self::RUPIAH_MAKS) {
            return self::terlaluBesar($label, $mentah);
        }

        return HasilSlip::berhasil($rupiah * 100);
    }

    /**
     * Float dari sel numerik/JSON. Derau representasi biner (`0.1 + 0.2`, `19.99`) ditoleransi secara relatif; pecahan
     * di bawah sen (`1.005`, `1.0004`) gagal, bukan dibulatkan diam-diam.
     */
    private static function dariFloat(float $rupiah, string $label, mixed $mentah): HasilSlip
    {
        if (! is_finite($rupiah)) {
            return self::tidakValid($label, $mentah, 'Nilai harus berupa angka terhingga.');
        }

        $kaliSeratus = $rupiah * 100;

        // Dicek sebelum cast agar (int) tidak meluap.
        if (abs($kaliSeratus) > self::SEN_MAKS) {
            return self::terlaluBesar($label, $mentah);
        }

        $sen = (int) round($kaliSeratus);

        if (abs($kaliSeratus - $sen) > max(1e-6, abs($kaliSeratus) * 1e-15)) {
            return HasilSlip::gagal(
                'nominal_desimal_berlebih',
                sprintf('%s memiliki lebih dari 2 angka desimal (nilai: %s).', $label, NilaiSel::uraikan($mentah)),
            );
        }

        return self::hasilSen($sen, $label, $mentah);
    }

    /**
     * Rakit sen dari bagian teks yang sudah lolos pola. Jumlah digit bagian bulat dicek sebelum konversi supaya `(int)`
     * tidak tersaturasi ke `PHP_INT_MAX`.
     */
    private static function dariBagian(bool $negatif, string $bulat, string $pecahan, string $label, mixed $mentah): HasilSlip
    {
        $bulatTanpaNol = ltrim($bulat, '0');

        if (strlen($bulatTanpaNol) > strlen((string) self::RUPIAH_MAKS)) {
            return self::terlaluBesar($label, $mentah);
        }

        $sen = (int) ($bulatTanpaNol === '' ? '0' : $bulatTanpaNol) * 100 + (int) str_pad($pecahan, 2, '0');

        return self::hasilSen($negatif ? -$sen : $sen, $label, $mentah);
    }

    private static function hasilSen(int $sen, string $label, mixed $mentah): HasilSlip
    {
        if ($sen > self::SEN_MAKS || $sen < -self::SEN_MAKS) {
            return self::terlaluBesar($label, $mentah);
        }

        return HasilSlip::berhasil($sen);
    }

    private static function tidakValid(string $label, mixed $mentah, string $saran): HasilSlip
    {
        return HasilSlip::gagal(
            'nominal_tidak_valid',
            sprintf('%s bukan angka yang valid (nilai: %s). %s', $label, NilaiSel::uraikan($mentah), $saran),
        );
    }

    private static function terlaluBesar(string $label, mixed $mentah): HasilSlip
    {
        return HasilSlip::gagal(
            'nominal_terlalu_besar',
            sprintf('%s melebihi batas 999.999.999.999,99 (nilai: %s).', $label, NilaiSel::uraikan($mentah)),
        );
    }

    private static function wajibDalamBatas(int $sen): void
    {
        if ($sen > self::SEN_MAKS || $sen < -self::SEN_MAKS) {
            throw new InvalidArgumentException("Nominal sen di luar batas DECIMAL(14,2): {$sen}");
        }
    }
}
