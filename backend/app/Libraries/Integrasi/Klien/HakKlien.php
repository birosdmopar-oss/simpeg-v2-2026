<?php

declare(strict_types=1);

namespace App\Libraries\Integrasi\Klien;

use InvalidArgumentException;

/**
 * MAKE-058 (murni) — jenis klien dan hak endpoint (`klien_layanan.jenis`/`hak`, DBV-026 §13.2 A3, audit B4, PRD Fase 6
 * §7.0.1). Hak = grup endpoint yang boleh dipanggil klien; filter `klienLayanan:<hak>` menolak klien tanpa hak itu.
 *
 * Aturan jenis ↔ hak (audit B4 = 0): jenis 1 (SIMAPI lama/Pusdatin) dan 2 (SIMAPI baru/Simanta) WAJIB punya hak `simapi`;
 * jenis 3 (layanan/MuDig) TIDAK boleh punya hak `simapi`. Hak lain (`stat`, `dm`, `esign`, `sinkron`) bebas per klien.
 */
final class HakKlien
{
    public const SIMAPI  = 'simapi';
    public const STAT    = 'stat';
    public const DM      = 'dm';
    public const ESIGN   = 'esign';
    public const SINKRON = 'sinkron';

    /** Urutan sama dengan SET kolom `hak` (DBV-026-Q1). */
    public const SEMUA = [self::SIMAPI, self::STAT, self::DM, self::ESIGN, self::SINKRON];

    public const JENIS_SIMAPI_LAMA = 1;
    public const JENIS_SIMAPI_BARU = 2;
    public const JENIS_LAYANAN     = 3;

    public const JENIS = [self::JENIS_SIMAPI_LAMA => 'SIMAPI lama (Pusdatin)', self::JENIS_SIMAPI_BARU => 'SIMAPI baru (Simanta)', self::JENIS_LAYANAN => 'Layanan (MuDig)'];

    private function __construct()
    {
    }

    public static function jenisSah(int $jenis): bool
    {
        return isset(self::JENIS[$jenis]);
    }

    /**
     * Daftar hak dari masukan `a,b,c` → bentuk SET kolom (urutan SET, tanpa ganda).
     *
     * @return list<string>
     *
     * @throws InvalidArgumentException hak tidak dikenal atau kosong
     */
    public static function urai(string $mentah): array
    {
        $diminta = [];

        foreach (explode(',', $mentah) as $hak) {
            $hak = strtolower(trim($hak));

            if ($hak === '' || ! in_array($hak, self::SEMUA, true)) {
                throw new InvalidArgumentException('Hak tidak dikenal. Pilihan: ' . implode(', ', self::SEMUA) . '.');
            }

            $diminta[$hak] = true;
        }

        return array_values(array_filter(self::SEMUA, static fn (string $h): bool => isset($diminta[$h])));
    }

    /**
     * Pastikan hak cocok dengan jenis (audit B4).
     *
     * @param list<string> $hak
     *
     * @throws InvalidArgumentException jenis tidak sah atau tidak cocok dengan hak `simapi`
     */
    public static function periksa(int $jenis, array $hak): void
    {
        if (! self::jenisSah($jenis)) {
            throw new InvalidArgumentException('Jenis klien harus 1, 2, atau 3.');
        }

        $punyaSimapi = in_array(self::SIMAPI, $hak, true);

        if ($jenis !== self::JENIS_LAYANAN && ! $punyaSimapi) {
            throw new InvalidArgumentException('Klien jenis 1 atau 2 (SIMAPI) wajib punya hak simapi.');
        }

        if ($jenis === self::JENIS_LAYANAN && $punyaSimapi) {
            throw new InvalidArgumentException('Klien jenis 3 (layanan) tidak boleh punya hak simapi.');
        }
    }

    /**
     * Hak dari nilai SET kolom (`a,b`) → daftar.
     *
     * @return list<string>
     */
    public static function dariKolom(string $set): array
    {
        return $set === '' ? [] : explode(',', $set);
    }
}
