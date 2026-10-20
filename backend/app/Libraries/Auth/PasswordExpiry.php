<?php

declare(strict_types=1);

namespace App\Libraries\Auth;

use App\Models\Auth\PenggunaModel;
use Config\Auth as AuthConfig;

/**
 * MAKE-028 [K] — umur password (`pengguna.expired_at`), satu-satunya titik penulis dan pembaca aturannya.
 *
 * Legacy: `core/MY_Controller.php:223-230` (juga `libraries/hr/L_user.php:207`) menganggap `expired_at` sebagai
 * kedaluwarsa PASSWORD: bila `now > expired_at` pengguna hanya diminta mengganti password (popup `fcp`), bukan ditolak.
 * `new DateTime(NULL)` dibuat sesudah `$dtNow` sehingga `$dtNow > $dtPassExp` false: NULL = tidak kedaluwarsa. Nilainya
 * diisi trigger DB (`simpeg01_struktur_lengkap_20261001.sql:8364-8382`): `pengguna_beIns` akun aktif baru → +1 tahun;
 * `pengguna_beUpd` password berubah dan status akhir aktif → +3 bulan. v2 memindahkan aturan itu ke sini (tanpa trigger,
 * interval di Config\Auth). Akun berstatus bukan aktif tidak disentuh (`expired_at` apa adanya).
 *
 * Penegakan di v2 memakai mekanisme MAKE-020: password kedaluwarsa → claim `must_change_password` (login dan refresh,
 * dihitung ulang tiap kali) dan `must_change_password` pada representasi akun. Login/refresh tetap berhasil; access
 * token yang sudah terbit berlaku sampai TTL habis.
 *
 * Bukan "password berubah": lazy rehash (PasswordVerifier) menukar hash algoritma, bukan password pemilik.
 */
final class PasswordExpiry
{
    private function __construct()
    {
    }

    /**
     * Kedaluwarsa bila `expired_at` terisi dan $now lewat darinya (strict, seperti legacy `$dtNow > $dtPassExp`).
     * NULL, string kosong, atau nilai yang tidak terbaca = tidak kedaluwarsa.
     */
    public static function isExpired(mixed $expiredAt, int $now): bool
    {
        if (! is_string($expiredAt) || trim($expiredAt) === '') {
            return false;
        }

        $at = strtotime($expiredAt);

        return $at !== false && $now > $at;
    }

    /**
     * Kolom untuk INSERT akun baru (trigger `pengguna_beIns`): status aktif → +12 bulan; selain itu tidak ada.
     *
     * @return array{expired_at?: string}
     */
    public static function forNewAccount(string $status, int $now, ?AuthConfig $config = null): array
    {
        return self::forStatus($status, $now, ($config ?? config(AuthConfig::class))->newAccountValidityMonths);
    }

    /**
     * Kolom untuk UPDATE yang mengubah password (trigger `pengguna_beUpd`): status AKHIR aktif → +3 bulan; selain itu
     * tidak ada. Dipakai semua jalur: ganti sendiri, reset email, admin mengatur password, reset koreksi NIP.
     *
     * @return array{expired_at?: string}
     */
    public static function forPasswordChange(string $status, int $now, ?AuthConfig $config = null): array
    {
        return self::forStatus($status, $now, ($config ?? config(AuthConfig::class))->passwordValidityMonths);
    }

    /**
     * Tambah bulan ala MySQL `INTERVAL n MONTH`: hari dijepit ke hari terakhir bulan tujuan (31 Jan + 3 bulan = 30 Apr),
     * jam tetap.
     */
    public static function addMonths(int $timestamp, int $months): int
    {
        $y     = (int) date('Y', $timestamp);
        $m     = (int) date('n', $timestamp) - 1 + $months;
        $year  = $y + intdiv($m, 12);
        $month = $m % 12 + 1;
        $day   = min((int) date('j', $timestamp), (int) date('t', mktime(0, 0, 0, $month, 1, $year)));

        return mktime((int) date('G', $timestamp), (int) date('i', $timestamp), (int) date('s', $timestamp), $month, $day, $year);
    }

    /**
     * @return array{expired_at?: string}
     */
    private static function forStatus(string $status, int $now, int $months): array
    {
        if ($status !== PenggunaModel::STATUS_ACTIVE) {
            return [];
        }

        return ['expired_at' => date('Y-m-d H:i:s', self::addMonths($now, $months))];
    }
}
