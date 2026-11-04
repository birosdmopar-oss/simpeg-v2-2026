<?php

declare(strict_types=1);

namespace App\Libraries\Siasn\Tarik;

use App\Libraries\Bkn\Ekinerja\ParserTanggalBkn;

/**
 * Validasi tipe & panjang nilai respons SIASN per kolom tujuan (PRD Fase 6 BP-10; syarat review B6). Legacy menulis nilai
 * mentah (`addslashes`) ke kolom apa pun; v2 menolak baris yang nilainya tidak muat/tidak sah ({@see NilaiTidakSah},
 * dihitung per kode alasan tanpa nilai). String kosong / null = tidak diisi (null), sama dengan `!= ''` legacy.
 */
final class NilaiSiasn
{
    /**
     * Teks (skalar) dengan batas panjang karakter kolom.
     */
    public static function teks(array $baris, string $kunci, int $maks): ?string
    {
        $nilai = $baris[$kunci] ?? null;

        if ($nilai === null || $nilai === '') {
            return null;
        }

        if (! is_scalar($nilai)) {
            throw new NilaiTidakSah($kunci, 'tipe');
        }

        $teks = trim((string) $nilai);

        if ($teks === '') {
            return null;
        }

        if (mb_strlen($teks) > $maks) {
            throw new NilaiTidakSah($kunci, 'panjang');
        }

        return $teks;
    }

    /**
     * Tanggal BKN `DD-MM-YYYY` / `YYYY-MM-DD` ketat ({@see ParserTanggalBkn}) → `Y-m-d`.
     */
    public static function tanggal(array $baris, string $kunci): ?string
    {
        $nilai = $baris[$kunci] ?? null;

        if ($nilai === null || $nilai === '') {
            return null;
        }

        if (! is_string($nilai)) {
            throw new NilaiTidakSah($kunci, 'tipe');
        }

        return ParserTanggalBkn::urai(trim($nilai)) ?? throw new NilaiTidakSah($kunci, 'tanggal');
    }

    /**
     * Bilangan bulat dalam rentang kolom (mis. TINYINT, YEAR).
     */
    public static function bulat(array $baris, string $kunci, int $min, int $maks): ?int
    {
        $nilai = $baris[$kunci] ?? null;

        if ($nilai === null || $nilai === '') {
            return null;
        }

        if (is_int($nilai)) {
            $angka = $nilai;
        } elseif (is_string($nilai) && preg_match('/^\s*-?\d+\s*$/', $nilai) === 1) {
            $angka = (int) trim($nilai);
        } elseif (is_float($nilai) && floor($nilai) === $nilai) {
            $angka = (int) $nilai;
        } else {
            throw new NilaiTidakSah($kunci, 'tipe');
        }

        if ($angka < $min || $angka > $maks) {
            throw new NilaiTidakSah($kunci, 'rentang');
        }

        return $angka;
    }

    /**
     * Desimal dengan batas digit bulat (mis. DECIMAL(10,3) → 7 digit bulat) → string kanonik.
     */
    public static function desimal(array $baris, string $kunci, int $digitBulat): ?string
    {
        $nilai = $baris[$kunci] ?? null;

        if ($nilai === null || $nilai === '') {
            return null;
        }

        if (! is_int($nilai) && ! is_float($nilai) && ! (is_string($nilai) && is_numeric(trim($nilai)))) {
            throw new NilaiTidakSah($kunci, 'tipe');
        }

        $angka = (float) (is_string($nilai) ? trim($nilai) : $nilai);

        if (! is_finite($angka) || abs($angka) >= 10 ** $digitBulat) {
            throw new NilaiTidakSah($kunci, 'rentang');
        }

        return is_string($nilai) ? trim($nilai) : (string) $nilai;
    }

    /**
     * Beda nilai lokal vs SIASN dengan perbandingan longgar legacy (`!=`): angka dibandingkan sebagai angka, lainnya
     * sebagai teks; null = ''.
     */
    public static function berbeda(mixed $lokal, mixed $siasn): bool
    {
        $a = $lokal === null ? '' : (string) $lokal;
        $b = $siasn === null ? '' : (string) $siasn;

        if (is_numeric($a) && is_numeric($b)) {
            return (float) $a !== (float) $b;
        }

        return $a !== $b;
    }
}
