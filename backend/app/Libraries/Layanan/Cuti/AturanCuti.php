<?php

declare(strict_types=1);

namespace App\Libraries\Layanan\Cuti;

use InvalidArgumentException;

/**
 * MAKE-022 Tahap 2b — aturan/konstanta cuti sebagai value object murni (PRD Fase 4 v1.1 §6.3.1). Sumber nilai runtime =
 * `Config\Cuti::aturan()`; {@see self::bawaan()} = nilai PRD untuk test dan pemanggil tanpa config. Library di folder
 * ini tidak boleh membaca `Config\*` (pemindai kemurnian), jadi aturan selalu diterima sebagai parameter.
 */
final readonly class AturanCuti
{
    public const KUOTA_N = 12;

    public const MAKS_N1 = 6;

    public const MAKS_N2 = 6;

    public const MAKS_TOTAL = 24;

    public const MAKS_UTANG = 12;

    /** 1 Tahunan, 3 Sakit, 5 Alasan Penting — lama = hari kerja. */
    public const JENIS_HARI_KERJA = [1, 3, 5];

    /** 2 Besar, 4 Melahirkan, 6 CLTN — lama = hari kalender inklusif. */
    public const JENIS_HARI_KALENDER = [2, 4, 6];

    public const DURASI_BESAR = [1, 2, 3];

    public const MAKS_BULAN_ALASAN_PENTING = 1;

    public const MAKS_TAHUN_CLTN = 3;

    public const JAM_BATAS_TUNDA = '12:00';

    public const JENIS_TAHUNAN = 1;

    public const JENIS_BESAR = 2;

    public const JENIS_SAKIT = 3;

    public const JENIS_MELAHIRKAN = 4;

    public const JENIS_ALASAN_PENTING = 5;

    public const JENIS_CLTN = 6;

    /**
     * @param list<int> $jenisHariKerja
     * @param list<int> $jenisHariKalender
     * @param list<int> $durasiBesar
     */
    public function __construct(
        public int $kuotaN = self::KUOTA_N,
        public int $maksN1 = self::MAKS_N1,
        public int $maksN2 = self::MAKS_N2,
        public int $maksTotal = self::MAKS_TOTAL,
        public int $maksUtang = self::MAKS_UTANG,
        public array $jenisHariKerja = self::JENIS_HARI_KERJA,
        public array $jenisHariKalender = self::JENIS_HARI_KALENDER,
        public array $durasiBesar = self::DURASI_BESAR,
        public int $maksBulanAlasanPenting = self::MAKS_BULAN_ALASAN_PENTING,
        public int $maksTahunCltn = self::MAKS_TAHUN_CLTN,
        public string $jamBatasTunda = self::JAM_BATAS_TUNDA,
    ) {
        if (preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]\z/', $jamBatasTunda) !== 1) {
            throw new InvalidArgumentException("Jam batas tunda harus HH:MM: {$jamBatasTunda}");
        }
    }

    public static function bawaan(): self
    {
        return new self();
    }

    public function jenisDikenal(int $jenis): bool
    {
        return in_array($jenis, $this->jenisHariKerja, true) || in_array($jenis, $this->jenisHariKalender, true);
    }

    /**
     * `true` = lama dihitung hari kerja; `false` = hari kalender. Jenis tidak dikenal → `InvalidArgumentException`
     * (kesalahan program/data, bukan input pengguna).
     */
    public function satuanHariKerja(int $jenis): bool
    {
        if (in_array($jenis, $this->jenisHariKerja, true)) {
            return true;
        }

        if (in_array($jenis, $this->jenisHariKalender, true)) {
            return false;
        }

        throw new InvalidArgumentException("Jenis cuti tidak dikenal: {$jenis}");
    }
}
