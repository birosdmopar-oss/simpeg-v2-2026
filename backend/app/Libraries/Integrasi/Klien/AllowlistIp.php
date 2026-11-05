<?php

declare(strict_types=1);

namespace App\Libraries\Integrasi\Klien;

use InvalidArgumentException;

/**
 * MAKE-058 (murni) — allowlist IP klien layanan (`klien_layanan.ip_allowlist`, DBV-026-Q2, PRD Fase 6 §7.0.1 AC-F.3).
 * Daftar IPv4/IPv6 atau CIDR dipisah koma. `null` = semua IP; string kosong TIDAK PERNAH berarti "semua IP":
 * {@see self::normalisasi()} menolaknya saat simpan dan {@see self::cocok()} menolak semua alamat bila isinya kosong
 * (gagal tertutup).
 */
final class AllowlistIp
{
    /** Panjang kolom `ip_allowlist`. */
    public const PANJANG_MAKS = 1000;

    private function __construct()
    {
    }

    /**
     * Bentuk simpan: tanpa spasi, tanpa entri ganda, huruf kecil (IPv6), urutan input dipertahankan. `null` = semua IP.
     *
     * @throws InvalidArgumentException isi kosong, entri bukan IP/CIDR sah, atau lebih panjang dari kolom
     */
    public static function normalisasi(?string $mentah): ?string
    {
        if ($mentah === null) {
            return null;
        }

        $entri = [];

        foreach (explode(',', $mentah) as $bagian) {
            $bagian = trim($bagian);

            if ($bagian === '') {
                throw new InvalidArgumentException('Allowlist IP berisi entri kosong; kosongkan seluruh opsi untuk "semua IP" (NULL).');
            }

            $entri[self::kanonik($bagian)] = true;
        }

        $hasil = implode(',', array_keys($entri));

        if (strlen($hasil) > self::PANJANG_MAKS) {
            throw new InvalidArgumentException('Allowlist IP melebihi ' . self::PANJANG_MAKS . ' karakter.');
        }

        return $hasil;
    }

    /**
     * Apakah `$ip` diizinkan oleh `$allowlist` (bentuk simpan). `null` = semua; kosong atau IP tak sah = ditolak.
     */
    public static function cocok(string $ip, ?string $allowlist): bool
    {
        if ($allowlist === null) {
            return true;
        }

        $biner = @inet_pton($ip);

        if ($biner === false) {
            return false;
        }

        foreach (explode(',', $allowlist) as $entri) {
            $entri = trim($entri);

            if ($entri === '') {
                continue;
            }

            [$alamat, $prefiks] = array_pad(explode('/', $entri, 2), 2, null);
            $biner2             = @inet_pton((string) $alamat);

            if ($biner2 === false || strlen($biner2) !== strlen($biner)) {
                continue;
            }

            $bit = $prefiks === null ? strlen($biner) * 8 : (int) $prefiks;

            if (self::samaPrefiks($biner, $biner2, $bit)) {
                return true;
            }
        }

        return false;
    }

    private static function kanonik(string $entri): string
    {
        [$alamat, $prefiks] = array_pad(explode('/', $entri, 2), 2, null);
        $biner              = @inet_pton($alamat);

        if ($biner === false) {
            throw new InvalidArgumentException('Entri allowlist bukan IPv4/IPv6 sah.');
        }

        $maks = strlen($biner) * 8;

        if ($prefiks === null) {
            return (string) inet_ntop($biner);
        }

        if ($prefiks === '' || ! ctype_digit($prefiks) || (int) $prefiks > $maks || (strlen($prefiks) > 1 && $prefiks[0] === '0')) {
            throw new InvalidArgumentException('Panjang prefiks CIDR tidak sah.');
        }

        return inet_ntop($biner) . '/' . (int) $prefiks;
    }

    private static function samaPrefiks(string $a, string $b, int $bit): bool
    {
        if ($bit < 0 || $bit > strlen($a) * 8) {
            return false;
        }

        $byte = intdiv($bit, 8);

        if ($byte > 0 && substr($a, 0, $byte) !== substr($b, 0, $byte)) {
            return false;
        }

        $sisa = $bit % 8;

        if ($sisa === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $sisa)) & 0xFF;

        return (ord($a[$byte]) & $mask) === (ord($b[$byte]) & $mask);
    }
}
