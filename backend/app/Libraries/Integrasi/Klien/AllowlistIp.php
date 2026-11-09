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
     * MAKE-076 (Q6-41, DBV-031 §3.1) — varian API SIMAPI lama `simapi/a_pegawai/*`: allowlist WAJIB berisi (NULL/kosong = ditolak,
     * setara kolom `simapi_auth.ip_address` legacy yang selalu dicocokkan) lalu IP harus cocok.
     */
    public static function cocokWajib(string $ip, ?string $allowlist): bool
    {
        return $allowlist !== null && trim($allowlist) !== '' && self::cocok($ip, $allowlist);
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

        [$biner] = self::petakan($biner, null);

        foreach (explode(',', $allowlist) as $entri) {
            $entri = trim($entri);

            if ($entri === '') {
                continue;
            }

            [$alamat, $prefiks] = array_pad(explode('/', $entri, 2), 2, null);
            $biner2             = @inet_pton((string) $alamat);

            // Prefiks harus angka murni dalam batas (gagal tertutup: `10.0.0.0/` atau `/x` tidak berarti "semua").
            if ($biner2 === false || ($prefiks !== null && ($prefiks === '' || ! ctype_digit($prefiks)))) {
                continue;
            }

            [$biner2, $bit] = self::petakan($biner2, $prefiks === null ? null : (int) $prefiks);

            if (strlen($biner2) !== strlen($biner) || $bit > strlen($biner2) * 8) {
                continue;
            }

            $bit ??= strlen($biner) * 8;

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
            return (string) inet_ntop(self::petakan($biner, null)[0]);
        }

        if ($prefiks === '' || ! ctype_digit($prefiks) || (int) $prefiks > $maks || (strlen($prefiks) > 1 && $prefiks[0] === '0')) {
            throw new InvalidArgumentException('Panjang prefiks CIDR tidak sah.');
        }

        [$biner, $bit] = self::petakan($biner, (int) $prefiks);

        return inet_ntop($biner) . '/' . $bit;
    }

    /**
     * IPv4-mapped IPv6 (`::ffff:a.b.c.d`) dinormalkan ke 4 byte IPv4 (prefiks dikurangi 96); mapped dengan prefiks < 96 tetap IPv6
     * (lebih luas dari satu IPv4, tidak diterjemahkan). Alamat lain tidak berubah.
     *
     * @return array{0: string, 1: int|null}
     */
    private static function petakan(string $biner, ?int $prefiks): array
    {
        if (strlen($biner) !== 16 || substr($biner, 0, 12) !== str_repeat("\x00", 10) . "\xff\xff") {
            return [$biner, $prefiks];
        }

        if ($prefiks !== null && $prefiks < 96) {
            return [$biner, $prefiks];
        }

        return [substr($biner, 12), $prefiks === null ? null : $prefiks - 96];
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
